<?php

namespace App\Services\Marketplaces;

use Illuminate\Validation\ValidationException;

class ProviderRegistry
{
    public function codes(): array
    {
        return array_keys(config('marketplaces.providers'));
    }

    public function get(string $code): MarketplaceProvider
    {
        $definition = config('marketplaces.providers.'.$code);
        if (! $definition) {
            throw ValidationException::withMessages(['marketplace' => 'Неизвестный провайдер подключения.']);
        }

        return app()->makeWith($definition['adapter'], ['code' => $code, 'name' => $definition['name']]);
    }

    public function definitions(): array
    {
        return array_map(fn ($code) => $this->get($code)->definition(), $this->codes());
    }
}
