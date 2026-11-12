<?php

namespace App\Services\Shopify;

use App\Models\MarketplaceAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class ShopifyClient
{
    public const VERSION = '2026-10';

    private function key(MarketplaceAccount $account): string
    {
        return 'shopify-token:'.$account->organization_id.':'.$account->id.':'.hash('sha256', $account->shop_domain.json_encode($account->credentialsPayload()));
    }

    public function forgetToken(MarketplaceAccount $account): void
    {
        Cache::forget($this->key($account));
    }

    private function token(MarketplaceAccount $account): string
    {
        $cached = Cache::get($this->key($account));
        if ($cached) {
            return Crypt::decryptString($cached);
        }
        try {
            $response = Http::withOptions(['verify' => config('shopify.ca_bundle') ?: true])->connectTimeout(10)->timeout(30)->withoutRedirecting()->post('https://'.$account->shop_domain.'/admin/oauth/access_token', [
                'grant_type' => 'client_credentials', 'client_id' => $account->credentialsPayload()['client_id'], 'client_secret' => $account->credentialsPayload()['client_secret'],
            ]);
        } catch (ConnectionException) {
            throw new ShopifyException('connection', true);
        }
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new ShopifyException('token', $response->serverError() || $response->status() === 429);
        }
        $token = $response->json('access_token');
        Cache::put($this->key($account), Crypt::encryptString($token), max(1, (int) $response->json('expires_in', 86400) - 120));

        return $token;
    }

    public function query(MarketplaceAccount $account, string $query, array $variables = []): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', (string) $account->shop_domain) || ! str_starts_with(ltrim($query), 'query')) {
            throw new ShopifyException('invalid_data');
        }
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $response = Http::withOptions(['verify' => config('shopify.ca_bundle') ?: true])->connectTimeout(10)->timeout(45)->withoutRedirecting()
                    ->withHeaders(['X-Shopify-Access-Token' => $this->token($account)])
                    ->post('https://'.$account->shop_domain.'/admin/api/'.self::VERSION.'/graphql.json', ['query' => $query, 'variables' => $variables ?: (object) []]);
            } catch (ConnectionException) {
                if ($attempt < 3) {
                    $this->pause($attempt + 1);

                    continue;
                }
                throw new ShopifyException('connection', true);
            }
            if ($response->status() === 401 && $attempt < 3) {
                $this->forgetToken($account);

                continue;
            }
            $codes = array_column($response->json('errors', []), 'extensions');
            $codes = array_column($codes, 'code');
            if ($response->status() === 429 || in_array('THROTTLED', $codes, true) || $response->serverError()) {
                if ($attempt < 3) {
                    $cost = $response->json('extensions.cost', []);
                    $throttle = $cost['throttleStatus'] ?? [];
                    $seconds = max((int) $response->header('Retry-After'), $attempt + 1, (int) ceil((($cost['requestedQueryCost'] ?? 1) - ($throttle['currentlyAvailable'] ?? 0)) / max(1, $throttle['restoreRate'] ?? 1)));
                    $this->pause(min(30, $seconds));

                    continue;
                }
                throw new ShopifyException($response->serverError() ? 'connection' : 'throttled', true);
            }
            if ($response->status() === 403 || in_array('ACCESS_DENIED', $codes, true)) {
                throw new ShopifyException('permissions');
            }
            if ($response->status() === 401) {
                throw new ShopifyException('token');
            }
            if (! $response->successful() || $response->json('errors') || ! is_array($response->json('data'))) {
                throw new ShopifyException('invalid_data');
            }

            return $response->json('data');
        }
        throw new ShopifyException('connection', true);
    }

    private function pause(int $seconds): void
    {
        if (! app()->runningUnitTests()) {
            sleep($seconds);
        }
    }

    public function pages(MarketplaceAccount $account, string $query, string $path, array $variables = [], ?callable $onPage = null): \Generator
    {
        $cursor = null;
        do {
            $data = data_get($this->query($account, $query, [...$variables, 'after' => $cursor]), $path);
            if (! is_array($data) || ! isset($data['nodes'], $data['pageInfo']['hasNextPage'])) {
                throw new ShopifyException('invalid_data');
            }
            if ($onPage) {
                $onPage(['cursor' => $cursor, 'has_next_page' => (bool) $data['pageInfo']['hasNextPage'], 'complete' => false]);
            }
            foreach ($data['nodes'] as $node) {
                yield $node;
            }
            $next = $data['pageInfo']['endCursor'] ?? null;
            if ($data['pageInfo']['hasNextPage'] && (! $next || $next === $cursor)) {
                throw new ShopifyException('invalid_data');
            }
            $cursor = $next;
        } while ($data['pageInfo']['hasNextPage']);
    }
}
