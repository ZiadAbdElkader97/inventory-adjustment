<?php

namespace Tests\Unit;

use App\Models\Inventory;
use App\Models\User;
use App\Policies\InventoryPolicy;
use PHPUnit\Framework\TestCase;

class InventoryPolicyTest extends TestCase
{
    public function test_admins_can_adjust_inventory(): void
    {
        $admin = (new User)->forceFill(['is_admin' => true]);

        $this->assertTrue((new InventoryPolicy)->adjust($admin, new Inventory));
    }

    public function test_regular_users_cannot_adjust_inventory(): void
    {
        $user = (new User)->forceFill(['is_admin' => false]);

        $this->assertFalse((new InventoryPolicy)->adjust($user, new Inventory));
    }
}
