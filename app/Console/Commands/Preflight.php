<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\DemoAccounts;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Release gate: checks the settings a live system needs and that no demo
 * account can sign in. scripts/deploy.sh runs it before switching releases;
 * any FAIL stops the deploy.
 */
class Preflight extends Command
{
    protected $signature = 'platform:preflight {--deactivate-demo : Deactivate staff accounts on demo/reserved email domains (production only, nothing is deleted)}';

    protected $description = 'Check production settings and demo accounts before going live';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(TenantContext $tenants, Migrator $migrator, AuditLogger $audit): int
    {
        $this->rows = [];
        $production = app()->isProduction();
        $this->check($production, 'APP_ENV is production', $production ? 'production' : app()->environment().' — checks below are judged as for a live server');

        $this->check(! config('app.debug'), 'APP_DEBUG is off', hint: 'debug pages would expose code and settings');
        $this->check(filled(config('app.key')), 'APP_KEY is set', hint: 'integration secrets and backups are encrypted with it');
        $this->check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL uses HTTPS', (string) config('app.url'));
        $this->check((bool) config('session.secure'), 'Session cookie is HTTPS-only', hint: 'SESSION_SECURE_COOKIE=true');
        $this->check((bool) config('session.encrypt'), 'Session data is encrypted', hint: 'SESSION_ENCRYPT=true');
        $this->check(config('queue.default') !== 'sync', 'Queue runs in the background', 'QUEUE_CONNECTION='.config('queue.default'));
        $this->check(! in_array(config('mail.default'), ['log', 'array'], true), 'Mail is delivered', 'MAIL_MAILER='.config('mail.default').' (password resets)', warn: true);
        $this->check($this->logLevel() !== 'debug', 'Log level is not debug', 'LOG_LEVEL='.$this->logLevel(), warn: true);
        $this->check(config('services.whatsapp.driver') !== 'log', 'WhatsApp sends for real', warn: true, hint: 'WHATSAPP_DRIVER=meta once Meta is set up');
        $this->check(is_link(public_path('storage')) || is_dir(public_path('storage')), 'Public storage is linked', hint: 'php artisan storage:link');

        try {
            DB::connection()->getPdo();
            $pending = array_diff(array_keys($migrator->getMigrationFiles($this->migrationPaths($migrator))), $migrator->getRepository()->getRan());
            $this->check($pending === [], 'Database is migrated', $pending === [] ? 'up to date' : count($pending).' pending');
        } catch (Throwable $e) {
            $this->check(false, 'Database is reachable', $e->getMessage());

            return $this->report();
        }

        $demoStaff = User::query()->get(['id', 'email', 'is_active'])
            ->filter(fn (User $u) => DemoAccounts::isReservedEmail($u->email));
        $activeDemo = $demoStaff->where('is_active', true);

        if ($this->option('deactivate-demo') && $activeDemo->isNotEmpty()) {
            if (! $production) {
                $this->error('--deactivate-demo only runs in production; locally the demo data is expected.');

                return self::FAILURE;
            }
            User::whereKey($activeDemo->pluck('id'))->update(['is_active' => false]);
            $audit->log('platform.demo_deactivated', null, ['emails' => $activeDemo->pluck('email')->values()->all()]);
            $this->info('Deactivated '.$activeDemo->count().' demo account(s).');
            $activeDemo = collect();
        }

        $this->check($activeDemo->isEmpty(), 'No active demo staff accounts', $activeDemo->isEmpty()
            ? 'none'.($demoStaff->isNotEmpty() ? ' ('.$demoStaff->count().' deactivated)' : '')
            : $activeDemo->count().': '.$activeDemo->pluck('email')->take(3)->implode(', ').($activeDemo->count() > 3 ? ' …' : ''), hint: '— run with --deactivate-demo');

        $demoMembers = $tenants->bypass(fn () => Member::whereNotNull('email')->pluck('email')->filter(fn ($e) => DemoAccounts::isReservedEmail($e))->count());
        $this->check($demoMembers === 0, 'No members on demo email domains', $demoMembers.' member(s)', warn: true, hint: '— their portal sign-in is refused in production');

        $demoClubs = Tenant::whereIn('slug', ['alopera', 'demofit'])->get()->filter(fn (Tenant $t) => DemoAccounts::isReservedEmail($t->email))->pluck('slug');
        $this->check($demoClubs->isEmpty(), 'No demo clubs', $demoClubs->isEmpty() ? 'none' : $demoClubs->implode(', ').' look like seeded demo data', warn: true);

        $owners = User::whereNotNull('tenant_id')->where('is_active', true)->whereHas('role', fn ($q) => $q->where('key', 'owner'))->get(['email'])->reject(fn (User $u) => DemoAccounts::isReservedEmail($u->email));
        $this->check($owners->isNotEmpty(), 'The gym is set up with a real owner account', $owners->count().' owner(s)', warn: true, hint: '— php artisan gym:install');

        return $this->report();
    }

    /** $detail is always shown; $hint (what to do) only when the check does not pass. */
    private function check(bool $ok, string $label, string $detail = '', bool $warn = false, string $hint = ''): void
    {
        $this->rows[] = [$ok ? '<info>PASS</info>' : ($warn ? '<comment>WARN</comment>' : '<error>FAIL</error>'), $label, $ok ? $detail : trim($detail.' '.$hint)];
    }

    private function report(): int
    {
        $this->table(['', 'Check', 'Detail'], $this->rows);
        $failed = collect($this->rows)->filter(fn ($r) => str_contains($r[0], 'FAIL'))->count();
        $failed ? $this->error("{$failed} check(s) failed — fix them before going live.") : $this->info('Ready: no blocking issues.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function logLevel(): string
    {
        $channel = config('logging.default');
        if (config("logging.channels.{$channel}.driver") === 'stack') {
            $channel = config("logging.channels.{$channel}.channels.0", $channel);
        }

        return (string) config("logging.channels.{$channel}.level", 'debug');
    }

    /** @return list<string> */
    private function migrationPaths(Migrator $migrator): array
    {
        return array_merge([database_path('migrations')], $migrator->paths());
    }
}
