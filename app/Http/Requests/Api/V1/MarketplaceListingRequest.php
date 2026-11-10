<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MarketplaceListing;
use App\Support\CatalogRules;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class MarketplaceListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('marketplaceListing') ? Gate::allows('update', $this->route('marketplaceListing')) : Gate::allows('create', MarketplaceListing::class);
    }

    public function rules(): array
    {
        $record = $this->route('marketplaceListing');
        $accountId = $this->input('marketplace_account_id', $record?->marketplace_account_id);

        return CatalogRules::forPatch(CatalogRules::listing(OrganizationContext::id(), $record?->id, is_numeric($accountId) ? (int) $accountId : null), $this->isMethod('PATCH'));
    }
}
