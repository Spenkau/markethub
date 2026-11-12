<?php

use App\Models\MarketplaceAccount;
use App\Services\Marketplaces\CatalogMappingService;
use App\Services\Marketplaces\MarketplaceImportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    throw new LogicException('Isolated test database required.');
}
DB::select("SELECT set_config('application_name', ?, false)", [$argv[2]]);
$data = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$account = MarketplaceAccount::withoutGlobalScopes()->findOrFail($data['account']);
if ($data['action'] === 'map') {
    app(CatalogMappingService::class)->map($account, $data['listing'], $data['product']);
} else {
    // An obsolete caller may supply a mapping. The source whitelist must still reject it.
    app(MarketplaceImportService::class)->upsert($account, $data['parent'], [...$data['variant'], 'product_id' => null], []);
}
echo json_encode(['status' => 'ok']);
