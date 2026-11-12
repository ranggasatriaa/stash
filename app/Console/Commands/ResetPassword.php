<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

class ResetPassword extends Command
{
    protected $signature = 'stash:reset-password {email : The account e-mail} {--keep-sessions : Do not sign out other sessions}';

    protected $description = 'Set a new password for a user (hidden prompt); signs out other sessions';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();
        if (! $user) {
            $this->error('No user with that e-mail.');

            return self::FAILURE;
        }

        $plain = password('New password', required: true, hint: 'Input is hidden.');
        if (! hash_equals($plain, password('Confirm new password', required: true))) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(['password' => $plain], ['password' => ['required', Password::defaults()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user->password = $plain;
        $user->setRememberToken(null);
        $user->save();

        if (! $this->option('keep-sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $this->info('Password updated.');

        return self::SUCCESS;
    }
}
