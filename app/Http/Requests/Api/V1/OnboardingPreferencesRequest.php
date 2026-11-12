<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class OnboardingPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['auto_start' => ['sometimes', 'boolean'], 'checklist_collapsed' => ['sometimes', 'boolean'], 'user_id' => ['prohibited'], 'organization_id' => ['prohibited']];
    }
}
