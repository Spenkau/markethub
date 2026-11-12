<?php

use App\Models\GoodsReceipt;
use App\Models\User;
use App\Services\ProcurementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    throw new LogicException('Isolated test database required.');
}
DB::select("SELECT set_config('application_name', ?, false)", [$argv[3]]);
$user = User::findOrFail($argv[1]);
auth()->guard('web')->setUser($user);
auth()->shouldUse('web');
try {
    $receipt = GoodsReceipt::findOrFail($argv[2]);
    $result = app(ProcurementService::class)->confirm($receipt, $user);
    echo json_encode(['status' => 200, 'replayed' => $result['replayed']]);
} catch (ValidationException $e) {
    echo json_encode(['status' => 422]);
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
