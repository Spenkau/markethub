<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockMovement;
use App\Support\OrganizationContext;
use App\Support\StockOperationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StockOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', StockMovement::class);
    }

    public function rules(): array
    {
        return StockOperationRules::rules(OrganizationContext::id());
    }
}
