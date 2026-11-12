<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Models\Organization;
use App\Services\Marketplaces\ConnectionService;
use App\Services\Shopify\ShopifyException;
use App\Services\Shopify\ShopifyImporter;
use Illuminate\Console\Command;

class ConnectLocalShopify extends Command
{
    protected $signature = 'shopify:connect-local {file : Existing credentials file} {--organization= : Existing organization ID}';

    protected $description = 'Store encrypted Shopify credentials for an existing local organization; no import is started';

    public function handle(ShopifyImporter $importer): int
    {
        if (! app()->environment('local')) {
            $this->error('Available only in local environment.');

            return self::FAILURE;
        }
        $organization = Organization::find($this->option('organization'));
        if (! $organization) {
            $this->error('Specify an existing organization with --organization.');

            return self::FAILURE;
        }
        $file = $this->argument('file');
        if (! is_readable($file)) {
            $this->error('Credentials file is not readable.');

            return self::FAILURE;
        }
        $raw = file_get_contents($file);
        if (! preg_match('/ClientID\s+(\S+)/i', $raw, $id) || ! preg_match('/Client Secret\s+(\S+)/i', $raw, $secret) || ! preg_match('/\b([a-z0-9][a-z0-9-]*\.myshopify\.com)\b/i', $raw, $domain)) {
            $this->error('Expected ClientID, Client Secret and address fields.');

            return self::FAILURE;
        }
        $account = MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $organization->id)->where('shop_domain', strtolower($domain[1]))->first();
        $connections = app(ConnectionService::class);
        $payload = ['credentials' => ['client_id' => $id[1], 'client_secret' => $secret[1]]];
        $account = $account ? $connections->update($account, $payload) : $connections->create($organization->id, ['marketplace' => 'shopify', 'name' => 'Shopify', 'configuration' => ['shop_domain' => strtolower($domain[1])], ...$payload]);
        try {
            $result = $connections->check($account);
            if ($account->name === 'Shopify') {
                $account = $connections->update($account, ['name' => $result['shop_name']]);
            }
            $this->info('Connection '.$account->id.' configured. Missing scopes: '.implode(', ', $result['missing_scopes']));
            $this->info('No import started. Confirm the first import in the Shopify page.');

            return self::SUCCESS;
        } catch (ShopifyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
