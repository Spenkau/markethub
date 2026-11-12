<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncRun;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyException;
use App\Services\Shopify\ShopifyImporter;
use App\Support\Money;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ShopifyProvider implements ConnectionProbe, InventorySourceNormalizer, MarketplaceProvider, ProductLinks
{
    public function definition(): array
    {
        return ['code' => 'shopify', 'name' => 'Shopify', 'connection_type' => 'app_credentials', 'auth_type' => 'client_credentials',
            'capabilities' => ['check_connection', 'products', 'inventory', 'locations', 'sync', 'refresh_listing'],
            'credentials_schema' => [['key' => 'client_id', 'type' => 'string', 'required' => true, 'write_only' => true], ['key' => 'client_secret', 'type' => 'password', 'required' => true, 'write_only' => true]],
            'configuration_schema' => [['key' => 'shop_domain', 'type' => 'string', 'required' => true, 'normalize' => 'lowercase_trim']],
            'authorization_notice' => 'Client credentials установленного приложения. Не является OAuth-подключением произвольного независимого продавца.'];
    }

    public function credentialRules(): array
    {
        return ['client_id' => ['required', 'string', 'max:255'], 'client_secret' => ['required', 'string', 'max:255']];
    }

    public function configurationRules(?MarketplaceAccount $account = null, ?int $organizationId = null): array
    {
        return ['shop_domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', Rule::unique('marketplace_accounts', 'shop_domain')->where('organization_id', $organizationId)->ignore($account?->id), ...($account ? [Rule::in([$account->shop_domain])] : [])]];
    }

    public function connectionChecker(): ?Closure
    {
        return fn (MarketplaceAccount $account) => app(ShopifyImporter::class)->check($account);
    }

    public function importer(): ?Closure
    {
        return fn (MarketplaceAccount $account, MarketplaceSyncRun $run) => app(ShopifyImporter::class)->execute($account, $run);
    }

    public function sourceNormalizer(): ?Closure
    {
        return fn (MarketplaceAccount $account, array $raw, string $syncedAt) => $this->normalize($account, $raw, $syncedAt);
    }

    public function compatibilityWriter(): ?Closure
    {
        return function (MarketplaceAccount $account, array $configuration, ?array $credentials) {
            if (isset($configuration['shop_domain'])) {
                $account->shop_domain = $configuration['shop_domain'];
            }
            if ($credentials !== null) {
                app(ShopifyClient::class)->forgetToken($account);
                $account->forceFill(['shopify_client_id' => $credentials['client_id'], 'shopify_client_secret' => $credentials['client_secret']]);
            }
        };
    }

    public function probe(array $configuration, array $credentials): array
    {
        $account = new MarketplaceAccount;
        $account->forceFill(['marketplace' => 'shopify', 'shop_domain' => $configuration['shop_domain'], 'shopify_client_id' => $credentials['client_id'], 'shopify_client_secret' => $credentials['client_secret']]);
        $data = app(ShopifyClient::class)->query($account, 'query { shop { name currencyCode } currentAppInstallation { accessScopes { handle } } }');
        $scopes = array_column($data['currentAppInstallation']['accessScopes'], 'handle');

        return ['shop_name' => $data['shop']['name'], 'currency' => $data['shop']['currencyCode'], 'missing_scopes' => array_values(array_diff(['read_products', 'read_inventory', 'read_locations'], $scopes))];
    }

    public function adminUrl(MarketplaceAccount $account, ?string $externalProductId): ?string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', (string) $account->shop_domain) || ! preg_match('~^gid://shopify/Product/(\d+)$~', (string) $externalProductId, $match)) {
            return null;
        }

        return 'https://'.$account->shop_domain.'/admin/products/'.$match[1];
    }

    public function normalizeLocation(array $raw, string $syncedAt): ExternalLocation
    {
        if (! is_string($raw['id'] ?? null) || ! is_string($raw['name'] ?? null) || ! is_bool($raw['isActive'] ?? null)) {
            throw new ShopifyException('invalid_data');
        }

        return new ExternalLocation($raw['id'], $raw['name'], $raw['isActive'], $syncedAt);
    }

    public function normalizeInventory(array $raw, bool $tracked, string $syncedAt, array $context = []): ExternalInventorySnapshot
    {
        if (! is_string($raw['location']['id'] ?? null) || ! is_array($raw['quantities'] ?? null)) {
            throw new ShopifyException('invalid_data');
        }
        $quantities = [];
        foreach ($raw['quantities'] as $quantity) {
            if (! is_string($quantity['name'] ?? null) || ! (is_int($quantity['quantity'] ?? null) || array_key_exists('quantity', $quantity) && $quantity['quantity'] === null)) {
                throw new ShopifyException('invalid_data');
            }
            $quantities[$quantity['name']] = $quantity['quantity'];
        }

        return new ExternalInventorySnapshot($raw['location']['id'], $tracked, $quantities['available'] ?? null, $quantities, $raw['updatedAt'] ?? null, $syncedAt, ['inventory_item_id' => $context['inventory_item_id'] ?? null, 'inventory_level_id' => $raw['id'] ?? null]);
    }

    private function normalize(MarketplaceAccount $account, array $raw, string $syncedAt): array
    {
        $p = $raw['product'] ?? [];
        $v = $raw['variant'] ?? [];
        if (! is_string($p['id'] ?? null) || ! preg_match('~^gid://shopify/Product/\d+$~', $p['id']) || ! is_string($v['id'] ?? null) || ! preg_match('~^gid://shopify/ProductVariant/\d+$~', $v['id']) || ! is_string($p['title'] ?? null) || trim($p['title']) === '' || ! isset($v['inventoryItem']['id']) || ! isset(Money::CURRENCIES[$account->shop_currency])) {
            throw new ShopifyException('invalid_data');
        }
        $amount = $v['price'] ?? '';
        if (! is_string($amount) || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount)) {
            throw new ShopifyException('invalid_data');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount), 2, '');
        $price = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($price > 999999999999) {
            throw new ShopifyException('invalid_data');
        }
        $date = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;
        $status = strtolower($p['status'] ?? '');
        if (! in_array($status, ['active', 'draft', 'archived'], true) || ! is_array($p['images'] ?? [])) {
            throw new ShopifyException('invalid_data');
        }

        return ['parent' => ['external_id' => $p['id'], 'title' => mb_substr($p['title'], 0, 255), 'description' => $p['descriptionHtml'] ?? null,
            'status' => $status, 'images' => $p['images'] ?? [], 'source_data' => $p,
            'source_created_at' => $date($p['createdAt'] ?? null), 'source_updated_at' => $date($p['updatedAt'] ?? null), 'source_synced_at' => $syncedAt],
            'variant' => ['external_id' => $v['id'], 'external_sku' => $v['sku'] ?? null, 'variant_title' => $v['title'] ?? null, 'barcode' => $v['barcode'] ?? null,
                'price_minor' => $price, 'currency' => $account->shop_currency, 'status' => $status === 'active' ? 'active' : 'inactive', 'source_status' => $status,
                'source_created_at' => $date($v['createdAt'] ?? null), 'source_updated_at' => $date($v['updatedAt'] ?? null), 'source_synced_at' => $syncedAt]];
    }
}
