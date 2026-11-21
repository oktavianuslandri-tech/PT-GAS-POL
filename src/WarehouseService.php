<?php
declare(strict_types=1);

namespace Warehouse;

use PDO;
use PDOException;
use Throwable;

final class WarehouseService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function listWarehouses(): array
    {
        return $this->db->query('SELECT * FROM warehouses ORDER BY name')->fetchAll();
    }

    public function getWarehouse(string $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM warehouses WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: throw new ApiException(404, 'Warehouse not found');
    }

    public function createWarehouse(array $input): array
    {
        $name = $this->requiredString($input, 'name');
        $code = $this->requiredString($input, 'code');
        $address = $this->optionalString($input, 'address');
        $id = $this->uuid();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO warehouses (id, code, name, address) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$id, $code, $name, $address]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw new ApiException(409, 'Warehouse code already exists');
            }
            throw $exception;
        }
        return $this->getWarehouse($id);
    }

    public function updateWarehouse(string $id, array $input): array
    {
        $this->getWarehouse($id);
        $fields = [];
        $values = [];
        foreach (['code', 'name', 'address', 'is_active'] as $field) {
            if (!\array_key_exists($field, $input)) {
                continue;
            }
            if ($field === 'is_active') {
                if (!\is_bool($input[$field])) {
                    throw new ApiException(422, 'is_active must be a boolean');
                }
                $fields[] = 'is_active = ?';
                $values[] = $input[$field] ? 1 : 0;
            } elseif ($field === 'address') {
                $fields[] = 'address = ?';
                $values[] = $this->optionalString($input, $field);
            } else {
                $fields[] = "{$field} = ?";
                $values[] = $this->requiredString($input, $field);
            }
        }
        if ($fields === []) {
            throw new ApiException(422, 'At least one warehouse field must be provided');
        }
        $values[] = $id;
        try {
            $statement = $this->db->prepare('UPDATE warehouses SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $statement->execute($values);
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw new ApiException(409, 'Warehouse code already exists');
            }
            throw $exception;
        }
        return $this->getWarehouse($id);
    }

    public function deleteWarehouse(string $id): void
    {
        $this->getWarehouse($id);
        try {
            $statement = $this->db->prepare('DELETE FROM warehouses WHERE id = ?');
            $statement->execute([$id]);
        } catch (PDOException $exception) {
            if ($this->isForeignKeyConstraintViolation($exception)) {
                throw new ApiException(409, 'Warehouse is referenced by stock or order records');
            }
            throw $exception;
        }
    }

public function listStocks(array $filters): array
{
    $sql = 'SELECT s.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                   s.product_id, s.quantity, s.reserved, s.min_stock,
                   (s.quantity - s.reserved) AS available, s.updated_at
            FROM warehouse_stocks s
            JOIN warehouses w ON w.id = s.warehouse_id
            WHERE 1 = 1';

    $values = [];

    if (!empty($filters['warehouse_id'])) {
        $sql .= ' AND s.warehouse_id = ?';
        $values[] = $filters['warehouse_id'];
    }

    if (!empty($filters['product_id'])) {
        $sql .= ' AND s.product_id LIKE ?';
        $values[] = '%' . trim($filters['product_id']) . '%';
    }

    if (isset($filters['low_stock']) && $filters['low_stock'] === 'true') {
        $sql .= ' AND s.quantity - s.reserved <= s.min_stock';
    }

    $allowedSort = [
        'quantity' => 's.quantity',
        'available' => '(s.quantity - s.reserved)',
        'updated_at' => 's.updated_at',
        'product_id' => 's.product_id',
    ];

    $sort = $filters['sort'] ?? 'product_id';
    $order = strtoupper($filters['order'] ?? 'ASC');

    $sortColumn = $allowedSort[$sort] ?? 's.product_id';

    if (!in_array($order, ['ASC', 'DESC'], true)) {
        $order = 'ASC';
    }

    $sql .= " ORDER BY {$sortColumn} {$order}";

    $statement = $this->db->prepare($sql);
    $statement->execute($values);

    return $statement->fetchAll();
}

    public function adjustStock(array $input): array
    {
        $warehouseId = $this->requiredString($input, 'warehouse_id');
        $productId = $this->requiredString($input, 'product_id');
        $delta = \array_key_exists('quantity_delta', $input) ? $this->integer($input, 'quantity_delta') : 0;
        if ($delta === 0 && !\array_key_exists('min_stock', $input)) {
            throw new ApiException(422, 'Provide a non-zero quantity_delta or min_stock');
        }
        $reason = $delta !== 0 ? $this->requiredString($input, 'reason') : 'Minimum stock update';
        $minStock = \array_key_exists('min_stock', $input) ? $this->integer($input, 'min_stock') : null;
        if ($minStock !== null && $minStock < 0) {
            throw new ApiException(422, 'min_stock must be zero or greater');
        }
        return $this->transaction(function () use ($warehouseId, $productId, $delta, $reason, $minStock): array {
            $this->assertActiveWarehouse($warehouseId);
            $stock = $this->lockStock($warehouseId, $productId);
            $newQuantity = (int) $stock['quantity'] + $delta;
            if ($newQuantity < (int) $stock['reserved']) {
                throw new ApiException(409, 'Adjustment would reduce quantity below the reserved amount');
            }
            $update = $this->db->prepare(
                'UPDATE warehouse_stocks SET quantity = ?, min_stock = ? WHERE warehouse_id = ? AND product_id = ?'
            );
            $update->execute([
                $newQuantity,
                $minStock ?? (int) $stock['min_stock'],
                $warehouseId,
                $productId,
            ]);
            if ($delta !== 0) {
                $this->movement(
                    $warehouseId,
                    $productId,
                    $delta > 0 ? 'IN' : 'OUT',
                    abs($delta),
                    'ADJUSTMENT',
                    $reason,
                    (int) $stock['quantity'],
                    $newQuantity,
                    (int) $stock['reserved'],
                    (int) $stock['reserved']
                );
            }
            return $this->stockRow($warehouseId, $productId);
        });
    }

    public function listMovements(array $filters): array
    {
        $sql = 'SELECT * FROM stock_movements WHERE 1 = 1';
        $values = [];
        foreach (['warehouse_id', 'product_id', 'movement_type', 'reference_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $sql .= " AND {$field} = ?";
                $values[] = $filters[$field];
            }
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 500';
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        return $statement->fetchAll();
    }

    public function listRestockOrders(array $filters): array
    {
        $sql = 'SELECT * FROM warehouse_restock_orders WHERE 1 = 1';
        $values = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND status = ?';
            $values[] = $filters['status'];
        }
        if (!empty($filters['warehouse_id'])) {
            $sql .= ' AND warehouse_id = ?';
            $values[] = $filters['warehouse_id'];
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 500';
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        $orders = $statement->fetchAll();
        if ($orders === []) {
            return [];
        }
        $ids = array_column($orders, 'id');
        $placeholders = \implode(',', \array_fill(0, \count($ids), '?'));
        $items = $this->db->prepare(
            "SELECT * FROM warehouse_restock_order_items WHERE restock_order_id IN ({$placeholders}) ORDER BY product_id"
        );
        $items->execute($ids);
        $byOrder = [];
        foreach ($items->fetchAll() as $item) {
            $byOrder[$item['restock_order_id']][] = $item;
        }
        foreach ($orders as &$order) {
            $order['items'] = $byOrder[$order['id']] ?? [];
        }
        return $orders;
    }

    public function rejectRestockOrder(string $id, array $input): array
    {
        $reason = $this->optionalString($input, 'reason');
        return $this->transaction(function () use ($id, $reason): array {
            $order = $this->lockRestockOrder($id);
            if (!\in_array($order['status'], ['WAITING_SUPPLIER', 'RESERVED'], true)) {
                throw new ApiException(409, 'Only waiting or reserved orders can be rejected');
            }
            if ($order['status'] === 'RESERVED') {
                foreach ($this->restockItems($id) as $item) {
                    $stock = $this->lockStock($order['warehouse_id'], $item['product_id']);
                    $reserved = (int) $stock['reserved'] - (int) $item['quantity'];
                    if ($reserved < 0) {
                        throw new ApiException(409, 'Reserved stock is inconsistent for this order');
                    }
                    $this->setReserved($order['warehouse_id'], $item['product_id'], $reserved);
                    $this->movement(
                        $order['warehouse_id'], $item['product_id'], 'RELEASE', (int) $item['quantity'],
                        'RESTOCK_ORDER', $id, (int) $stock['quantity'], (int) $stock['quantity'],
                        (int) $stock['reserved'], $reserved
                    );
                }
            }
            $statement = $this->db->prepare(
                'UPDATE warehouse_restock_orders SET status = ?, rejection_reason = ? WHERE id = ?'
            );
            $statement->execute(['REJECTED', $reason, $id]);
            return $this->restockOrder($id);
        });
    }

    public function createPurchaseRequest(array $input): array
    {
        $supplierId = $this->requiredString($input, 'supplier_id');
        $items = $this->inputItems($input);
        $defaultWarehouseId = isset($input['warehouse_id']) ? $this->requiredString($input, 'warehouse_id') : null;
        $id = $this->uuid();
        $this->transaction(function () use ($id, $supplierId, $items, $defaultWarehouseId): void {
            $statement = $this->db->prepare(
                'INSERT INTO purchase_requests (id, supplier_id, status) VALUES (?, ?, ?)'
            );
            $statement->execute([$id, $supplierId, 'REQUESTED']);
            foreach ($items as $item) {
                $warehouseId = $item['warehouse_id'] ?? $defaultWarehouseId;
                if (!\is_string($warehouseId) || $warehouseId === '') {
                    throw new ApiException(422, 'Provide warehouse_id for the request or each item');
                }
                $this->assertActiveWarehouse($warehouseId);
                $insert = $this->db->prepare(
                    'INSERT INTO purchase_request_items (purchase_request_id, warehouse_id, product_id, quantity)
                     VALUES (?, ?, ?, ?)'
                );
                $insert->execute([$id, $warehouseId, $item['product_id'], $item['quantity']]);
            }
            $this->addOutbox('po.requested', [
                'purchase_request_id' => $id,
                'supplier_id' => $supplierId,
                'items' => array_map(static fn(array $item): array => [
                    'warehouse_id' => $item['warehouse_id'] ?? $defaultWarehouseId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ], $items),
            ]);
        });
        return $this->purchaseRequest($id);
    }

    public function listPurchaseRequests(array $filters): array
    {
        $sql = 'SELECT * FROM purchase_requests WHERE 1 = 1';
        $values = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND status = ?';
            $values[] = $filters['status'];
        }
        if (!empty($filters['supplier_id'])) {
            $sql .= ' AND supplier_id = ?';
            $values[] = $filters['supplier_id'];
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 500';
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        $requests = $statement->fetchAll();
        if ($requests === []) {
            return [];
        }
        $ids = array_column($requests, 'id');
        $placeholders = \implode(',', \array_fill(0, \count($ids), '?'));
        $items = $this->db->prepare(
            "SELECT * FROM purchase_request_items WHERE purchase_request_id IN ({$placeholders}) ORDER BY product_id"
        );
        $items->execute($ids);
        $byRequest = [];
        foreach ($items->fetchAll() as $item) {
            $byRequest[$item['purchase_request_id']][] = $item;
        }
        foreach ($requests as &$request) {
            $request['items'] = $byRequest[$request['id']] ?? [];
        }
        return $requests;
    }

    public function receiveGoods(array $input): array
    {
        $poId = $this->requiredString($input, 'po_id');
        $items = $this->inputItems($input);
        $receiptId = $this->uuid();
        return $this->transaction(function () use ($poId, $items, $receiptId): array {
            $purchase = $this->lockPurchaseRequest($poId);
            if (!\in_array($purchase['status'], ['DELIVERED', 'PARTIALLY_RECEIVED'], true)) {
                throw new ApiException(409, 'Goods can only be received after the PO is delivered');
            }
            $statement = $this->db->prepare(
                'INSERT INTO goods_receipts (id, purchase_request_id) VALUES (?, ?)'
            );
            $statement->execute([$receiptId, $purchase['id']]);
            foreach ($items as $item) {
                $line = $this->lockPurchaseItem($purchase['id'], $item['product_id'], $item['warehouse_id'] ?? null);
                $qty = $item['quantity'];
                if ((int) $line['received_quantity'] + $qty > (int) $line['quantity']) {
                    throw new ApiException(409, 'Receipt quantity exceeds the outstanding PO quantity');
                }
                $stock = $this->lockStock($line['warehouse_id'], $line['product_id']);
                $newQuantity = (int) $stock['quantity'] + $qty;
                $updateStock = $this->db->prepare(
                    'UPDATE warehouse_stocks SET quantity = ? WHERE warehouse_id = ? AND product_id = ?'
                );
                $updateStock->execute([$newQuantity, $line['warehouse_id'], $line['product_id']]);
                $updateLine = $this->db->prepare(
                    'UPDATE purchase_request_items SET received_quantity = received_quantity + ?
                     WHERE purchase_request_id = ? AND warehouse_id = ? AND product_id = ?'
                );
                $updateLine->execute([$qty, $purchase['id'], $line['warehouse_id'], $line['product_id']]);
                $receiptItem = $this->db->prepare(
                    'INSERT INTO goods_receipt_items (goods_receipt_id, warehouse_id, product_id, quantity)
                     VALUES (?, ?, ?, ?)'
                );
                $receiptItem->execute([$receiptId, $line['warehouse_id'], $line['product_id'], $qty]);
                $this->movement(
                    $line['warehouse_id'], $line['product_id'], 'IN', $qty, 'GOODS_RECEIPT', $receiptId,
                    (int) $stock['quantity'], $newQuantity, (int) $stock['reserved'], (int) $stock['reserved']
                );
            }
            $remaining = $this->db->prepare(
                'SELECT COUNT(*) FROM purchase_request_items
                 WHERE purchase_request_id = ? AND received_quantity < quantity'
            );
            $remaining->execute([$purchase['id']]);
            $status = (int) $remaining->fetchColumn() === 0 ? 'RECEIVED' : 'PARTIALLY_RECEIVED';
            $this->db->prepare('UPDATE purchase_requests SET status = ? WHERE id = ?')
                ->execute([$status, $purchase['id']]);
            $this->processWaitingOrders();
            return $this->goodsReceipt($receiptId);
        });
    }

    private function processWaitingOrders(): void
    {
        $orders = $this->db->query(
            "SELECT id, warehouse_id FROM warehouse_restock_orders
             WHERE status = 'WAITING_SUPPLIER' ORDER BY created_at, id FOR UPDATE"
        )->fetchAll();
        foreach ($orders as $order) {
            $items = $this->restockItems($order['id']);
            $available = true;
            foreach ($items as $item) {
                $stock = $this->lockStock($order['warehouse_id'], $item['product_id']);
                if ((int) $stock['quantity'] - (int) $stock['reserved'] < (int) $item['quantity']) {
                    $available = false;
                }
            }
            if (!$available) {
                continue;
            }
            foreach ($items as $item) {
                $stock = $this->lockStock($order['warehouse_id'], $item['product_id']);
                $reserved = (int) $stock['reserved'] + (int) $item['quantity'];
                $this->setReserved($order['warehouse_id'], $item['product_id'], $reserved);
                $this->movement(
                    $order['warehouse_id'], $item['product_id'], 'RESERVE', (int) $item['quantity'],
                    'RESTOCK_ORDER', $order['id'], (int) $stock['quantity'], (int) $stock['quantity'],
                    (int) $stock['reserved'], $reserved
                );
            }
            $this->db->prepare("UPDATE warehouse_restock_orders SET status = 'RESERVED' WHERE id = ?")
                ->execute([$order['id']]);
            $this->addOutbox('shipment.requested', $this->shipmentPayload($order['id']));
        }
    }

    private function inputItems(array $input): array
    {
        if (!isset($input['items']) || !\is_array($input['items']) || $input['items'] === []) {
            throw new ApiException(422, 'items must be a non-empty array');
        }
        $items = [];
        $keys = [];
        foreach ($input['items'] as $item) {
            if (!\is_array($item) || \array_is_list($item)) {
                throw new ApiException(422, 'Each item must be an object');
            }
            $productId = $this->requiredString($item, 'product_id');
            $quantity = $this->integer($item, 'quantity');
            if ($quantity <= 0) {
                throw new ApiException(422, 'Item quantity must be greater than zero');
            }
            $warehouseId = isset($item['warehouse_id']) ? $this->requiredString($item, 'warehouse_id') : null;
            $key = ($warehouseId ?? '') . "\0" . $productId;
            if (isset($keys[$key])) {
                throw new ApiException(422, 'Duplicate product/warehouse items are not allowed');
            }
            $keys[$key] = true;
            $items[] = ['product_id' => $productId, 'quantity' => $quantity, 'warehouse_id' => $warehouseId];
        }
        return $items;
    }

    private function assertActiveWarehouse(string $id): void
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
        $insert = $this->db->prepare(
            'INSERT INTO warehouse_stocks (warehouse_id, product_id, quantity, reserved, min_stock)
             VALUES (?, ?, 0, 0, 0) ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)'
        );
        $insert->execute([$warehouseId, $productId]);
        $statement = $this->db->prepare(
            'SELECT * FROM warehouse_stocks WHERE warehouse_id = ? AND product_id = ? FOR UPDATE'
        );
        $statement->execute([$warehouseId, $productId]);
        return $statement->fetch();
    }

    private function stockRow(string $warehouseId, string $productId): array
    {
        $statement = $this->db->prepare(
            'SELECT *, quantity - reserved AS available FROM warehouse_stocks
             WHERE warehouse_id = ? AND product_id = ?'
        );
        $statement->execute([$warehouseId, $productId]);
        return $statement->fetch();
    }

    private function setReserved(string $warehouseId, string $productId, int $reserved): void
    {
        $statement = $this->db->prepare(
            'UPDATE warehouse_stocks SET reserved = ? WHERE warehouse_id = ? AND product_id = ?'
        );
        $statement->execute([$reserved, $warehouseId, $productId]);
    }

    private function movement(
        string $warehouseId,
        string $productId,
        string $type,
        int $quantity,
        string $referenceType,
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
            $this->uuid(), $warehouseId, $productId, $type, $quantity, $referenceType,
            $referenceId, $quantityBefore, $quantityAfter, $reservedBefore, $reservedAfter,
        ]);
    }

    private function addOutbox(string $eventType, array $payload): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO outbox_events (id, event_type, payload) VALUES (?, ?, ?)'
        );
        $statement->execute([
            $this->uuid(), $eventType,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function restockOrder(string $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM warehouse_restock_orders WHERE id = ?');
        $statement->execute([$id]);
        $order = $statement->fetch() ?: throw new ApiException(404, 'Restock order not found');
        $order['items'] = $this->restockItems($id);
        return $order;
    }

    private function restockItems(string $id): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM warehouse_restock_order_items WHERE restock_order_id = ? ORDER BY product_id'
        );
        $statement->execute([$id]);
        return $statement->fetchAll();
    }

    private function lockRestockOrder(string $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM warehouse_restock_orders WHERE id = ? FOR UPDATE');
        $statement->execute([$id]);
        return $statement->fetch() ?: throw new ApiException(404, 'Restock order not found');
    }

    private function shipmentPayload(string $id): array
    {
        $order = $this->restockOrder($id);
        return [
            'restock_order_id' => $order['id'],
            'warehouse_id' => $order['warehouse_id'],
            'retail_id' => $order['retail_id'],
            'items' => array_map(static fn(array $item): array => [
                'product_id' => $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ], $order['items']),
        ];
    }

    private function purchaseRequest(string $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM purchase_requests WHERE id = ?');
        $statement->execute([$id]);
        $request = $statement->fetch() ?: throw new ApiException(404, 'Purchase request not found');
        $items = $this->db->prepare(
            'SELECT * FROM purchase_request_items WHERE purchase_request_id = ? ORDER BY warehouse_id, product_id'
        );
        $items->execute([$id]);
        $request['items'] = $items->fetchAll();
        return $request;
    }

    private function lockPurchaseRequest(string $poId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM purchase_requests WHERE po_id = ? OR id = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$poId, $poId]);
        return $statement->fetch() ?: throw new ApiException(404, 'Purchase order not found');
    }

    private function lockPurchaseItem(string $purchaseId, string $productId, ?string $warehouseId): array
    {
        $sql = 'SELECT * FROM purchase_request_items WHERE purchase_request_id = ? AND product_id = ?';
        $values = [$purchaseId, $productId];
        if ($warehouseId !== null) {
            $sql .= ' AND warehouse_id = ?';
            $values[] = $warehouseId;
        }
        $sql .= ' FOR UPDATE';
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        $rows = $statement->fetchAll();
        if (\count($rows) !== 1) {
            throw new ApiException(422, 'Receipt item must match exactly one PO line; include warehouse_id if needed');
        }
        return $rows[0];
    }

    private function goodsReceipt(string $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM goods_receipts WHERE id = ?');
        $statement->execute([$id]);
        $receipt = $statement->fetch();
        $items = $this->db->prepare('SELECT * FROM goods_receipt_items WHERE goods_receipt_id = ?');
        $items->execute([$id]);
        $receipt['items'] = $items->fetchAll();
        return $receipt;
    }

    private function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!\is_string($value) || trim($value) === '') {
            throw new ApiException(422, "{$key} is required and must be a non-empty string");
        }
        return trim($value);
    }

    private function optionalString(array $input, string $key): ?string
    {
        if (!\array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        if (!\is_string($input[$key])) {
            throw new ApiException(422, "{$key} must be a string or null");
        }
        return trim($input[$key]);
    }

    private function integer(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (!\is_int($value)) {
            throw new ApiException(422, "{$key} must be an integer");
        }
        return $value;
    }

    private function transaction(callable $callback): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $callback();
            $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
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

    private function isDuplicateKey(PDOException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    private function isForeignKeyConstraintViolation(PDOException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1451;
    }
}
