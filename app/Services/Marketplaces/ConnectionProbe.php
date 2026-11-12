<?php

namespace App\Services\Marketplaces;

interface ConnectionProbe
{
    public function probe(array $configuration, array $credentials): array;
}
