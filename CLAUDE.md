# Alopera Gym — notes for coding agents

Management system for one gym (Alopera Gym) — owner panel, staff by permission, point of sale; no hosting-platform
screens. Club tables stay tenant-scoped internally. Laravel 13 / PHP 8.4 / PostgreSQL 16 / Inertia 3 / React 19 / TypeScript / Tailwind 4.
Read `docs/ARCHITECTURE.md` first.

## Rules that must not be broken
- Every club-owned model uses `App\Core\Tenancy\BelongsToTenant`. The scope fails closed; never query club data
  outside a tenant context except via `TenantContext::bypass()` in console, webhook and device code.
- New tables with `tenant_id` must be added to `App\Core\Backup\TenantTables` (a test enforces it).
- Validation of ids from requests uses `TenantRule::exists()/unique()`, never plain `exists:`.
- Routes in the club panel are protected with `can:<permission>`; new permissions go in `PermissionRegistry`.
- Money is `decimal(12,3)`; do arithmetic with `App\Core\Support\Money`. Apply money to invoices only through `PaymentService`.
- Memberships are activated only by `ActivatePaidItems` on `InvoicePaid`. Never activate from a controller or a return URL.
- WhatsApp messages go through `WhatsAppService::queueTemplate()` (consent, approval, dedupe, after-commit queueing).
- Integration secrets live in `TenantIntegration::credentials` (encrypted) and are never sent to the browser.
- Gym tables are prefixed `gym_`; each module keeps its own migrations and routes under `app/Modules/<Module>`.
- The logo is `App\Core\Branding\OfficialLogo`: the owner's file in `public/brand`, unchanged, until the owner uploads another in the club settings (`settings.logo_file`). The old `logo_path` column is never read. Never redraw or stretch a logo.

## Commands
- Tests: `php artisan test` (PostgreSQL database `alopera_gym_testing`)
- Types/build: `npx tsc --noEmit -p tsconfig.json` · `npm run build` · JS tests: `npm test` (vitest, `tests/js`)
- Printing apps: `mobile/` (Android, iOS); the iOS printing package tests on macOS with `swift test` (see `mobile/README.md`)
- Style: `vendor/bin/pint`
- Demo data: `php artisan migrate:fresh --seed` (refuses to run in production)
- Release gate: `php artisan platform:preflight` (run by `scripts/deploy.sh`; fails locally by design)
- Push + version tag: `scripts/release.sh` (`--dry-run` to preview); bumps from conventional-commit subjects, refuses env files and keys
- Deploy (docs/DEPLOY.md): CI runs on pull requests and ends with a proof of the tested tree; on main, Main Gate checks main's tree is that tree; Deploy Main then runs `scripts/deploy.sh` on the droplet over SSH and tags what it deployed (v0.N). Merging to main IS deploying to production — only with the owner's explicit approval. Going back is by hand: `scripts/rollback-code.sh <commit>`
