<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Runs several admins' adjustments in parallel OS processes against a real
 * database. The workers must see committed data, so this test cannot use the
 * RefreshDatabase transaction; it prepares and cleans its own tables instead.
 */
#[Group('concurrency')]
class InventoryAdjustmentConcurrencyTest extends TestCase
{
    private const WORKERS = 6;

    private const ADJUSTMENTS_PER_WORKER = 25;

    private const INITIAL_QUANTITY = 500;

    private const TABLES = ['inventory_movements', 'inventories', 'products', 'warehouses', 'users'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Requires MySQL, MariaDB or PostgreSQL. Run `composer test:mysql`.');
        }

        $this->artisan('migrate');
        $this->truncateTables();
    }

    protected function tearDown(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->truncateTables();
        }

        parent::tearDown();
    }

    public function test_concurrent_adjustments_keep_stock_and_movement_ledger_consistent(): void
    {
        $inventory = Inventory::factory()->quantity(self::INITIAL_QUANTITY)->create();
        $admins = User::factory()->admin()->count(self::WORKERS)->create();

        $startAt = microtime(true) + 5;

        $processes = $admins->map(fn (User $admin): InvokedProcess => Process::path(base_path())
            ->env($this->workerEnvironment())
            ->timeout(180)
            ->start([
                PHP_BINARY,
                'tests/Concurrency/adjust-inventory-worker.php',
                (string) $inventory->id,
                (string) $admin->id,
                (string) self::ADJUSTMENTS_PER_WORKER,
                sprintf('%.6F', $startAt),
            ]));

        foreach ($processes as $process) {
            $result = $process->wait();

            $this->assertTrue($result->successful(), "Worker failed:\n".$result->errorOutput().$result->output());
        }

        $movements = InventoryMovement::query()->where('inventory_id', $inventory->id);

        $this->assertSame(self::WORKERS * self::ADJUSTMENTS_PER_WORKER, $movements->count());
        $this->assertSame(
            $inventory->fresh()->quantity,
            self::INITIAL_QUANTITY + (int) $movements->sum('quantity_change'),
            'The stock level no longer equals the initial stock plus the sum of all recorded movements.',
        );
    }

    private function truncateTables(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            foreach (self::TABLES as $table) {
                DB::table($table)->truncate();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $name = DB::getDefaultConnection();
        $connection = config("database.connections.{$name}");

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $name,
            'DB_URL' => '',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
        ];
    }
}
