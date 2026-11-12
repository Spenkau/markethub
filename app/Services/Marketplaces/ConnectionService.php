<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCredential;
use App\Models\MarketplaceSyncRun;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConnectionService
{
    public function __construct(private ProviderRegistry $providers) {}

    public function create(int $organizationId, array $data): MarketplaceAccount
    {
        if (auth()->check()) {
            Gate::authorize('create', MarketplaceAccount::class);
            abort_unless(OrganizationContext::id() === $organizationId, 403);
        }
        $data = $this->validated($data, null, $organizationId);

        return DB::transaction(function () use ($data, $organizationId) {
            $definition = $this->providers->get($data['marketplace'])->definition();
            $account = new MarketplaceAccount;
            $account->forceFill(['organization_id' => $organizationId, 'marketplace' => $data['marketplace'], 'name' => $data['name'],
                'status' => $data['status'] ?? ($definition['connection_type'] === 'demo' ? 'demo' : 'active'),
                'is_demo' => $definition['connection_type'] === 'demo', 'configuration' => $data['configuration'] ?? []]);
            foreach (['sync_frequency', 'sync_products', 'sync_inventory'] as $field) {
                if (array_key_exists($field, $data)) {
                    $account->{$field} = $data[$field];
                }
            }
            $this->mirrorConfiguration($account, $data['configuration'] ?? []);
            $account->save();
            if (isset($data['credentials'])) {
                $this->writeCredentials($account, $definition['auth_type'], $data['credentials']);
            }

            return $account->fresh();
        });
    }

    public function update(MarketplaceAccount $account, array $data): MarketplaceAccount
    {
        if (auth()->check()) {
            Gate::authorize('update', $account);
        }

        return DB::transaction(function () use ($account, $data) {
            $account = MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $account->organization_id)->lockForUpdate()->findOrFail($account->id);
            $data = $this->validated($data, $account, $account->organization_id);
            abort_if(MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->whereIn('status', ['queued', 'running'])->exists(), 409, 'Дождитесь завершения текущей синхронизации.');
            foreach (['name', 'status', 'sync_frequency', 'sync_products', 'sync_inventory'] as $field) {
                if (array_key_exists($field, $data)) {
                    $account->{$field} = $data[$field];
                }
            }
            if (array_key_exists('configuration', $data)) {
                $account->configuration = $data['configuration'];
                $this->mirrorConfiguration($account, $data['configuration']);
            }
            if (isset($data['credentials'])) {
                $this->writeCredentials($account, $this->providers->get($account->marketplace)->definition()['auth_type'], $data['credentials']);
                $account->api_status = 'unchecked';
            }
            if (array_intersect(array_keys($data), ['status', 'sync_frequency', 'sync_products', 'sync_inventory'])) {
                $account->next_sync_at = SyncDispatcher::next($account);
            }
            if ($account->isDirty()) {
                $account->save();
            }

            return $account->fresh();
        });
    }

    public function check(MarketplaceAccount $account): array
    {
        if (auth()->check()) {
            Gate::authorize('update', $account);
        }
        $checker = $this->providers->get($account->marketplace)->connectionChecker();
        abort_unless($checker, 422, 'Демонстрационный провайдер не выполняет проверку внешнего API.');

        try {
            $result = $checker($account);
            $account->refresh();
            $account->forceFill(['api_status' => ! empty($result['missing_scopes']) ? 'missing_permissions' : 'connected', 'api_checked_at' => now()])->save();

            return $result;
        } catch (\Throwable) {
            $account->forceFill(['api_status' => 'connection_error', 'api_checked_at' => now()])->save();
            throw ValidationException::withMessages(['connection' => 'Не удалось проверить подключение. Проверьте реквизиты и права API.']);
        }
    }

    public function capabilities(MarketplaceAccount $account): array
    {
        return $this->providers->get($account->marketplace)->definition()['capabilities'];
    }

    public function disconnect(MarketplaceAccount $account): MarketplaceAccount
    {
        return $this->update($account, ['status' => 'disabled']);
    }

    private function validated(array $data, ?MarketplaceAccount $account, int $org): array
    {
        Validator::make($data, ['marketplace' => [$account ? 'sometimes' : 'required', 'string', Rule::in($this->providers->codes())]])->validate();
        $provider = $this->providers->get($account?->marketplace ?? (string) ($data['marketplace'] ?? ''));
        $definition = $provider->definition();
        if (isset($data['name']) && is_string($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        $rules = ['name' => [$account ? 'sometimes' : 'required', 'string', 'min:1', 'max:255'],
            'marketplace' => [$account ? 'sometimes' : 'required', Rule::in([$definition['code']])],
            'status' => ['sometimes', Rule::in(['demo', 'active', 'disabled'])], 'is_demo' => ['sometimes', 'boolean'],
            'sync_frequency' => ['sometimes', Rule::in(['manual', 'hourly', 'daily'])], 'sync_products' => ['sometimes', 'boolean'], 'sync_inventory' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'array'], 'credentials' => ['sometimes', 'array'],
            'organization_id' => ['prohibited'], 'is_admin' => ['prohibited'], 'active_organization_id' => ['prohibited'], 'shopify_client_id' => ['prohibited'], 'shopify_client_secret' => ['prohibited'], 'id' => ['prohibited'], 'created_at' => ['prohibited'], 'updated_at' => ['prohibited']];
        Validator::make($data, $rules)->validate();
        if ($definition['connection_type'] === 'demo') {
            Validator::make($data, ['credentials' => ['prohibited'], 'configuration' => ['prohibited']])->validate();
            if (isset($data['is_demo']) && ! $data['is_demo']) {
                Validator::make(['is_demo' => false], ['is_demo' => ['accepted']])->validate();
            }
        } else {
            Validator::make($data, ['is_demo' => ['sometimes', 'declined'], 'status' => ['sometimes', Rule::in(['active', 'disabled'])]])->validate();
            $config = array_replace($account?->configuration ?? [], $data['configuration'] ?? []);
            foreach ($definition['configuration_schema'] as $field) {
                $key = $field['key'];
                if (($field['normalize'] ?? null) === 'lowercase_trim' && isset($config[$key]) && is_string($config[$key])) {
                    $config[$key] = strtolower(trim($config[$key]));
                }
            }
            $configRules = $provider->configurationRules($account, $org);
            foreach (array_keys($config) as $key) {
                if (! array_key_exists($key, $configRules)) {
                    $configRules[$key] = ['prohibited'];
                }
            }
            Validator::make(['configuration' => $config], collect($configRules)->mapWithKeys(fn ($value, $key) => ['configuration.'.$key => $value])->all())->validate();
            if (! $account || array_key_exists('configuration', $data)) {
                $data['configuration'] = $config;
            }
            $credentials = array_replace($account?->credentialsPayload() ?? [], $data['credentials'] ?? []);
            $credentialRules = $provider->credentialRules();
            foreach (array_keys($credentials) as $key) {
                if (! array_key_exists($key, $credentialRules)) {
                    $credentialRules[$key] = ['prohibited'];
                }
            }
            Validator::make(['credentials' => $credentials], collect($credentialRules)->mapWithKeys(fn ($value, $key) => ['credentials.'.$key => $value])->all())->validate();
            if (! $account || isset($data['credentials'])) {
                $data['credentials'] = $credentials;
            }
        }

        return array_intersect_key($data, $rules);
    }

    private function mirrorConfiguration(MarketplaceAccount $account, array $configuration): void
    {
        $writer = $this->providers->get($account->marketplace)->compatibilityWriter();
        if ($writer) {
            $writer($account, $configuration, null);
        }

    }

    private function writeCredentials(MarketplaceAccount $account, string $authType, array $payload): void
    {
        $row = MarketplaceCredential::withoutGlobalScopes()->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->lockForUpdate()->first();
        if ($row && $row->payload === $payload) {
            return;
        }
        $writer = $this->providers->get($account->marketplace)->compatibilityWriter();
        if ($writer) {
            $writer($account, [], $payload);
        }
        if ($account->isDirty()) {
            $account->save();
        }
        $row ??= new MarketplaceCredential;
        $row->forceFill(['organization_id' => $account->organization_id, 'marketplace_account_id' => $account->id, 'auth_type' => $authType, 'payload' => $payload, 'version' => $row->exists ? $row->version + 1 : 1])->save();
        $account->unsetRelation('credential');
    }
}
