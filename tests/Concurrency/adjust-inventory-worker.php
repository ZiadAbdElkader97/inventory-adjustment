<?php

/*
 * Worker process for InventoryAdjustmentConcurrencyTest.
 *
 * Usage: php adjust-inventory-worker.php <inventory-id> <admin-id> <adjustments> <start-at-unix-time>
 */

use App\Models\Inventory;
use App\Models\User;
use App\Services\InventoryAdjustmentService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $inventoryId, $adminId, $adjustments, $startAt] = $argv;

$inventory = Inventory::query()->findOrFail($inventoryId);
$admin = User::query()->findOrFail($adminId);
$service = $app->make(InventoryAdjustmentService::class);

// Every worker waits for the same moment so that their transactions overlap.
$wait = (float) $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

for ($i = 0; $i < (int) $adjustments; $i++) {
    $service->adjust($inventory, random_int(0, 1000), 'Concurrent stock count', $admin);
}
