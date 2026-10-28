<?php

namespace App\Modules\Gym\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainerAvailability extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_trainer_availabilities';

    protected $guarded = ['id', 'tenant_id'];

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
