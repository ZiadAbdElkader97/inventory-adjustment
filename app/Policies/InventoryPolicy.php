<?php

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;

class InventoryPolicy
{
    /**
     * Determine whether the user can manually adjust the stock of the inventory item.
     */
    public function adjust(User $user, Inventory $inventory): bool
    {
        return $user->isAdmin();
    }
}
