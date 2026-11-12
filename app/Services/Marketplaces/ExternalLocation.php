<?php

namespace App\Services\Marketplaces;

final readonly class ExternalLocation
{
    public function __construct(public string $externalId, public string $name, public bool $active, public string $syncedAt, public array $metadata = []) {}
}
