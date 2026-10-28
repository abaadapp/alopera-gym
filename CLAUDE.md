# Alopera Gym — notes for coding agents

Multi-tenant gym SaaS: Laravel 13 / PHP 8.4 / PostgreSQL 16 / Inertia 3 / React 19 / TypeScript / Tailwind 4.
Read `docs/ARCHITECTURE.md` first.

## Rules that must not be broken
- Every club-owned model uses `App\Core\Tenancy\BelongsToTenant`. The scope fails closed; never query club data
  outside a tenant context except via `TenantContext::bypass()` in platform code.
- New tables with `tenant_id` must be added to `App\Core\Backup\TenantTables` (a test enforces it).
- Validation of ids from requests uses `TenantRule::exists()/unique()`, never plain `exists:`.
- Routes in the club panel are protected with `can:<permission>`; new permissions go in `PermissionRegistry`.
- Money is `decimal(12,3)`; do arithmetic with `App\Core\Support\Money`. Apply money to invoices only through `PaymentService`.
- Memberships are activated only by `ActivatePaidItems` on `InvoicePaid`. Never activate from a controller or a return URL.
- WhatsApp messages go through `WhatsAppService::queueTemplate()` (consent, approval, dedupe, after-commit queueing).
- Integration secrets live in `TenantIntegration::credentials` (encrypted) and are never sent to the browser.
- Gym tables are prefixed `gym_`; each module keeps its own migrations and routes under `app/Modules/<Module>`.

## Commands
- Tests: `php artisan test` (PostgreSQL database `alopera_gym_testing`)
- Types/build: `npx tsc --noEmit -p tsconfig.json` · `npm run build`
- Style: `vendor/bin/pint`
- Demo data: `php artisan migrate:fresh --seed` (refuses to run in production)
- Release gate: `php artisan platform:preflight` (run by `scripts/deploy.sh`; fails locally by design)
- Push + version tag: `scripts/release.sh` (`--dry-run` to preview); bumps from conventional-commit subjects, refuses env files and keys
