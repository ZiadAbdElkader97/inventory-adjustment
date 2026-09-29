<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryAdjustmentEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_adjust_inventory_to_the_counted_quantity(): void
    {
        $admin = User::factory()->admin()->create();
        $inventory = Inventory::factory()
            ->for(Product::factory()->state(['sku' => 'BAG-001']))
            ->quantity(12)
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => 9,
            'reason' => 'Damaged items',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.movement.inventory_id', $inventory->id)
            ->assertJsonPath('data.movement.quantity_change', -3)
            ->assertJsonPath('data.movement.reason', 'Damaged items')
            ->assertJsonPath('data.movement.created_by', $admin->id)
            ->assertJsonPath('data.inventory.id', $inventory->id)
            ->assertJsonPath('data.inventory.previous_quantity', 12)
            ->assertJsonPath('data.inventory.quantity', 9)
            ->assertJsonStructure(['data' => ['movement' => ['id', 'created_at']]]);

        $this->assertSame(9, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $inventory->id,
            'quantity_change' => -3,
            'reason' => 'Damaged items',
            'created_by' => $admin->id,
        ]);
    }

    public function test_numeric_strings_are_accepted_as_quantities(): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => '9',
            'reason' => 'Damaged items',
            'expected_quantity' => '12',
        ])->assertCreated()->assertJsonPath('data.movement.quantity_change', -3);

        $this->assertSame(9, $inventory->fresh()->quantity);
    }

    public function test_counting_more_than_recorded_records_a_positive_movement(): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => 15,
            'reason' => 'Found misplaced items',
        ])->assertCreated()->assertJsonPath('data.movement.quantity_change', 3);

        $this->assertSame(15, $inventory->fresh()->quantity);
    }

    public function test_counted_quantity_can_be_zero(): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => 0,
            'reason' => 'Stolen',
        ])->assertCreated()->assertJsonPath('data.movement.quantity_change', -12);

        $this->assertSame(0, $inventory->fresh()->quantity);
    }

    public function test_count_matching_the_current_stock_is_recorded_as_a_zero_movement(): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => 12,
            'reason' => 'Routine stock count',
        ])->assertCreated()->assertJsonPath('data.movement.quantity_change', 0);

        $this->assertSame(12, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_consecutive_adjustments_keep_the_movement_ledger_consistent_with_stock(): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson($this->endpoint($inventory), ['counted_quantity' => 9, 'reason' => 'Damaged items'])
            ->assertCreated()
            ->assertJsonPath('data.movement.quantity_change', -3);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson($this->endpoint($inventory), ['counted_quantity' => 10, 'reason' => 'Recount'])
            ->assertCreated()
            ->assertJsonPath('data.movement.quantity_change', 1);

        $this->assertSame(10, $inventory->fresh()->quantity);
        $this->assertSame(-2, (int) InventoryMovement::query()->where('inventory_id', $inventory->id)->sum('quantity_change'));
    }

    public function test_negative_counted_quantity_is_rejected(): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => -1,
            'reason' => 'Damaged items',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['counted_quantity' => 'cannot be negative']);

        $this->assertSame(12, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected(array $payload, string $invalidField): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson($this->endpoint($inventory), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($invalidField);

        $this->assertSame(12, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing counted quantity' => [['reason' => 'Damaged items'], 'counted_quantity'],
            'null counted quantity' => [['counted_quantity' => null, 'reason' => 'Damaged items'], 'counted_quantity'],
            'non numeric counted quantity' => [['counted_quantity' => 'nine', 'reason' => 'Damaged items'], 'counted_quantity'],
            'fractional counted quantity' => [['counted_quantity' => 9.5, 'reason' => 'Damaged items'], 'counted_quantity'],
            'boolean counted quantity' => [['counted_quantity' => true, 'reason' => 'Damaged items'], 'counted_quantity'],
            'counted quantity too large' => [['counted_quantity' => 2_147_483_648, 'reason' => 'Damaged items'], 'counted_quantity'],
            'missing reason' => [['counted_quantity' => 9], 'reason'],
            'blank reason' => [['counted_quantity' => 9, 'reason' => '   '], 'reason'],
            'reason too short' => [['counted_quantity' => 9, 'reason' => 'ab'], 'reason'],
            'reason too long' => [['counted_quantity' => 9, 'reason' => str_repeat('a', 256)], 'reason'],
            'non string reason' => [['counted_quantity' => 9, 'reason' => ['Damaged items']], 'reason'],
            'negative expected quantity' => [['counted_quantity' => 9, 'reason' => 'Damaged items', 'expected_quantity' => -1], 'expected_quantity'],
            'non numeric expected quantity' => [['counted_quantity' => 9, 'reason' => 'Damaged items', 'expected_quantity' => 'twelve'], 'expected_quantity'],
            'boolean expected quantity' => [['counted_quantity' => 9, 'reason' => 'Damaged items', 'expected_quantity' => true], 'expected_quantity'],
        ];
    }

    public function test_the_acting_admin_is_recorded_even_if_the_payload_names_someone_else(): void
    {
        $admin = User::factory()->admin()->create();
        $someoneElse = User::factory()->admin()->create();
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs($admin);

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => 9,
            'reason' => 'Damaged items',
            'created_by' => $someoneElse->id,
        ])->assertCreated()->assertJsonPath('data.movement.created_by', $admin->id);

        $this->assertDatabaseHas('inventory_movements', ['created_by' => $admin->id]);
        $this->assertDatabaseMissing('inventory_movements', ['created_by' => $someoneElse->id]);
    }

    public function test_adjustment_is_accepted_when_expected_quantity_matches_the_current_stock(): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => 9,
            'reason' => 'Damaged items',
            'expected_quantity' => 12,
        ])->assertCreated()->assertJsonPath('data.movement.quantity_change', -3);

        $this->assertSame(9, $inventory->fresh()->quantity);
    }

    public function test_adjustment_is_rejected_with_conflict_when_stock_changed_since_it_was_read(): void
    {
        $inventory = Inventory::factory()->quantity(9)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => 10,
            'reason' => 'Recount',
            'expected_quantity' => 12,
        ])
            ->assertConflict()
            ->assertJsonPath('expected_quantity', 12)
            ->assertJsonPath('current_quantity', 9);

        $this->assertSame(9, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_guest_cannot_adjust_inventory(): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => 9,
            'reason' => 'Damaged items',
        ])->assertUnauthorized();

        $this->assertSame(12, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_non_admin_user_cannot_adjust_inventory(): void
    {
        $inventory = Inventory::factory()->quantity(12)->create();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->endpoint($inventory), [
            'counted_quantity' => 9,
            'reason' => 'Damaged items',
        ])->assertForbidden();

        $this->assertSame(12, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_adjusting_an_unknown_inventory_item_returns_not_found(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/inventories/999999/adjustments', [
            'counted_quantity' => 9,
            'reason' => 'Damaged items',
        ])->assertNotFound();

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    private function endpoint(Inventory $inventory): string
    {
        return route('inventories.adjustments.store', $inventory);
    }
}
