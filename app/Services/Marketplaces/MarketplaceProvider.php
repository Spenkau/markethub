<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use Closure;

interface MarketplaceProvider
{
    public function definition(): array;

    public function credentialRules(): array;

    public function configurationRules(?MarketplaceAccount $account = null, ?int $organizationId = null): array;

    public function connectionChecker(): ?Closure;

    public function importer(): ?Closure;

    public function sourceNormalizer(): ?Closure;

    public function compatibilityWriter(): ?Closure;
}
