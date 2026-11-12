<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TourProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'tour_id' => $this->tour_id, 'version' => $this->version, 'status' => $this->status,
            'step_id' => $this->step_id, 'step_index' => $this->step_index,
            'last_started_at' => $this->last_started_at?->toISOString(), 'last_offered_at' => $this->last_offered_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(), 'next_eligible_at' => $this->next_eligible_at?->toISOString(),
        ];
    }
}
