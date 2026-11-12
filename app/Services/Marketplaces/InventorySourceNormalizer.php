<?php

namespace App\Services\Marketplaces;

interface InventorySourceNormalizer
{
    public function normalizeLocation(array $raw, string $syncedAt): ExternalLocation;

    public function normalizeInventory(array $raw, bool $tracked, string $syncedAt, array $context = []): ExternalInventorySnapshot;
}
