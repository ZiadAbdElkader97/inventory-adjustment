<?php

namespace App\Exceptions;

use App\Models\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Thrown when the stock changed between the admin reading it and submitting a count.
 */
class InventoryQuantityMismatchException extends RuntimeException
{
    public function __construct(
        public readonly Inventory $inventory,
        public readonly int $expectedQuantity,
    ) {
        parent::__construct(sprintf(
            'Inventory %d quantity is %d, expected %d.',
            $inventory->getKey(),
            $inventory->quantity,
            $expectedQuantity,
        ));
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'The inventory quantity has changed since it was last read. Please review the current quantity and submit the count again.',
            'expected_quantity' => $this->expectedQuantity,
            'current_quantity' => $this->inventory->quantity,
        ], Response::HTTP_CONFLICT);
    }
}
