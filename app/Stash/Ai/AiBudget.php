<?php

namespace App\Stash\Ai;

use App\Models\User;
use App\Stash\Exceptions\StashException;
use Illuminate\Support\Facades\DB;

/**
 * Per-user monthly token budget. Reservations are made while holding a lock
 * on the user row, so concurrent requests cannot jointly exceed the limit.
 * Active runs count their reservation; finished runs count actual usage
 * (or the retained estimate when the provider did not report usage).
 */
final class AiBudget
{
    public function limit(): int
    {
        return max(0, (int) config('stash.ai.monthly_token_limit', 0));
    }

    public function used(User $user): int
    {
        $start = now()->startOfMonth();

        return (int) DB::table('ai_runs')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $start)
            ->selectRaw("coalesce(sum(CASE WHEN status IN ('queued','running') THEN reserved_tokens ELSE coalesce(total_tokens, 0) END), 0) AS used")
            ->value('used');
    }

    /** @return array{limit: int, used: int, remaining: int, period_start: string} */
    public function summary(User $user): array
    {
        $used = $this->used($user);

        return [
            'limit' => $this->limit(),
            'used' => $used,
            'remaining' => max(0, $this->limit() - $used),
            'period_start' => now()->startOfMonth()->toIso8601ZuluString(),
        ];
    }

    /**
     * Run $create inside a transaction that holds the user's budget lock and
     * verifies that $tokens still fit.
     *
     * @template T
     *
     * @param  callable(): T  $create
     * @return T
     */
    public function reserve(User $user, int $tokens, callable $create): mixed
    {
        return DB::transaction(function () use ($user, $tokens, $create) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first(['id']);
            $used = $this->used($user);
            if ($used + $tokens > $this->limit()) {
                throw new StashException('ai_budget_exceeded', sprintf(
                    'Monthly AI token limit reached (%s of %s tokens used; this request needs about %s).',
                    number_format($used), number_format($this->limit()), number_format($tokens),
                ), 429, ['used' => $used, 'limit' => $this->limit(), 'requested' => $tokens]);
            }

            return $create();
        });
    }
}
