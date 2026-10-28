<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\DemoAccounts;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Club administration from the server, in place of the platform owner's screens (switched off, see
 * config gym.modules.platform_panel): only someone with shell access to the server can run it.
 *
 *   php artisan clubs list
 *   php artisan clubs create --name="Nadi Fit" --owner-name="Sara" --owner-email=sara@nadi.om [--slug=] [--phone=] [--city=]
 *   php artisan clubs suspend nadi-fit
 *   php artisan clubs activate nadi-fit
 */
class ManageClubs extends Command
{
    protected $signature = 'clubs {action : list | create | suspend | activate} {slug? : the club (for suspend / activate)}
        {--name= : club name (create)} {--slug= : web address part (create)} {--phone=} {--city=}
        {--owner-name= : owner full name (create)} {--owner-email= : owner sign-in email (create)} {--owner-password= : otherwise one is generated}';

    protected $description = 'List, create, suspend or reactivate clubs (the platform owner\'s screens are switched off)';

    public function handle(TenantProvisioner $provisioner, AuditLogger $audit): int
    {
        return match ($this->argument('action')) {
            'list' => $this->list(),
            'create' => $this->create($provisioner),
            'suspend' => $this->setStatus(Tenant::STATUS_SUSPENDED, $audit),
            'activate' => $this->setStatus(Tenant::STATUS_ACTIVE, $audit),
            default => $this->failWith('Unknown action. Use: list, create, suspend, activate.'),
        };
    }

    private function list(): int
    {
        $owners = User::query()->whereNotNull('tenant_id')->whereHas('role', fn ($q) => $q->where('key', 'owner'))->pluck('email', 'tenant_id');

        $this->table(['#', 'slug', 'name', 'status', 'trial ends', 'owner', 'created'], Tenant::query()->orderBy('id')->get()->map(fn (Tenant $t) => [
            $t->id, $t->slug, $t->name, $t->status, $t->trial_ends_at?->toDateString() ?? '—', $owners[$t->id] ?? '—', $t->created_at?->toDateString(),
        ]));

        return self::SUCCESS;
    }

    private function create(TenantProvisioner $provisioner): int
    {
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
            return $this->failWith($check->errors()->first());
        }
        if (DemoAccounts::blocked($data['owner_email'])) {
            return $this->failWith('Demo/reserved email domains (.test, example.com…) are not allowed in production.');
        }

        $password = $data['password'] ?: Str::password(16);
        $tenant = $provisioner->provision(
            ['name' => $data['name'], 'slug' => $this->option('slug'), 'phone' => $this->option('phone'), 'city' => $this->option('city')],
            ['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $password],
        );

        $this->info("Club ready: {$tenant->name} ({$tenant->slug}) — status {$tenant->status}");
        $this->line('Sign-in: '.route('login')." as {$data['owner_email']}");
        if (! $data['password']) {
            $this->warn("Generated owner password (shown once): {$password}");
        }

        return self::SUCCESS;
    }

    private function setStatus(string $status, AuditLogger $audit): int
    {
        $tenant = Tenant::where('slug', (string) $this->argument('slug'))->first();
        if (! $tenant) {
            return $this->failWith('No club with that slug. See: php artisan clubs list');
        }

        $old = $tenant->status;
        $tenant->forceFill(['status' => $status])->save();
        $audit->log('platform.tenant_status_changed', $tenant, ['via' => 'console'], ['status' => $old], ['status' => $status], $tenant->id);
        $this->info("{$tenant->slug}: {$old} → {$status}");

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
