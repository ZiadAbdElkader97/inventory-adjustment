<?php

namespace App\Services;

use App\Exceptions\InventoryQuantityMismatchException;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryAdjustmentService
{
    /**
     * How many times the transaction is retried when the database reports a
     * deadlock or lock wait timeout.
     */
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Set an inventory item's stock to the physically counted quantity and
     * record the difference as a movement, atomically.
     *
     * @param  int|null  $expectedQuantity  The stock level the admin saw before counting.
     *                                      When given, the adjustment is rejected if the
     *                                      stock has changed since then.
     *
     * @throws InvalidArgumentException
     * @throws InventoryQuantityMismatchException
     */
    public function adjust(
        Inventory $inventory,
        int $countedQuantity,
        string $reason,
        User $admin,
        ?int $expectedQuantity = null,
    ): InventoryMovement {
        if ($countedQuantity < 0) {
            throw new InvalidArgumentException('Counted quantity cannot be negative.');
        }

        return DB::transaction(function () use ($inventory, $countedQuantity, $reason, $admin, $expectedQuantity) {
            // The difference must be computed from the row as it is while we hold
            // the lock; the caller's copy may already be stale.
            $locked = Inventory::query()
                ->whereKey($inventory->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($expectedQuantity !== null && $locked->quantity !== $expectedQuantity) {
                throw new InventoryQuantityMismatchException($locked, $expectedQuantity);
            }

            $quantityChange = $countedQuantity - $locked->quantity;

            $locked->quantity = $countedQuantity;
            $locked->save();

            $movement = $locked->movements()->create([
                'quantity_change' => $quantityChange,
                'reason' => $reason,
                'created_by' => $admin->getKey(),
            ]);

            return $movement->setRelation('inventory', $locked);
        }, self::TRANSACTION_ATTEMPTS);
    }
}
