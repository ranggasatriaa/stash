<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoLibrarySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Explicit development fixtures. Refuses to run in production unless
 * --force-production is given. Production databases start empty.
 */
class DemoSeed extends Command
{
    protected $signature = 'stash:demo-seed
        {--email=demo@stash.test : Demo owner e-mail (created if missing)}
        {--password-from-env= : Read the demo password from this environment variable}
        {--force-production : Allow running in production (not recommended)}';

    protected $description = 'Create demo resources for local development (never in production by default)';

    public function handle(DemoLibrarySeeder $seeder): int
    {
        if (app()->isProduction() && ! $this->option('force-production')) {
            $this->error('Demo data is disabled in production. Use --force-production only on a disposable instance.');

            return self::FAILURE;
        }

        $email = strtolower((string) $this->option('email'));
        $user = User::query()->where('email', $email)->first();
        $generated = null;

        if (! $user) {
            $env = $this->option('password-from-env');
            $password = $env ? (string) getenv((string) $env) : ($generated = Str::password(16, symbols: false));
            if ($password === '') {
                $this->error("Environment variable {$env} is empty.");

                return self::FAILURE;
            }
            $user = new User(['name' => 'Demo Owner', 'email' => $email, 'password' => $password, 'timezone' => config('stash.display_timezone', 'UTC')]);
            $user->email_verified_at = now();
            $user->save();
        } elseif ($user->resources()->withTrashed()->exists()) {
            $this->warn('This user already has resources; adding demo items anyway.');
        }

        $count = $seeder->run($user);
        $this->info("Created {$count} demo resources for {$email}.");
        if ($generated) {
            $this->warn("Generated demo password (shown once, development only): {$generated}");
        }

        return self::SUCCESS;
    }
}
