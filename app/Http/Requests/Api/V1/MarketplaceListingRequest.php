<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplaces\ProviderRegistry;
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
        $account = MarketplaceAccount::find($this->input('marketplace_account_id', $record?->marketplace_account_id));
        abort_if($record?->origin === 'imported' || ($account && app(ProviderRegistry::class)->get($account->marketplace)->definition()['connection_type'] !== 'demo'), 422, 'Данные Shopify доступны только для чтения. Используйте сопоставление.');
        $accountId = $this->input('marketplace_account_id', $record?->marketplace_account_id);

        return CatalogRules::forPatch(CatalogRules::listing(OrganizationContext::id(), $record?->id, is_numeric($accountId) ? (int) $accountId : null), $this->isMethod('PATCH'));
    }
}
