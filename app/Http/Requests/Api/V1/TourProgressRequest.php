<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TourProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1', 'max:1000000'],
            'action' => ['required', Rule::in(['offer', 'start', 'advance', 'complete', 'skip', 'snooze'])],
            'step_id' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'step_index' => ['required', 'integer', 'min:0', 'max:199'],
            'automatic' => ['sometimes', 'boolean'],
            'snooze_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'user_id' => ['prohibited'], 'organization_id' => ['prohibited'], 'status' => ['prohibited'],
            'last_started_at' => ['prohibited'], 'completed_at' => ['prohibited'], 'next_eligible_at' => ['prohibited'],
        ];
    }
}
