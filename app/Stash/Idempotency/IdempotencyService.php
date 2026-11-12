<?php

namespace App\Stash\Idempotency;

use App\Stash\Exceptions\StashException;
use App\Stash\Models\IdempotencyRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Idempotency-Key handling for creates and costly job requests.
 *
 * Records are scoped to owner + credential (token id or "session"). The
 * request fingerprint covers method, path and a canonical body; multipart
 * bodies hash the sorted fields plus each file's SHA-256. A replay returns
 * the stored response; the same key with a different fingerprint is a 409.
 * Records expire after `stash.idempotency.ttl_hours` (default 24h).
 */
final class IdempotencyService
{
    public function fingerprint(Request $request): string
    {
        $fields = $request->except(array_keys($request->allFiles()));
        $fields = self::canonical($fields);

        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            foreach (is_array($file) ? $file : [$file] as $i => $one) {
                if ($one->isValid()) {
                    $files[$name.'.'.$i] = hash_file('sha256', (string) $one->getRealPath());
                }
            }
        }
        ksort($files);

        return hash('sha256', implode("\n", [
            strtoupper($request->method()),
            '/'.ltrim($request->path(), '/'),
            json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($files),
        ]));
    }

    /**
     * Claim a key. Returns the existing record when this is a replay (or
     * in-flight duplicate), or null when the caller should proceed.
     */
    public function claim(int $userId, string $scope, string $key, string $hash, Request $request): ?IdempotencyRecord
    {
        $existing = $this->find($userId, $scope, $key);
        if ($existing) {
            return $this->check($existing, $hash);
        }

        try {
            IdempotencyRecord::query()->create([
                'user_id' => $userId,
                'scope' => $scope,
                'key' => $key,
                'request_hash' => $hash,
                'method' => strtoupper($request->method()),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                'expires_at' => now()->addHours((int) config('stash.idempotency.ttl_hours', 24)),
            ]);
        } catch (QueryException $e) {
            // Lost a race with a concurrent request using the same key.
            $existing = $this->find($userId, $scope, $key);
            if ($existing) {
                return $this->check($existing, $hash);
            }
            throw $e;
        }

        return null;
    }

    /** @param array<string, mixed>|null $body */
    public function complete(int $userId, string $scope, string $key, int $status, ?array $body, ?string $resourceRef = null): void
    {
        IdempotencyRecord::query()->where('user_id', $userId)->where('scope', $scope)->where('key', $key)
            ->update([
                'response_status' => $status,
                'response_body' => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'resource_ref' => $resourceRef,
                'updated_at' => now(),
            ]);
    }

    /** Release a claim after a failed (non-2xx) attempt so the client may retry. */
    public function release(int $userId, string $scope, string $key): void
    {
        IdempotencyRecord::query()->where('user_id', $userId)->where('scope', $scope)->where('key', $key)
            ->whereNull('response_status')->delete();
    }

    public function prune(): int
    {
        return IdempotencyRecord::query()->where('expires_at', '<', now())->delete();
    }

    private function find(int $userId, string $scope, string $key): ?IdempotencyRecord
    {
        return IdempotencyRecord::query()->where('user_id', $userId)->where('scope', $scope)->where('key', $key)
            ->where('expires_at', '>', now())->first();
    }

    private function check(IdempotencyRecord $record, string $hash): IdempotencyRecord
    {
        if (! hash_equals($record->request_hash, $hash)) {
            throw new StashException('idempotency_key_reused', 'This Idempotency-Key was already used with a different request body.', 409);
        }
        if ($record->response_status === null) {
            throw new StashException('idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.', 409);
        }

        return $record;
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map([self::class, 'canonical'], $value);
            }
            ksort($value);

            return array_map([self::class, 'canonical'], $value);
        }

        return $value;
    }
}
