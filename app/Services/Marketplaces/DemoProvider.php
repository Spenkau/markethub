<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use Closure;

class DemoProvider implements MarketplaceProvider
{
    public function __construct(private string $code, private string $name) {}

    public function definition(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'connection_type' => 'demo', 'auth_type' => 'none', 'capabilities' => [], 'credentials_schema' => [], 'configuration_schema' => []];
    }

    public function credentialRules(): array
    {
        return [];
    }

    public function configurationRules(?MarketplaceAccount $account = null, ?int $organizationId = null): array
    {
        return [];
    }

    public function connectionChecker(): ?Closure
    {
        return null;
    }

    public function importer(): ?Closure
    {
        return null;
    }

    public function sourceNormalizer(): ?Closure
    {
        return null;
    }

    public function compatibilityWriter(): ?Closure
    {
        return null;
    }
}
