<?php

namespace App\Providers;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\PermissionRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Sale;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reset for every request and every queued job.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AuditLogger::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // migrate:fresh / migrate:refresh / migrate:reset / db:wipe refuse to run in production.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Short, stable aliases in *_type columns — no PHP class names in the database.
        Relation::enforceMorphMap([
            'tenant' => Tenant::class,
            'user' => User::class,
            'member' => Member::class,
            'membership' => Membership::class,
            'pt_subscription' => PtSubscription::class,
            'pt_session' => PtSession::class,
            'booking' => Booking::class,
            'class_session' => ClassSession::class,
            'invoice' => Invoice::class,
            'payment' => Payment::class,
            'credit_note' => CreditNote::class,
            'product' => Product::class,
            'sale' => Sale::class,
            'purchase_order' => PurchaseOrder::class,
        ]);

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->mixedCase()->numbers()->uncompromised()
            : Password::min(8));

        $this->registerGates();
        $this->registerRateLimiters();

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
                app(AuditLogger::class)->log('auth.login', $event->user, tenantId: $event->user->tenant_id);
            }
        });
    }

    private function registerGates(): void
    {
        // Club permissions come from the user's role; nothing else can grant them.
        Gate::before(function ($user, string $ability) {
            if (! $user instanceof User) {
                return null;
            }

            if ($ability === 'platform') {
                return $user->isPlatformAdmin();
            }

            if (PermissionRegistry::exists($ability)) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('public-forms', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        RateLimiter::for('portal', fn (Request $request) => Limit::perMinute(60)->by(
            optional($request->user('member'))->getAuthIdentifier() ?: $request->ip()
        ));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        RateLimiter::for('devices', fn (Request $request) => Limit::perMinute(
            (int) config('gym.devices_rate_limit', 120)
        )->by(substr(hash('sha256', (string) $request->bearerToken()), 0, 16) ?: $request->ip()));

        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by((string) optional($request->user())->id));
    }
}
