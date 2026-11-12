<?php

use Illuminate\Support\Facades\Schedule;

// Housekeeping: expired exports/imports and idempotency records. Never purges Trash.
Schedule::command('stash:prune')->hourly()->withoutOverlapping()->onOneServer();

// Keep failed-job records bounded (inspect with `php artisan queue:failed`).
Schedule::command('queue:prune-failed --hours=720')->daily();

// Expired personal access tokens.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
