<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with the scenario from the task.
     */
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Warehouse Admin',
            'email' => 'admin@example.com',
        ]);

        $staff = User::factory()->create([
            'name' => 'Warehouse Staff',
            'email' => 'staff@example.com',
        ]);

        $inventory = Inventory::factory()
            ->for(Product::factory()->state(['sku' => 'BAG-001', 'name' => 'Canvas Bag']))
            ->for(Warehouse::factory()->state(['name' => 'Main Warehouse']))
            ->quantity(12)
            ->create();

        $this->command?->info("Inventory BAG-001 @ Main Warehouse: id={$inventory->id}, quantity={$inventory->quantity}");
        $this->command?->info('Admin API token: '.$admin->createToken('api')->plainTextToken);
        $this->command?->info('Non-admin API token: '.$staff->createToken('api')->plainTextToken);
    }
}
