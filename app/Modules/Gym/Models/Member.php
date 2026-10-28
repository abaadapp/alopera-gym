<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use App\Modules\Finance\Contracts\Billable;
use App\Modules\Finance\Models\Invoice;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A club member (مشتركة). Also the authenticatable model of the `member`
 * guard used by the member portal.
 */
#[UseFactory(MemberFactory::class)]
class Member extends Authenticatable implements Billable
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const BLOCKED = 'blocked';

    /** Arabic labels for exports (the UI has its own copy in resources/js/lib/labels.ts). */
    public const STATUS_LABELS = [self::ACTIVE => 'نشطة', self::INACTIVE => 'غير نشطة', self::BLOCKED => 'موقوفة'];

    protected $table = 'gym_members';

    protected $guarded = ['id', 'tenant_id', 'qr_token', 'password'];

    protected $attributes = ['status' => 'active', 'gender' => 'female', 'whatsapp_opt_in' => true, 'marketing_opt_in' => false];

    protected $hidden = ['password', 'remember_token', 'qr_token'];

    /** @var list<string> */
    protected array $auditExclude = ['health_notes', 'last_login_at', 'last_check_in_at'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joined_on' => 'date',
            'last_check_in_at' => 'datetime',
            'last_login_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'marketing_opt_out_at' => 'datetime',
            'whatsapp_opt_in' => 'boolean',
            'marketing_opt_in' => 'boolean',
            'password' => 'hashed',
            'health_notes' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Member $member): void {
            $member->qr_token ??= Str::random(40);
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** The membership that currently grants access, if any. */
    public function currentMembership(): HasOne
    {
        return $this->hasOne(Membership::class)
            ->whereIn('status', [Membership::ACTIVE, Membership::FROZEN])
            ->latestOfMany('ends_on');
    }

    /** A sold membership not running yet (awaiting payment or a future start) — for display in lists. */
    public function upcomingMembership(): HasOne
    {
        return $this->hasOne(Membership::class)
            ->whereIn('status', [Membership::PENDING, Membership::SCHEDULED])
            ->latestOfMany();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class);
    }

    public function notesLog(): HasMany
    {
        return $this->hasMany(MemberNote::class);
    }

    public function ptSubscriptions(): HasMany
    {
        return $this->hasMany(PtSubscription::class);
    }

    public function invoices(): MorphMany
    {
        return $this->morphMany(Invoice::class, 'billable');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $digits = preg_replace('/\D/', '', $term);

        return $query->where(function (Builder $q) use ($like, $digits, $term) {
            $q->where('full_name', 'ilike', $like)
                ->orWhere('member_no', 'ilike', $like)
                ->orWhere('email', 'ilike', $like);
            if ($digits !== '' && strlen($digits) >= 3) {
                $q->orWhere('phone', 'like', '%'.$digits.'%');
            }
            if (ctype_digit($term)) {
                $q->orWhere('national_id', $term);
            }
        });
    }

    public function rotateQrToken(): void
    {
        $this->forceFill(['qr_token' => Str::random(40)])->save();
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function canReceiveMarketing(): bool
    {
        return $this->marketing_opt_in && $this->marketing_opt_out_at === null && $this->status !== self::BLOCKED;
    }

    public function billingName(): string
    {
        return $this->full_name;
    }

    public function billingPhone(): ?string
    {
        return $this->phone;
    }

    public function billingEmail(): ?string
    {
        return $this->email;
    }
}
