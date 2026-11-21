<?php
declare(strict_types=1);

namespace Warehouse;

use PDO;
use PDOException;
use Throwable;

final class EventService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function consume(array $event): array
    {
        $eventId = $event['event_id'] ?? null;
        $eventType = $event['event_type'] ?? null;
        $payload = $event['payload'] ?? null;
        if (!\is_string($eventId) || trim($eventId) === '' || !\is_string($eventType) || !\is_array($payload)) {
            throw new ApiException(422, 'event_id, event_type and object payload are required');
        }
        $allowed = ['restock.requested', 'shipment.shipped', 'po.confirmed', 'po.shipped', 'po.delivered'];
        if (!\in_array($eventType, $allowed, true)) {
            throw new ApiException(422, 'Unsupported event_type');
        }

        return $this->consumeWithRetry($eventId, $eventType, $payload, 1);
    }

    private function consumeWithRetry(string $eventId, string $eventType, array $payload, int $attempt): array
    {
        try {
            $this->db->beginTransaction();
            $insert = $this->db->prepare(
                'INSERT INTO processed_events (event_id, event_type) VALUES (?, ?)'
            );
            try {
                $insert->execute([$eventId, $eventType]);
            } catch (PDOException $exception) {
                if ($this->isDuplicateKey($exception)) {
                    $this->db->rollBack();
                    return ['duplicate' => true, 'event_id' => $eventId];
                }
                throw $exception;
            }

            match ($eventType) {
                'restock.requested' => $this->restockRequested($payload),
                'shipment.shipped' => $this->shipmentShipped($payload),
                'po.confirmed' => $this->purchaseOrderStatus($payload, 'CONFIRMED'),
                'po.shipped' => $this->purchaseOrderStatus($payload, 'SHIPPED'),
                'po.delivered' => $this->purchaseOrderStatus($payload, 'DELIVERED'),
            };
            $this->db->commit();
            return ['duplicate' => false, 'event_id' => $eventId];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($attempt >= 3 || !$this->isRetryableTransactionError($exception)) {
                throw $exception;
            }
            usleep(random_int(10000, 30000) * $attempt);
            return $this->consumeWithRetry($eventId, $eventType, $payload, $attempt + 1);
        }
    }

    private function restockRequested(array $payload): void
    {
        $orderId = $this->string($payload, 'restock_order_id', 'order_id');
        $warehouseId = $this->string($payload, 'warehouse_id');
        $retailId = $this->string($payload, 'retail_id');
        $items = $this->items($payload);
        $this->activeWarehouse($warehouseId);

        $insertOrder = $this->db->prepare(
            'INSERT INTO warehouse_restock_orders (id, warehouse_id, retail_id, status)
             VALUES (?, ?, ?, ?)'
        );
        try {
            $insertOrder->execute([$orderId, $warehouseId, $retailId, 'WAITING_SUPPLIER']);
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw new ApiException(409, 'Restock order already exists with a different event id');
            }
            throw $exception;
        }

        $this->insertRestockItems($orderId, $items);

        $allAvailable = true;
        $shortages = [];
        foreach ($items as $item) {
            $stock = $this->lockStock($warehouseId, $item['product_id']);
            $available = (int) $stock['quantity'] - (int) $stock['reserved'];
            if ($available < $item['quantity']) {
                $allAvailable = false;
                $shortages[] = [
                    'product_id' => $item['product_id'],
                    'requested' => $item['quantity'],
                    'available' => $available,
                    'shortage' => $item['quantity'] - $available,
                ];
            }
        }

        if (!$allAvailable) {
            $this->addOutbox('warehouse.stock.insufficient', [
                'restock_order_id' => $orderId,
                'warehouse_id' => $warehouseId,
                'retail_id' => $retailId,
                'shortages' => $shortages,
            ]);
            return;
        }

        foreach ($items as $item) {
            $stock = $this->lockStock($warehouseId, $item['product_id']);
            $reserved = (int) $stock['reserved'] + $item['quantity'];
            $this->setReserved($warehouseId, $item['product_id'], $reserved);
            $this->movement(
                $warehouseId, $item['product_id'], 'RESERVE', $item['quantity'], $orderId,
                (int) $stock['quantity'], (int) $stock['quantity'], (int) $stock['reserved'], $reserved
            );
        }
        $this->db->prepare("UPDATE warehouse_restock_orders SET status = 'RESERVED' WHERE id = ?")
            ->execute([$orderId]);
        $this->addOutbox('shipment.requested', [
            'restock_order_id' => $orderId,
            'warehouse_id' => $warehouseId,
            'retail_id' => $retailId,
            'items' => $items,
        ]);
    }

    private function shipmentShipped(array $payload): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $orderId = $this->string($payload, 'restock_order_id', 'order_id');
            $statement = $this->db->prepare(
                'SELECT id, warehouse_id, status
                 FROM warehouse_restock_orders
                 WHERE id = ?
                 FOR UPDATE'
            );
            $statement->execute([$orderId]);
            $order = $statement->fetch();
            if (!$order) {
                throw new ApiException(404, 'Restock order not found');
            }
            if ($order['status'] !== 'RESERVED') {
                throw new ApiException(409, 'Restock order is not reserved for shipment');
            }
            $itemsStatement = $this->db->prepare(
                'SELECT restock_order_id, product_id, quantity
                 FROM warehouse_restock_order_items
                 WHERE restock_order_id = ?
                 ORDER BY product_id'
            );
            $itemsStatement->execute([$orderId]);
            $stockUpdates = [];
            foreach ($itemsStatement->fetchAll() as $item) {
                $stock = $this->lockStock($order['warehouse_id'], $item['product_id']);
                $quantity = (int) $item['quantity'];
                $reserved = (int) $stock['reserved'] - $quantity;
                $onHand = (int) $stock['quantity'] - $quantity;
                if ($reserved < 0 || $onHand < 0 || $reserved > $onHand) {
                    throw new ApiException(409, 'Reserved stock is inconsistent for this shipment');
                }
                $stockUpdates[] = [
                    'product_id' => $item['product_id'],
                    'quantity' => $onHand,
                    'reserved' => $reserved,
                    'quantity_before' => (int) $stock['quantity'],
                    'reserved_before' => (int) $stock['reserved'],
                ];
            }

            $this->updateShipmentStock($order['warehouse_id'], $stockUpdates);

            foreach ($stockUpdates as $stockUpdate) {
                $this->movement(
                    $order['warehouse_id'], $stockUpdate['product_id'], 'OUT',
                    (int) $stockUpdate['quantity_before'] - $stockUpdate['quantity'],
                    $orderId,
                    $stockUpdate['quantity_before'], $stockUpdate['quantity'],
                    $stockUpdate['reserved_before'], $stockUpdate['reserved_before']
                );
                $this->movement(
                    $order['warehouse_id'], $stockUpdate['product_id'], 'RELEASE',
                    $stockUpdate['reserved_before'] - $stockUpdate['reserved'],
                    $orderId,
                    $stockUpdate['quantity'], $stockUpdate['quantity'],
                    $stockUpdate['reserved_before'], $stockUpdate['reserved']
                );
            }
            $this->db->prepare("UPDATE warehouse_restock_orders SET status = 'SHIPPED' WHERE id = ?")
                ->execute([$orderId]);

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function purchaseOrderStatus(array $payload, string $status): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $poId = $this->string($payload, 'po_id', 'purchase_request_id');
            $requestId = isset($payload['purchase_request_id']) && \is_string($payload['purchase_request_id'])
                ? $payload['purchase_request_id'] : $poId;
            $statement = $this->db->prepare(
                'SELECT id, po_id, status
                 FROM purchase_requests
                 WHERE po_id = ? OR id = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );
            $statement->execute([$poId, $requestId]);
            $request = $statement->fetch();
            if (!$request) {
                throw new ApiException(404, 'Purchase request not found for PO event');
            }
            $allowedTransitions = [
                'REQUESTED' => ['CONFIRMED'],
                'CONFIRMED' => ['SHIPPED'],
                'SHIPPED' => ['DELIVERED'],
                'DELIVERED' => [],
                'PARTIALLY_RECEIVED' => [],
                'RECEIVED' => [],
            ];
            if ($request['status'] !== $status) {
                if (!\in_array($status, $allowedTransitions[$request['status']] ?? [], true)) {
                    throw new ApiException(409, "Invalid purchase order transition {$request['status']} -> {$status}");
                }
                $update = $this->db->prepare(
                    'UPDATE purchase_requests
                     SET po_id = COALESCE(po_id, ?), status = ?
                     WHERE id = ? AND status = ?'
                );
                $update->execute([$poId, $status, $request['id'], $request['status']]);
                if ($update->rowCount() !== 1) {
                    throw new ApiException(409, 'Purchase order status changed concurrently');
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function items(array $payload): array
    {
        if (!isset($payload['items']) || !\is_array($payload['items']) || $payload['items'] === []) {
            throw new ApiException(422, 'Event items must be a non-empty array');
        }
        $items = [];
        $seen = [];
        foreach ($payload['items'] as $item) {
            if (!\is_array($item) || !isset($item['product_id'], $item['quantity'])
                || !\is_string($item['product_id']) || !\is_int($item['quantity']) || $item['quantity'] <= 0) {
                throw new ApiException(422, 'Each event item needs a product_id and positive integer quantity');
            }
            if (isset($seen[$item['product_id']])) {
                throw new ApiException(422, 'Duplicate product items are not allowed');
            }
            $seen[$item['product_id']] = true;
            $items[] = ['product_id' => $item['product_id'], 'quantity' => $item['quantity']];
        }
        usort($items, static fn(array $left, array $right): int => strcmp($left['product_id'], $right['product_id']));
        return $items;
    }

    private function string(array $data, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                if (!\is_string($data[$key]) || trim($data[$key]) === '') {
                    throw new ApiException(422, "{$key} must be a non-empty string");
                }
                return trim($data[$key]);
            }
        }
        throw new ApiException(422, implode(' or ', $keys) . ' is required');
    }

    private function insertRestockItems(string $orderId, array $items): void
    {
        $placeholders = [];
        $parameters = [];

        foreach ($items as $item) {
            $placeholders[] = '(?, ?, ?)';
            $parameters[] = $orderId;
            $parameters[] = $item['product_id'];
            $parameters[] = $item['quantity'];
        }

        $statement = $this->db->prepare(
            'INSERT INTO warehouse_restock_order_items (restock_order_id, product_id, quantity)
             VALUES ' . implode(', ', $placeholders)
        );
        $statement->execute($parameters);
    }

    private function activeWarehouse(string $id): void
    {
        $statement = $this->db->prepare('SELECT is_active FROM warehouses WHERE id = ?');
        $statement->execute([$id]);
        $active = $statement->fetchColumn();
        if ($active === false) {
            throw new ApiException(404, 'Warehouse not found');
        }
        if (!(bool) $active) {
            throw new ApiException(409, 'Warehouse is inactive');
        }
    }

    private function lockStock(string $warehouseId, string $productId): array
    {
        if (!$this->db->inTransaction()) {
            throw new ApiException(409, 'Stock locking requires an active transaction');
        }

        $insert = $this->db->prepare(
            'INSERT INTO warehouse_stocks (warehouse_id, product_id, quantity, reserved, min_stock)
             VALUES (?, ?, 0, 0, 0) ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)'
        );
        $insert->execute([$warehouseId, $productId]);
        // The upsert holds an InnoDB lock on this stock row for the surrounding transaction.
        $statement = $this->db->prepare(
            'SELECT warehouse_id, product_id, quantity, reserved, min_stock, updated_at
             FROM warehouse_stocks
             WHERE warehouse_id = ? AND product_id = ?'
        );
        $statement->execute([$warehouseId, $productId]);
        $stock = $statement->fetch();
        if (!$stock) {
            throw new ApiException(409, 'Stock row could not be initialized');
        }
        return $stock;
    }

    private function setReserved(string $warehouseId, string $productId, int $reserved): void
    {
        $statement = $this->db->prepare(
            'UPDATE warehouse_stocks SET reserved = ? WHERE warehouse_id = ? AND product_id = ?'
        );
        $statement->execute([$reserved, $warehouseId, $productId]);
    }

    private function updateShipmentStock(string $warehouseId, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $quantityCases = [];
        $reservedCases = [];
        $productPlaceholders = [];
        $parameters = [];

        foreach ($updates as $update) {
            $quantityCases[] = 'WHEN ? THEN ?';
            $parameters[] = $update['product_id'];
            $parameters[] = $update['quantity'];

            $reservedCases[] = 'WHEN ? THEN ?';
            $parameters[] = $update['product_id'];
            $parameters[] = $update['reserved'];

            $productPlaceholders[] = '?';
        }

        $parameters[] = $warehouseId;
        foreach ($updates as $update) {
            $parameters[] = $update['product_id'];
        }

        $quantityCaseSql = 'CASE product_id ' . implode(' ', $quantityCases) . ' END';
        $reservedCaseSql = 'CASE product_id ' . implode(' ', $reservedCases) . ' END';
        $statement = $this->db->prepare(
            "UPDATE warehouse_stocks
             SET quantity = {$quantityCaseSql}, reserved = {$reservedCaseSql}
             WHERE warehouse_id = ? AND product_id IN (" . implode(', ', $productPlaceholders) . ')'
        );
        $statement->execute($parameters);
    }

    private function movement(
        string $warehouseId,
        string $productId,
        string $type,
        int $quantity,
        string $referenceId,
        int $quantityBefore,
        int $quantityAfter,
        int $reservedBefore,
        int $reservedAfter
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO stock_movements
             (id, warehouse_id, product_id, movement_type, quantity, reference_type, reference_id,
              quantity_before, quantity_after, reserved_before, reserved_after)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $this->uuid(), $warehouseId, $productId, $type, $quantity, 'RESTOCK_ORDER',
            $referenceId, $quantityBefore, $quantityAfter, $reservedBefore, $reservedAfter,
        ]);
    }

    private function addOutbox(string $type, array $payload): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO outbox_events (id, event_type, payload) VALUES (?, ?, ?)'
        );
        $statement->execute([
            $this->uuid(), $type,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    private function isRetryableTransactionError(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }

        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        return ($exception->errorInfo[0] ?? null) === '40001'
            || \in_array($driverCode, [1205, 1213], true);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3f | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
