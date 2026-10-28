<?php

namespace App\Console\Commands;

use App\Core\Auth\DemoAccounts;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email?} {--name=Platform Admin} {--password=}';

    protected $description = 'Create (or reset) a super admin account for the platform owner';

    public function handle(): int
    {
        $email = strtolower((string) ($this->argument('email') ?: config('gym.platform_admin_email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide a valid email (argument or PLATFORM_ADMIN_EMAIL).');

            return self::FAILURE;
        }

        if (DemoAccounts::blocked($email)) {
            $this->error('Demo/reserved email domains (.test, example.com…) are not allowed in production.');

            return self::FAILURE;
        }

        // A password given on the command line gets the same policy as the UI (strict in production).
        if ($this->option('password')) {
            $check = Validator::make(['password' => $this->option('password')], ['password' => [Password::defaults()]]);
            if ($check->fails()) {
                $this->error($check->errors()->first('password'));

                return self::FAILURE;
            }
        }

        $password = $this->option('password') ?: Str::password(16);
        $user = User::firstOrNew(['email' => $email]);
        if ($user->exists && $user->tenant_id !== null) {
            $this->error('This email belongs to a club account.');

            return self::FAILURE;
        }

        $user->fill(['name' => $this->option('name'), 'password' => $password]);
        $user->forceFill(['is_platform_admin' => true, 'tenant_id' => null, 'email_verified_at' => now()])->save();

        $this->info("Platform admin ready: {$email}");
        if (! $this->option('password')) {
            $this->warn("Generated password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
