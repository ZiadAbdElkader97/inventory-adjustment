<?php

namespace Tests\Feature;

use App\Exceptions\InventoryQuantityMismatchException;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\InventoryAdjustmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class InventoryAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(InventoryAdjustmentService::class);
    }

    public function test_it_sets_the_counted_quantity_and_records_the_difference(): void
    {
        $admin = User::factory()->admin()->create();
        $inventory = Inventory::factory()->quantity(12)->create();

        $movement = $this->service->adjust($inventory, 9, 'Damaged items', $admin);

        $this->assertTrue($movement->exists);
        $this->assertSame(-3, $movement->quantity_change);
        $this->assertSame('Damaged items', $movement->reason);
        $this->assertSame($admin->id, $movement->created_by);
        $this->assertSame(9, $movement->inventory->quantity);
        $this->assertSame(9, $inventory->fresh()->quantity);
    }

    public function test_the_difference_is_computed_from_the_current_row_not_the_callers_stale_copy(): void
    {
        $admin = User::factory()->admin()->create();
        $inventory = Inventory::factory()->quantity(12)->create();

        // Another admin's adjustment lands after this copy was loaded.
        DB::table('inventories')->where('id', $inventory->id)->update(['quantity' => 10]);

        $movement = $this->service->adjust($inventory, 9, 'Damaged items', $admin);

        $this->assertSame(-1, $movement->quantity_change);
        $this->assertSame(9, $inventory->fresh()->quantity);
    }

    public function test_the_stock_update_is_rolled_back_when_the_movement_cannot_be_recorded(): void
    {
        $admin = User::factory()->admin()->create();
        $inventory = Inventory::factory()->quantity(12)->create();

        InventoryMovement::creating(function () {
            throw new RuntimeException('Simulated failure while recording the movement.');
        });

        try {
            $this->service->adjust($inventory, 9, 'Damaged items', $admin);
            $this->fail('The adjustment should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure while recording the movement.', $e->getMessage());
        }

        $this->assertSame(12, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_it_refuses_a_negative_counted_quantity(): void
    {
        $admin = User::factory()->admin()->create();
        $inventory = Inventory::factory()->quantity(12)->create();

        try {
            $this->service->adjust($inventory, -1, 'Damaged items', $admin);
            $this->fail('A negative counted quantity should have been refused.');
        } catch (InvalidArgumentException) {
            //
        }

        $this->assertSame(12, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_it_refuses_the_adjustment_when_the_expected_quantity_is_stale(): void
    {
        $admin = User::factory()->admin()->create();
        $inventory = Inventory::factory()->quantity(9)->create();

        try {
            $this->service->adjust($inventory, 10, 'Recount', $admin, expectedQuantity: 12);
            $this->fail('A stale expected quantity should have been refused.');
        } catch (InventoryQuantityMismatchException $e) {
            $this->assertSame(12, $e->expectedQuantity);
            $this->assertSame(9, $e->inventory->quantity);
        }

        $this->assertSame(9, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_the_database_itself_rejects_a_negative_quantity(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite does not enforce unsigned columns.');
        }

        $inventory = Inventory::factory()->quantity(12)->create();

        $this->expectException(QueryException::class);

        DB::table('inventories')->where('id', $inventory->id)->update(['quantity' => -1]);
    }
}
