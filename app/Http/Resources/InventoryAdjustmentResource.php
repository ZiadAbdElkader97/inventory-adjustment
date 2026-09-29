<?php

namespace App\Http\Resources;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response for a freshly created adjustment. Expects the movement's inventory
 * relation to hold the stock level right after the adjustment was applied.
 *
 * @mixin InventoryMovement
 */
class InventoryAdjustmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'movement' => [
                'id' => $this->id,
                'inventory_id' => $this->inventory_id,
                'quantity_change' => $this->quantity_change,
                'reason' => $this->reason,
                'created_by' => $this->created_by,
                'created_at' => $this->created_at->toIso8601String(),
            ],
            'inventory' => [
                'id' => $this->inventory->id,
                'product_id' => $this->inventory->product_id,
                'warehouse_id' => $this->inventory->warehouse_id,
                'previous_quantity' => $this->inventory->quantity - $this->quantity_change,
                'quantity' => $this->inventory->quantity,
            ],
        ];
    }
}
