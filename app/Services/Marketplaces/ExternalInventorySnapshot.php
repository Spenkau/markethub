<?php

namespace App\Services\Marketplaces;

final readonly class ExternalInventorySnapshot
{
    public function __construct(public string $locationExternalId, public bool $tracked, public ?int $available, public array $quantities, public ?string $updatedAt, public string $syncedAt, public array $metadata = []) {}
}
