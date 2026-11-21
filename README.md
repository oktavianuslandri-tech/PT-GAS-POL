# Warehouse service (PHP)

Warehouse inventory, retail restock fulfillment, supplier purchasing, and goods receipt API. Requires PHP 8.1+ with PDO MySQL; the outbox publisher additionally requires cURL. MySQL 8.0.16+ is recommended for enforced `CHECK` constraints.

## Setup

1. Import [`database/schema.sql`](database/schema.sql) into MySQL (for example with phpMyAdmin).
2. Copy `.env.example` to `.env`, set the database connection, and replace all example bearer tokens with long random secrets. The service loads `.env` automatically; externally configured environment variables take precedence.
3. Configure Apache to allow `.htaccess` overrides and enable `mod_rewrite`. Alternatively, call `index.php` directly with the corresponding route path.
4. Ensure the PHP `pdo_mysql` extension is enabled. Keep the project directory out of public deployments if you do not need direct HTTP access to it; source/config directories are denied by their `.htaccess` files.

All API responses are JSON. Send `Authorization: Bearer <token>`:

| Token | Access |
|---|---|
| `OWNER_TOKEN` | Warehouse master, and stock reads |
| `WAREHOUSE_MANAGER_TOKEN` | Stock, restock, purchase, and receipt operations |
| `EVENT_CONSUMER_TOKEN` | `POST /events` only |

`GET /health` checks database connectivity and does not require authentication.

## Endpoints

| Method | Path | Authorization |
|---|---|---|
| GET, POST | `/warehouses` | OWNER |
| GET, PUT, PATCH, DELETE | `/warehouses/{id}` | OWNER |
| GET | `/stocks?warehouse_id=&product_id=` | WAREHOUSE_MANAGER, OWNER |
| POST | `/stocks/adjustments` | WAREHOUSE_MANAGER |
| GET | `/stock-movements?warehouse_id=&product_id=&movement_type=&reference_id=` | WAREHOUSE_MANAGER |
| GET | `/restock-orders?status=&warehouse_id=` | WAREHOUSE_MANAGER |
| POST | `/restock-orders/{id}/reject` | WAREHOUSE_MANAGER |
| GET, POST | `/purchase-requests` | WAREHOUSE_MANAGER |
| POST | `/goods-receipts` | WAREHOUSE_MANAGER |
| POST | `/events` | EVENT_CONSUMER_TOKEN |

Create a warehouse with `{"code":"WH-01","name":"Central warehouse","address":"..."}`.

Set a stock quantity with `POST /stocks/adjustments`:

```json
{"warehouse_id":"<warehouse-uuid>","product_id":"SKU-1","quantity_delta":50,"min_stock":10,"reason":"Opening stock"}
```

`quantity_delta` is a signed, non-zero integer. Positive adjustments create `IN` movements; negative adjustments create `OUT` movements and cannot reduce on-hand stock below reservations. `min_stock` may also be set without changing quantity by sending only `warehouse_id`, `product_id`, and `min_stock`.

Create a supplier purchase request with `{"supplier_id":"SUP-1","warehouse_id":"<warehouse-uuid>","items":[{"product_id":"SKU-1","quantity":20}]}`. `warehouse_id` can be specified once at the top level or individually per item. Purchase requests create a `po.requested` outbox event.

## Event contract

The event consumer accepts:

```json
{"event_id":"unique-upstream-event-id","event_type":"restock.requested","payload":{"restock_order_id":"retail-order-id","warehouse_id":"<warehouse-uuid>","retail_id":"retail-id","items":[{"product_id":"SKU-1","quantity":4}]}}
```

Supported `event_type` values: `restock.requested`, `shipment.shipped`, `po.confirmed`, `po.shipped`, and `po.delivered`.

Restock orders reserve all requested lines atomically if available. Otherwise, they enter `WAITING_SUPPLIER` and emit `warehouse.stock.insufficient`. Supplier purchase-order status events correlate by `purchase_request_id` and/or `po_id`; goods can be received after `po.delivered` by posting `{"po_id":"<po-id>","items":[{"product_id":"SKU-1","quantity":10}]}` to `/goods-receipts`. Add `warehouse_id` to a receipt item if that product occurs in multiple warehouses on the PO. A receipt cannot exceed the remaining PO quantity. Receipt stock is posted and waiting restock orders are retried in the same transaction.

When a `shipment.shipped` event arrives, it must identify the `restock_order_id` (or `order_id`). The complete reserved order is deducted from on-hand stock and the reservation released. Duplicate upstream `event_id` values are acknowledged without applying changes again.

Outbox events are persisted in the same transaction as the state changes. Configure `EVENT_PUBLISH_URL` to an HTTP event gateway that accepts the envelope:

```json
{"event_id":"<uuid>","event_type":"shipment.requested","occurred_at":"...","payload":{}}
```

Run `php bin/publish-outbox.php` periodically (for example, from Windows Task Scheduler or cron). The relay retries failed deliveries with exponential backoff; the gateway should deduplicate by `event_id` because HTTP delivery is at-least-once.

## Notes

- Every stock change and reservation/release writes a `stock_movements` audit row in the same database transaction.
- `available` is calculated as `quantity - reserved`; the database also enforces `reserved <= quantity`.
- The stock adjustment endpoint accepts `min_stock` to manage the threshold; threshold-only changes do not create stock movement records because on-hand and reserved stock do not change.
- Configure TLS and restrict API access at the web server or gateway in production. Bearer secrets should be stored outside source control.
