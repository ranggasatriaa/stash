<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Interactive owner creation. The password is read with hidden input and
 * never echoed or logged. There is no public registration endpoint.
 */
class CreateOwner extends Command
{
    protected $signature = 'stash:create-owner
        {--name= : Display name}
        {--email= : Login e-mail}
        {--timezone= : Display timezone (e.g. Asia/Jakarta)}
        {--allow-additional : Allow creating a user when one already exists}
        {--password-from-env= : Read the password from this environment variable (non-interactive automation only)}';

    protected $description = 'Create the Stash owner account (hidden password prompt)';

    public function handle(): int
    {
        if (User::query()->exists() && ! $this->option('allow-additional')) {
            $this->error('An owner already exists. Use stash:reset-password to change the password, or pass --allow-additional.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: text('Name', required: true, validate: fn ($v) => mb_strlen(trim($v)) > 120 ? 'Use at most 120 characters.' : null);
        $email = strtolower(trim((string) ($this->option('email') ?: text('E-mail', required: true))));
        $timezone = $this->option('timezone') ?: config('stash.display_timezone', 'UTC');

        $envName = $this->option('password-from-env');
        if ($envName) {
            $plain = (string) getenv((string) $envName);
            if ($plain === '') {
                $this->error("Environment variable {$envName} is empty.");

                return self::FAILURE;
            }
        } else {
            $plain = password('Password', required: true, hint: 'Input is hidden.');
            $confirm = password('Confirm password', required: true);
            if (! hash_equals($plain, $confirm)) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plain, 'timezone' => $timezone],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::defaults()],
                'timezone' => ['required', 'timezone:all'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => trim($name), 'email' => $email, 'password' => $plain, 'timezone' => $timezone]);
        $user->email_verified_at = now();
        $user->save();

        $this->info("Owner {$email} created. Sign in at ".rtrim((string) config('app.url'), '/').'/login');

        return self::SUCCESS;
    }
}
