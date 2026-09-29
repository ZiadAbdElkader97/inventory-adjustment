# Inventory Adjustment API

A Laravel 13 API endpoint that lets an admin submit the quantity found during a
physical stock count. The endpoint sets the stock to the counted quantity and
records the difference as an inventory movement, atomically and safely under
concurrent use.

```
BAG-001 @ Main Warehouse   current: 12   counted: 9   reason: Damaged items
=> inventories.quantity = 9, inventory_movements.quantity_change = -3
```

## Requirements

- PHP 8.3+ with `pdo_mysql` (and `pdo_sqlite` for the default test run)
- Composer 2
- MySQL 8 (InnoDB). MariaDB and PostgreSQL also work.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# create the database configured in .env (default: inventory_adjustment)
mysql -uroot -e "CREATE DATABASE inventory_adjustment"

php artisan migrate --seed
php artisan serve
```

The seeder creates the scenario from the task (`BAG-001`, quantity 12, in
"Main Warehouse"), an admin (`admin@example.com`) and a non-admin
(`staff@example.com`), and prints an API token for each.

## API

### `POST /api/inventories/{inventory}/adjustments`

Authenticated with a Sanctum bearer token. Only admins may call it.

| Field               | Type    | Rules                          | Description                                                                 |
|---------------------|---------|--------------------------------|-----------------------------------------------------------------------------|
| `counted_quantity`  | integer | required, 0 – 2,147,483,647    | Quantity physically counted. The stock is set to this value.                |
| `reason`            | string  | required, 3 – 255 characters   | Why the stock is being adjusted.                                            |
| `expected_quantity` | integer | optional, >= 0                 | Stock level the admin saw before counting. If the stock has changed since, the request is rejected with `409`. |

```bash
curl -X POST http://127.0.0.1:8000/api/inventories/1/adjustments \
  -H "Authorization: Bearer <admin-token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"counted_quantity": 9, "reason": "Damaged items"}'
```

**201 Created**

```json
{
  "data": {
    "movement": {
      "id": 1,
      "inventory_id": 1,
      "quantity_change": -3,
      "reason": "Damaged items",
      "created_by": 1,
      "created_at": "2026-09-29T14:06:33+00:00"
    },
    "inventory": {
      "id": 1,
      "product_id": 1,
      "warehouse_id": 1,
      "previous_quantity": 12,
      "quantity": 9
    }
  }
}
```

| Status | When                                                                  |
|--------|-----------------------------------------------------------------------|
| `401`  | No or invalid token                                                   |
| `403`  | Authenticated user is not an admin                                    |
| `404`  | Inventory item does not exist                                         |
| `409`  | `expected_quantity` was sent and no longer matches the current stock  |
| `422`  | Validation failed, e.g. a negative `counted_quantity`                 |

**409 Conflict**

```json
{
  "message": "The inventory quantity has changed since it was last read. Please review the current quantity and submit the count again.",
  "expected_quantity": 12,
  "current_quantity": 9
}
```

## Running the tests

```bash
# Fast run on in-memory SQLite. The two tests that need a real
# database engine (concurrency, DB-level constraint) are skipped.
php artisan test

# Full run on MySQL, including the multi-process concurrency test.
mysql -uroot -e "CREATE DATABASE inventory_adjustment_testing"
composer test:mysql
```

| Test file                                   | Covers                                                                                                   |
|---------------------------------------------|----------------------------------------------------------------------------------------------------------|
| `tests/Feature/InventoryAdjustmentEndpointTest.php`    | Happy path, increase/decrease/zero, validation, 401/403/404/409, `created_by` cannot be spoofed |
| `tests/Feature/InventoryAdjustmentServiceTest.php`     | Rollback when the movement insert fails, difference computed from the locked row, DB rejects negatives |
| `tests/Feature/InventoryAdjustmentConcurrencyTest.php` | 6 OS processes x 25 adjustments on the same item at the same moment; asserts stock = initial + sum(movements) |
| `tests/Unit/InventoryPolicyTest.php`                   | Only admins may adjust                                                                          |

## Implementation decisions

### 1. The admin submits the counted quantity, the server computes the difference

The task is a stock count, so the request carries what was counted, not a delta.
The server calculates `quantity_change = counted - current` itself. This keeps
the client simple, and the difference is always computed from the actual
stock at the moment of the write, never from a value the client read earlier.

A useful side effect: submitting the same count twice (double click, retry)
leaves the stock unchanged; the second request records a movement of `0`.

### 2. Atomicity: one database transaction

`InventoryAdjustmentService::adjust()` updates `inventories.quantity` and inserts
the `inventory_movements` row inside a single `DB::transaction()`. If either
write fails, both are rolled back. A test forces the movement insert to fail and
asserts the stock is unchanged.

### 3. Concurrency: pessimistic row lock

Inside the transaction the inventory row is re-read with `SELECT ... FOR UPDATE`
(`lockForUpdate()`). If two admins adjust the same item at the same time, the
second one waits until the first commits, then computes its difference from the
committed quantity. The movements therefore always add up:

```
initial quantity + SUM(quantity_change) = current quantity
```

Without the lock both requests would read `12`, each record a difference based
on `12`, and the ledger would drift from the real stock. The concurrency test
proves this: with `lockForUpdate()` removed it fails (stock `288`, ledger
`168`); with the lock it passes consistently.

Why pessimistic instead of optimistic locking as the default:

- The transaction is tiny (one row, two statements), so the wait is milliseconds.
- Contention on a single item is naturally low (humans submitting counts).
- Callers never have to handle a retry for a request that could have succeeded.

The transaction is retried up to 3 times if the database reports a deadlock.

### 4. Optional optimistic check: `expected_quantity`

The lock guarantees consistency, but not intent. If admin A sees `12`, admin B
adjusts it to `9`, and A then submits a count, A's count may have been based on
an outdated screen. Clients that want to detect this send the quantity they saw
as `expected_quantity`; if it no longer matches, the API answers `409 Conflict`
with the current quantity so the admin can recount. It is optional so the
endpoint still works for simple clients.

### 5. Inventory can never become negative, enforced in three layers

1. Validation: `counted_quantity` must be an integer `>= 0` (`422` otherwise).
2. Service: `adjust()` throws `InvalidArgumentException` for negative values, so
   non-HTTP callers (jobs, commands) are protected too.
3. Database: `quantity` is `UNSIGNED` in MySQL; on PostgreSQL a `CHECK (quantity >= 0)`
   constraint is added.

Because the admin submits an absolute count, the resulting stock is exactly the
validated value, so it can never go below zero through this endpoint.

### 6. Authorization and audit

- Sanctum bearer tokens; `users.is_admin` marks administrators.
- `InventoryPolicy::adjust()` is checked in `AdjustInventoryRequest::authorize()`,
  so non-admins get `403` before validation runs.
- `created_by` is always the authenticated user. A `created_by` field in the
  payload is ignored (covered by a test).
- Movements are append-only: no `updated_at`, and foreign keys use
  `ON DELETE RESTRICT` so audit history cannot be orphaned or cascaded away.

### 7. Code structure

| Layer        | File                                                   |
|--------------|--------------------------------------------------------|
| Route        | `routes/api.php`                                       |
| Controller   | `app/Http/Controllers/InventoryAdjustmentController.php` (thin, delegates) |
| Validation + authorization | `app/Http/Requests/AdjustInventoryRequest.php` |
| Business logic | `app/Services/InventoryAdjustmentService.php`        |
| Policy       | `app/Policies/InventoryPolicy.php`                     |
| Response     | `app/Http/Resources/InventoryAdjustmentResource.php`   |
| Conflict error | `app/Exceptions/InventoryQuantityMismatchException.php` (renders `409`) |

## Assumptions

- **Identifying the item.** An inventory row is one product in one warehouse,
  so the endpoint addresses it by `inventories.id`. The SKU from the scenario
  lives on a minimal `products` table; a minimal `warehouses` table backs
  `warehouse_id`. `(product_id, warehouse_id)` is unique.
- **Admins** are users with `is_admin = true`. A full roles/permissions package
  was out of scope.
- **Authentication** uses Sanctum personal access tokens.
- **A count equal to the current stock is still recorded** as a movement with
  `quantity_change = 0`, so every performed count leaves an audit entry.
- **`reason` is free text** (3–255 characters), matching the example
  "Damaged items". A fixed list of reason codes would be a small change.
- **Quantities are whole units.** `counted_quantity` is capped at 2,147,483,647
  so the difference always fits the signed integer `quantity_change` column.
- **Schema additions** beyond the given structure: `created_at`/`updated_at` on
  `inventories`, the `products`/`warehouses` tables, and `users.is_admin`.
  `inventory_movements` follows the given structure exactly.
- **Target database** is MySQL 8 / InnoDB, where row locks are real. SQLite is
  only used for the fast test run; it ignores `FOR UPDATE`, which is why the
  concurrency test runs against MySQL.

## Possible next steps

- `GET /api/inventories/{inventory}/movements` to browse the audit trail.
- Store `quantity_before`/`quantity_after` on each movement for easier reporting.
- An `Idempotency-Key` header for clients that retry automatically.
- Dispatch an `InventoryAdjusted` event for notifications or syncing other systems.
