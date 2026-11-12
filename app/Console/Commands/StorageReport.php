<?php

namespace App\Console\Commands;

use App\Http\Controllers\Web\DataTransferController;
use App\Models\User;
use App\Stash\Images\ImageInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StorageReport extends Command
{
    protected $signature = 'stash:storage';

    protected $description = 'Show storage usage per user and queue health (failed jobs)';

    public function handle(): int
    {
        $rows = [];
        foreach (User::query()->orderBy('id')->get() as $user) {
            $usage = DataTransferController::storageUsage($user);
            $rows[] = [
                $user->email,
                $usage['resources'],
                ImageInspector::humanBytes((int) ($usage['by_role']['original']['bytes'] ?? 0)),
                ImageInspector::humanBytes((int) (($usage['by_role']['thumbnail']['bytes'] ?? 0) + ($usage['by_role']['preview']['bytes'] ?? 0))),
                ImageInspector::humanBytes($usage['exports_bytes'] + $usage['imports_bytes']),
                ImageInspector::humanBytes($usage['total_bytes']),
            ];
        }
        $this->table(['Owner', 'Resources', 'Originals', 'Variants', 'Exports/imports', 'Total'], $rows);

        $failed = DB::table('failed_jobs')->count();
        $this->line("Failed jobs: {$failed}".($failed ? ' (inspect: php artisan queue:failed, retry: php artisan queue:retry all)' : ''));

        return self::SUCCESS;
    }
}
