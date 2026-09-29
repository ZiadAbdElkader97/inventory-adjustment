<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdjustInventoryRequest;
use App\Http\Resources\InventoryAdjustmentResource;
use App\Models\Inventory;
use App\Services\InventoryAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class InventoryAdjustmentController extends Controller
{
    public function __construct(
        private readonly InventoryAdjustmentService $adjustments,
    ) {}

    /**
     * Set an inventory item's stock to a physically counted quantity.
     */
    public function store(AdjustInventoryRequest $request, Inventory $inventory): JsonResponse
    {
        $movement = $this->adjustments->adjust(
            inventory: $inventory,
            countedQuantity: $request->countedQuantity(),
            reason: $request->reason(),
            admin: $request->user(),
            expectedQuantity: $request->expectedQuantity(),
        );

        return InventoryAdjustmentResource::make($movement)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
