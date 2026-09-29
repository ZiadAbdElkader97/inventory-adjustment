<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustInventoryRequest extends FormRequest
{
    /**
     * Largest value that keeps the resulting quantity_change inside a signed 32-bit column.
     */
    public const MAX_QUANTITY = 2_147_483_647;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('adjust', $this->route('inventory'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'counted_quantity' => ['required', 'numeric', 'integer', 'min:0', 'max:'.self::MAX_QUANTITY],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'expected_quantity' => ['sometimes', 'nullable', 'numeric', 'integer', 'min:0', 'max:'.self::MAX_QUANTITY],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counted_quantity.min' => 'The counted quantity cannot be negative.',
        ];
    }

    public function countedQuantity(): int
    {
        return $this->integer('counted_quantity');
    }

    public function reason(): string
    {
        return $this->string('reason')->toString();
    }

    public function expectedQuantity(): ?int
    {
        return $this->filled('expected_quantity') ? $this->integer('expected_quantity') : null;
    }
}
