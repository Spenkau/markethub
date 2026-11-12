<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTourProgress extends Model
{
    protected $table = 'user_tour_progress';

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['version' => 'integer', 'step_index' => 'integer', 'last_started_at' => 'immutable_datetime', 'last_offered_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'next_eligible_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
