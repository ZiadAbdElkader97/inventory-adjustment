<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_id' => Inventory::factory(),
            'quantity_change' => fake()->numberBetween(-10, 10),
            'reason' => fake()->sentence(3),
            'created_by' => User::factory()->admin(),
        ];
    }
}
