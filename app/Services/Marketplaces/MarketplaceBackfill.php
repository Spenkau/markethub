<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCredential;
use Illuminate\Support\Facades\DB;

class MarketplaceBackfill
{
    public function __construct(private ProviderRegistry $providers, private MarketplaceImportService $imports) {}

    public function run(): array
    {
        $result = ['credentials_created' => 0, 'variants_processed' => 0, 'skipped' => 0, 'issues' => []];
        MarketplaceAccount::withoutGlobalScopes()->orderBy('id')->chunkById(100, function ($accounts) use (&$result) {
            foreach ($accounts as $account) {
                $provider = $this->providers->get($account->marketplace);
                $normalizer = $provider->sourceNormalizer();
                if (! $normalizer) {
                    if ($provider->definition()['connection_type'] === 'demo') {
                        DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('origin', 'local')->whereNull('source_data')->update(['origin' => 'demo']);
                    }

                    continue;
                } // Demo IDs remain synthetic and untouched.
                try {
                    if (! MarketplaceCredential::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->exists()) {
                        $payload = $account->credentialsPayload();
                        if (! empty($payload['client_id']) && ! empty($payload['client_secret'])) {
                            $credential = new MarketplaceCredential;
                            $credential->forceFill(['organization_id' => $account->organization_id, 'marketplace_account_id' => $account->id, 'auth_type' => $provider->definition()['auth_type'], 'payload' => $payload, 'version' => 1])->save();
                            $result['credentials_created']++;
                        }
                    }
                    if ($account->configuration === null && $account->shop_domain) {
                        DB::table('marketplace_accounts')->where('id', $account->id)->update(['configuration' => json_encode(['shop_domain' => $account->shop_domain])]);
                    }
                } catch (\Throwable) {
                    $result['issues'][] = ['account_id' => $account->id, 'reason' => 'credentials_unavailable'];
                    $result['skipped']++;
                }
                DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->orderBy('id')->chunkById(100, function ($rows) use ($account, $normalizer, &$result) {
                    foreach ($rows as $row) {
                        try {
                            $raw = json_decode($row->source_data ?? '', true, flags: JSON_THROW_ON_ERROR);
                            $data = $normalizer($account, $raw, $row->source_synced_at ?? $row->updated_at);
                            // Preserve existing published state and price, including deleted snapshots.
                            $data['variant'] = [...$data['variant'], 'source_status' => $row->source_status ?? $data['variant']['source_status'], 'status' => $row->status, 'price_minor' => (int) $row->price_minor, 'currency' => $row->currency];
                            $this->imports->upsert($account, $data['parent'], $data['variant'], $raw, $row->id, true);
                            $result['variants_processed']++;
                        } catch (\Throwable) {
                            $result['skipped']++;
                            if (count($result['issues']) < 100) {
                                $result['issues'][] = ['listing_id' => $row->id, 'reason' => 'source_unavailable_or_invalid'];
                            }
                        }
                    }
                });
            }
        });

        return $result;
    }
}
