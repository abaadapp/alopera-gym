<?php

namespace App\Modules\Gym\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSession extends Model
{
    use BelongsToTenant;

    public const SCHEDULED = 'scheduled';

    public const CANCELLED = 'cancelled';

    public const COMPLETED = 'completed';

    public const STATUS_LABELS = [self::SCHEDULED => 'مجدولة', self::CANCELLED => 'ملغاة', self::COMPLETED => 'منتهية'];

    protected $table = 'gym_class_sessions';

    protected $guarded = ['id', 'tenant_id', 'booked_count'];

    protected $attributes = ['status' => 'scheduled', 'booked_count' => 0];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class)->withTrashed();
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ClassSchedule::class, 'class_schedule_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function liveBookings(): HasMany
    {
        return $this->hasMany(Booking::class)->where('status', '!=', Booking::CANCELLED);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)->where('starts_at', '>', now());
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->where('starts_at', '>=', $from)->where('starts_at', '<', $to);
    }

    public function seatsLeft(): int
    {
        return max(0, $this->capacity - $this->booked_count);
    }

    public function isFull(): bool
    {
        return $this->booked_count >= $this->capacity;
    }
}
