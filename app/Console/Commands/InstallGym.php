<?php

namespace App\Console\Commands;

use App\Core\Auth\DemoAccounts;
use App\Core\Tenancy\TenantProvisioner;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Sets the gym up on a new server, once: its record, main branch, roles and the owner's account. Run by whoever has
 * shell access; it refuses when a gym already exists (the system runs one gym).
 *
 *   php artisan gym:install --name="Alopera Gym" --owner-name="..." --owner-email=owner@alopera.om [--slug=] [--phone=] [--city=]
 */
class InstallGym extends Command
{
    protected $signature = 'gym:install {--name= : the gym\'s name} {--slug= : web address part} {--phone=} {--city=}
        {--owner-name= : the owner\'s full name} {--owner-email= : the owner\'s sign-in email} {--owner-password= : otherwise one is generated}';

    protected $description = 'Set up the gym and its owner account on a new installation (refuses if a gym exists)';

    public function handle(TenantProvisioner $provisioner): int
    {
        if (Tenant::withTrashed()->exists()) {
            $this->error('A gym is already set up on this installation; nothing was changed.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name'),
            'owner_name' => $this->option('owner-name'),
            'owner_email' => strtolower((string) $this->option('owner-email')),
            'password' => $this->option('owner-password'),
        ];
        $check = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'unique:users,email'],
            'password' => ['nullable', Password::defaults()],
        ]);
        if ($check->fails()) {
            $this->error($check->errors()->first());

            return self::FAILURE;
        }
        if (DemoAccounts::blocked($data['owner_email'])) {
            $this->error('Demo/reserved email domains (.test, example.com…) are not allowed in production.');

            return self::FAILURE;
        }

        $password = $data['password'] ?: Str::password(16);
        $gym = $provisioner->provision(
            ['name' => $data['name'], 'slug' => $this->option('slug'), 'phone' => $this->option('phone'), 'city' => $this->option('city')],
            ['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $password],
        );

        $this->info("Gym ready: {$gym->name} ({$gym->slug})");
        $this->line('Sign-in: '.route('login')." as {$data['owner_email']}");
        if (! $data['password']) {
            $this->warn("Generated owner password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
