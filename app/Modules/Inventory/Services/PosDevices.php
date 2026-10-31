<?php

namespace App\Modules\Inventory\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Inventory\Models\PosDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Point-of-sale devices and how a browser becomes one.
 *
 * A manager adds a device for a branch and gets a one-time pairing code (8 characters, 30 minutes; only its hash is
 * kept). On the device, a member of staff who sells enters the code; the server then gives that browser a secret in
 * an encrypted, HTTP-only cookie and keeps only the secret's hash. A manager may also activate the browser they are
 * on directly. Each request finds the device from the cookie, checks the club, the status and the hash; nothing the
 * browser says about itself is trusted otherwise. Revoking replaces the hash (a lost device is cut off at once);
 * moving a device to another branch revokes it until it is paired again. Every step is in the audit log.
 */
final class PosDevices
{
    public const COOKIE = 'pos_device';

    private const CODE_MINUTES = 30;

    /** @var array<string, PosDevice|null> */
    private array $resolved = [];

    public function __construct(private readonly TenantContext $tenants, private readonly BranchContext $branches, private readonly AuditLogger $audit) {}

    /** @return array{0: PosDevice, 1: string} the device and its pairing code (shown once) */
    public function create(string $name, int $branchId, ?int $settlementAccountId = null): array
    {
        return DB::transaction(function () use ($name, $branchId, $settlementAccountId) {
            $device = new PosDevice(['name' => $name, 'branch_id' => $branchId, 'settlement_account_id' => $settlementAccountId, 'created_by' => Auth::guard('web')->id(), 'settings' => ['auto_print' => false]]);
            $device->forceFill(['status' => PosDevice::PENDING])->save();
            $code = $this->issueCode($device);
            $this->audit->log('pos_device.created', $device, ['branch' => $branchId]);

            return [$device, $code];
        });
    }

    /** A new pairing code for a device waiting for one, or revoked (to pair a replacement). */
    public function newCode(PosDevice $device): string
    {
        return DB::transaction(function () use ($device) {
            $device = PosDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ($device->status === PosDevice::ACTIVE) {
                throw new BusinessRuleException('pos_device.active', 'الجهاز مفعّل؛ ألغِ تفعيله أولًا ثم أصدر رمزًا جديدًا.');
            }
            $device->forceFill(['status' => PosDevice::PENDING, 'token_hash' => null])->save();
            $code = $this->issueCode($device);
            $this->audit->log('pos_device.code_issued', $device);

            return $code;
        });
    }

    /** On the device: a valid pairing code makes this browser that device. */
    public function pair(string $code, Request $request): PosDevice
    {
        $hash = hash('sha256', self::normalize($code));

        return DB::transaction(function () use ($hash, $request) {
            $device = PosDevice::where('pairing_hash', $hash)->lockForUpdate()->first();
            if (! $device || $device->status !== PosDevice::PENDING || ! $device->pairing_expires_at?->isFuture()) {
                $this->audit->log('pos_device.pair_failed', null, ['ip' => $request->ip()]);
                throw new BusinessRuleException('pos_device.code', 'رمز الربط غير صحيح أو انتهت صلاحيته. اطلب رمزًا جديدًا من إعدادات أجهزة نقاط البيع.', 'code');
            }
            $this->ensureBranch($device);

            return $this->activate($device, $request, 'pos_device.paired');
        });
    }

    /** A manager activates the browser they are on (reusing this browser's device of the same club, if any). */
    public function activateHere(string $name, int $branchId, Request $request, ?int $settlementAccountId = null): PosDevice
    {
        return DB::transaction(function () use ($name, $branchId, $request, $settlementAccountId) {
            $existing = $this->fromCookie($request, activeOnly: false);
            $device = $existing ? PosDevice::whereKey($existing->id)->lockForUpdate()->first() : null;
            $device ??= new PosDevice(['created_by' => Auth::guard('web')->id(), 'settings' => ['auto_print' => false]]);
            $device->fill(['name' => $name, 'branch_id' => $branchId, 'settlement_account_id' => $settlementAccountId ?? $device->settlement_account_id]);
            $device->forceFill(['status' => PosDevice::PENDING])->save();

            return $this->activate($device, $request, 'pos_device.activated');
        });
    }

    public function revoke(PosDevice $device, string $reason = ''): PosDevice
    {
        return DB::transaction(function () use ($device, $reason) {
            $device = PosDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $device->forceFill([
                'status' => PosDevice::REVOKED, 'token_hash' => null, 'pairing_hash' => null, 'pairing_expires_at' => null,
                'revoked_by' => Auth::guard('web')->id(), 'revoked_at' => now(), 'revoke_reason' => $reason ?: null,
            ])->save();
            $this->audit->log('pos_device.revoked', $device, ['reason' => $reason]);

            return $device;
        });
    }

    /** Rename, change the card terminal's bank; a new branch revokes the device until it is paired there again. */
    public function update(PosDevice $device, array $data): PosDevice
    {
        return DB::transaction(function () use ($device, $data) {
            $device = PosDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $moved = isset($data['branch_id']) && (int) $data['branch_id'] !== (int) $device->branch_id;
            $device->fill(array_intersect_key($data, array_flip(['name', 'branch_id', 'settlement_account_id'])));
            $device->settings = array_merge($device->settings ?? [], array_intersect_key($data, array_flip(['auto_print', 'print_website_orders'])));
            if ($moved) {
                $device->forceFill(['status' => PosDevice::REVOKED, 'token_hash' => null, 'pairing_hash' => null, 'revoked_by' => Auth::guard('web')->id(), 'revoked_at' => now(), 'revoke_reason' => 'نُقل إلى فرع آخر']);
            }
            $device->save();
            $this->audit->log($moved ? 'pos_device.moved' : 'pos_device.updated', $device, ['branch' => $device->branch_id]);

            return $device;
        });
    }

    /** The activated device this browser is, for the signed-in club (null: none, revoked, or another club's). */
    public function current(Request $request): ?PosDevice
    {
        $key = (string) $request->cookie(self::COOKIE);

        return $this->resolved[$key] ??= $this->fromCookie($request);
    }

    /** Seen now (at most once a minute, to keep writes down). */
    public function touch(PosDevice $device, Request $request): void
    {
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 250, '')])->saveQuietly();
        }
    }

    /** May this user sell on this device? A user tied to a branch only on a device of that branch. */
    public function ensureBranch(PosDevice $device, ?User $user = null): void
    {
        $user ??= Auth::guard('web')->user();
        if ($user instanceof User && ! $this->branches->allows($user, $device->branch_id)) {
            throw new BusinessRuleException('pos_device.branch', 'هذا الجهاز لفرع آخر غير فرعك.');
        }
    }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private function activate(PosDevice $device, Request $request, string $event): PosDevice
    {
        $token = Str::random(64);
        $device->forceFill([
            'status' => PosDevice::ACTIVE, 'token_hash' => hash('sha256', $token), 'pairing_hash' => null, 'pairing_expires_at' => null,
            'activated_by' => Auth::guard('web')->id(), 'activated_at' => now(), 'revoked_at' => null, 'revoked_by' => null, 'revoke_reason' => null,
            'last_seen_at' => now(), 'last_ip' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ])->save();
        // Encrypted by the app (EncryptCookies), unreadable by scripts, sent back only to this site.
        Cookie::queue(Cookie::make(self::COOKIE, $device->id.'|'.$token, 60 * 24 * 730, '/', null, $request->isSecure() || config('session.secure'), true, false, 'lax'));
        $this->resolved = [];
        $this->audit->log($event, $device, ['branch' => $device->branch_id]);

        return $device;
    }

    private function issueCode(PosDevice $device): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (PosDevice::withoutGlobalScopes()->where('pairing_hash', hash('sha256', $code))->exists());
        $device->forceFill(['pairing_hash' => hash('sha256', $code), 'pairing_expires_at' => now()->addMinutes(self::CODE_MINUTES)])->save();

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    private function fromCookie(Request $request, bool $activeOnly = true): ?PosDevice
    {
        $value = (string) $request->cookie(self::COOKIE);
        if (! $this->tenants->check() || ! preg_match('/^(\d+)\|([A-Za-z0-9]{64})$/', $value, $m)) {
            return null;
        }
        $device = PosDevice::find((int) $m[1]); // the club's own devices only (tenant scope)
        if (! $device || ! $device->token_hash || ! hash_equals($device->token_hash, hash('sha256', $m[2]))) {
            // A revoked device of this club may be taken up again on the browser that was it; an active one never.
            return ! $activeOnly && $device && ! $device->token_hash ? $device : null;
        }

        return ! $activeOnly || $device->status === PosDevice::ACTIVE ? $device : null;
    }
}
