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
        return $this->db->query(
            'SELECT id, code, name, address, is_active, created_at, updated_at
             FROM warehouses ORDER BY name'
        )->fetchAll();
    }

    public function getWarehouse(string $id): array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, name, address, is_active, created_at, updated_at
             FROM warehouses WHERE id = ?'
        );
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
                FROM warehouse_stocks s JOIN warehouses w ON w.id = s.warehouse_id WHERE 1 = 1';
        $values = [];
        foreach (['warehouse_id', 'product_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $sql .= " AND s.{$field} = ?";
                $values[] = $filters[$field];
            }
        }
        $sql .= ' ORDER BY w.name, s.product_id';
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
        $this->db->beginTransaction();
        try {
            $this->assertActiveWarehouse($warehouseId);
            $insert = $this->db->prepare(
                'INSERT INTO warehouse_stocks (warehouse_id, product_id, quantity, reserved, min_stock)
                 VALUES (?, ?, 0, 0, 0) ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)'
            );
            $insert->execute([$warehouseId, $productId]);
            // The upsert holds an InnoDB lock on this stock row for the surrounding transaction.
            $statement = $this->db->prepare(
                'SELECT warehouse_id, product_id, quantity, reserved, min_stock, updated_at
                 FROM warehouse_stocks WHERE warehouse_id = ? AND product_id = ?'
            );
            $statement->execute([$warehouseId, $productId]);
            $stock = $statement->fetch();
            if (!$stock) {
                throw new ApiException(409, 'Stock row could not be initialized');
            }
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
            $result = $this->stockRow($warehouseId, $productId);
            $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function listMovements(array $filters): array
    {
        $sql = 'SELECT id, warehouse_id, product_id, movement_type, quantity, reference_type,
                       reference_id, quantity_before, quantity_after, reserved_before,
                       reserved_after, created_at
                FROM stock_movements WHERE 1 = 1';
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
        $sql = 'SELECT id, warehouse_id, retail_id, status, rejection_reason, created_at, updated_at
                FROM warehouse_restock_orders WHERE 1 = 1';
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
            "SELECT restock_order_id, product_id, quantity
             FROM warehouse_restock_order_items
             WHERE restock_order_id IN ({$placeholders}) ORDER BY product_id"
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
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'SELECT id, warehouse_id, retail_id, status, rejection_reason, created_at, updated_at
                 FROM warehouse_restock_orders WHERE id = ? FOR UPDATE'
            );
            $statement->execute([$id]);
            $order = $statement->fetch() ?: throw new ApiException(404, 'Restock order not found');
            if (!\in_array($order['status'], ['WAITING_SUPPLIER', 'RESERVED'], true)) {
                throw new ApiException(409, 'Only waiting or reserved orders can be rejected');
            }
            if ($order['status'] === 'RESERVED') {
                $items = $this->restockItems($id);
                $stockRows = $this->uniqueStockRows(array_map(
                    static fn(array $item): array => [
                        'warehouse_id' => $order['warehouse_id'],
                        'product_id' => $item['product_id'],
                    ],
                    $items
                ));
                $this->insertStockRows($stockRows);

                $productPlaceholders = \implode(',', \array_fill(0, \count($stockRows), '?'));
                $stockStatement = $this->db->prepare(
                    'SELECT warehouse_id, product_id, quantity, reserved, min_stock, updated_at
                     FROM warehouse_stocks
                     WHERE warehouse_id = ? AND product_id IN (' . $productPlaceholders . ')
                     ORDER BY product_id'
                );
                $stockStatement->execute(array_merge(
                    [$order['warehouse_id']],
                    array_column($stockRows, 'product_id')
                ));
                $stocksByProduct = [];
                foreach ($stockStatement->fetchAll() as $stock) {
                    $stocksByProduct[$stock['product_id']] = $stock;
                }

                $reservedUpdates = [];
                $movements = [];
                foreach ($items as $item) {
                    $stock = $stocksByProduct[$item['product_id']] ?? null;
                    if (!$stock) {
                        throw new ApiException(409, 'Stock row could not be initialized');
                    }
                    $reserved = (int) $stock['reserved'] - (int) $item['quantity'];
                    if ($reserved < 0) {
                        throw new ApiException(409, 'Reserved stock is inconsistent for this order');
                    }
                    $key = $this->stockKey($order['warehouse_id'], $item['product_id']);
                    $reservedUpdates[$key] = [
                        'warehouse_id' => $order['warehouse_id'],
                        'product_id' => $item['product_id'],
                        'reserved' => $reserved,
                    ];
                    $movements[] = [
                        'warehouse_id' => $order['warehouse_id'],
                        'product_id' => $item['product_id'],
                        'movement_type' => 'RELEASE',
                        'quantity' => (int) $item['quantity'],
                        'reference_type' => 'RESTOCK_ORDER',
                        'reference_id' => $id,
                        'quantity_before' => (int) $stock['quantity'],
                        'quantity_after' => (int) $stock['quantity'],
                        'reserved_before' => (int) $stock['reserved'],
                        'reserved_after' => $reserved,
                    ];
                }
                $this->updateReservedStocks(array_values($reservedUpdates));
                $this->insertMovements($movements);
            }
            $statement = $this->db->prepare(
                'UPDATE warehouse_restock_orders SET status = ?, rejection_reason = ? WHERE id = ?'
            );
            $statement->execute(['REJECTED', $reason, $id]);
            $result = $this->restockOrder($id);
            $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function createPurchaseRequest(array $input): array
    {
        $supplierId = $this->requiredString($input, 'supplier_id');
        $items = $this->inputItems($input);
        $defaultWarehouseId = isset($input['warehouse_id']) ? $this->requiredString($input, 'warehouse_id') : null;
        $purchaseItems = [];
        foreach ($items as $item) {
            $warehouseId = $item['warehouse_id'] ?? $defaultWarehouseId;
            if (!\is_string($warehouseId) || $warehouseId === '') {
                throw new ApiException(422, 'Provide warehouse_id for the request or each item');
            }
            $purchaseItems[] = [
                'warehouse_id' => $warehouseId,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
            ];
        }
        $id = $this->uuid();
        $this->transaction(function () use ($id, $supplierId, $purchaseItems): void {
            $this->assertActiveWarehouses(array_column($purchaseItems, 'warehouse_id'));
            $statement = $this->db->prepare(
                'INSERT INTO purchase_requests (id, supplier_id, status) VALUES (?, ?, ?)'
            );
            $statement->execute([$id, $supplierId, 'REQUESTED']);

            $placeholders = [];
            $parameters = [];
            foreach ($purchaseItems as $item) {
                $placeholders[] = '(?, ?, ?, ?)';
                $parameters[] = $id;
                $parameters[] = $item['warehouse_id'];
                $parameters[] = $item['product_id'];
                $parameters[] = $item['quantity'];
            }
            $insert = $this->db->prepare(
                'INSERT INTO purchase_request_items
                 (purchase_request_id, warehouse_id, product_id, quantity)
                 VALUES ' . implode(', ', $placeholders)
            );
            $insert->execute($parameters);

            $this->addOutbox('po.requested', [
                'purchase_request_id' => $id,
                'supplier_id' => $supplierId,
                'items' => $purchaseItems,
            ]);
        });
        return $this->purchaseRequest($id);
    }

    public function listPurchaseRequests(array $filters): array
    {
        $sql = 'SELECT id, po_id, supplier_id, status, created_at, updated_at
                FROM purchase_requests WHERE 1 = 1';
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
            "SELECT purchase_request_id, warehouse_id, product_id, quantity, received_quantity
             FROM purchase_request_items
             WHERE purchase_request_id IN ({$placeholders}) ORDER BY product_id"
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
        $this->db->beginTransaction();
        try {
            $purchaseStatement = $this->db->prepare(
                'SELECT id, po_id, supplier_id, status, created_at, updated_at
                 FROM purchase_requests
                 WHERE po_id = ? OR id = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1 FOR UPDATE'
            );
            $purchaseStatement->execute([$poId, $poId]);
            $purchase = $purchaseStatement->fetch() ?: throw new ApiException(404, 'Purchase order not found');
            if (!\in_array($purchase['status'], ['DELIVERED', 'PARTIALLY_RECEIVED'], true)) {
                throw new ApiException(409, 'Goods can only be received after the PO is delivered');
            }
            $statement = $this->db->prepare(
                'INSERT INTO goods_receipts (id, purchase_request_id) VALUES (?, ?)'
            );
            $statement->execute([$receiptId, $purchase['id']]);
            $receiptLines = [];
            $productIds = array_values(array_unique(array_column($items, 'product_id')));
            $productPlaceholders = \implode(',', \array_fill(0, \count($productIds), '?'));
            // The purchase request row is already locked above, serializing receipts for its item rows.
            $lineStatement = $this->db->prepare(
                'SELECT purchase_request_id, warehouse_id, product_id, quantity, received_quantity
                 FROM purchase_request_items
                 WHERE purchase_request_id = ? AND product_id IN (' . $productPlaceholders . ')
                 ORDER BY warehouse_id, product_id'
            );
            $lineStatement->execute(array_merge([$purchase['id']], $productIds));
            $linesByProduct = [];
            foreach ($lineStatement->fetchAll() as $line) {
                $linesByProduct[$line['product_id']][] = $line;
            }
            $receiptLineKeys = [];
            foreach ($items as $item) {
                $lines = $linesByProduct[$item['product_id']] ?? [];
                if (isset($item['warehouse_id'])) {
                    $lines = array_values(array_filter(
                        $lines,
                        static fn(array $line): bool => $line['warehouse_id'] === $item['warehouse_id']
                    ));
                }
                if (\count($lines) !== 1) {
                    throw new ApiException(422, 'Receipt item must match exactly one PO line; include warehouse_id if needed');
                }
                $line = $lines[0];
                $lineKey = $this->stockKey($line['warehouse_id'], $line['product_id']);
                if (isset($receiptLineKeys[$lineKey])) {
                    throw new ApiException(422, 'Duplicate product/warehouse receipt items are not allowed');
                }
                $receiptLineKeys[$lineKey] = true;
                $qty = $item['quantity'];
                if ((int) $line['received_quantity'] + $qty > (int) $line['quantity']) {
                    throw new ApiException(409, 'Receipt quantity exceeds the outstanding PO quantity');
                }
                $receiptLines[] = ['line' => $line, 'quantity' => $qty];
            }
            $stockRows = $this->uniqueStockRows(array_map(
                static fn(array $receiptLine): array => [
                    'warehouse_id' => $receiptLine['line']['warehouse_id'],
                    'product_id' => $receiptLine['line']['product_id'],
                ],
                $receiptLines
            ));
            $this->insertStockRows($stockRows);
            $stockConditions = [];
            $stockParameters = [];
            foreach ($stockRows as $stockRow) {
                $stockConditions[] = '(warehouse_id = ? AND product_id = ?)';
                $stockParameters[] = $stockRow['warehouse_id'];
                $stockParameters[] = $stockRow['product_id'];
            }
            $stockStatement = $this->db->prepare(
                'SELECT warehouse_id, product_id, quantity, reserved, min_stock, updated_at
                 FROM warehouse_stocks WHERE ' . implode(' OR ', $stockConditions) . '
                 ORDER BY warehouse_id, product_id'
            );
            $stockStatement->execute($stockParameters);
            $stocksByKey = [];
            foreach ($stockStatement->fetchAll() as $stock) {
                $stocksByKey[$this->stockKey($stock['warehouse_id'], $stock['product_id'])] = $stock;
            }

            $stockUpdates = [];
            $receivedUpdates = [];
            $receiptItems = [];
            $movements = [];
            foreach ($receiptLines as $receiptLine) {
                $line = $receiptLine['line'];
                $qty = $receiptLine['quantity'];
                $stock = $stocksByKey[$this->stockKey($line['warehouse_id'], $line['product_id'])] ?? null;
                if (!$stock) {
                    throw new ApiException(409, 'Stock row could not be initialized');
                }
                $newQuantity = (int) $stock['quantity'] + $qty;
                $stockUpdates[] = [
                    'warehouse_id' => $line['warehouse_id'],
                    'product_id' => $line['product_id'],
                    'quantity' => $newQuantity,
                ];
                $receivedUpdates[] = [
                    'warehouse_id' => $line['warehouse_id'],
                    'product_id' => $line['product_id'],
                    'quantity' => $qty,
                ];
                $receiptItems[] = [
                    'warehouse_id' => $line['warehouse_id'],
                    'product_id' => $line['product_id'],
                    'quantity' => $qty,
                ];
                $movements[] = [
                    'warehouse_id' => $line['warehouse_id'],
                    'product_id' => $line['product_id'],
                    'movement_type' => 'IN',
                    'quantity' => $qty,
                    'reference_type' => 'GOODS_RECEIPT',
                    'reference_id' => $receiptId,
                    'quantity_before' => (int) $stock['quantity'],
                    'quantity_after' => $newQuantity,
                    'reserved_before' => (int) $stock['reserved'],
                    'reserved_after' => (int) $stock['reserved'],
                ];
            }
            $this->insertReceiptItems($receiptId, $receiptItems);
            $this->updateReceivedStock($stockUpdates);
            $this->updateReceivedQuantities($purchase['id'], $receivedUpdates);
            $this->insertMovements($movements);
            $remaining = $this->db->prepare(
                'SELECT COUNT(*) FROM purchase_request_items
                 WHERE purchase_request_id = ? AND received_quantity < quantity'
            );
            $remaining->execute([$purchase['id']]);
            $status = (int) $remaining->fetchColumn() === 0 ? 'RECEIVED' : 'PARTIALLY_RECEIVED';
            $this->db->prepare('UPDATE purchase_requests SET status = ? WHERE id = ?')
                ->execute([$status, $purchase['id']]);
            $this->processWaitingOrders();
            $result = $this->goodsReceipt($receiptId);
            $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function insertReceiptItems(string $receiptId, array $items): void
    {
        if ($items === []) {
            return;
        }

        $placeholders = [];
        $parameters = [];
        foreach ($items as $item) {
            $placeholders[] = '(?, ?, ?, ?)';
            $parameters[] = $receiptId;
            $parameters[] = $item['warehouse_id'];
            $parameters[] = $item['product_id'];
            $parameters[] = $item['quantity'];
        }

        $statement = $this->db->prepare(
            'INSERT INTO goods_receipt_items
             (goods_receipt_id, warehouse_id, product_id, quantity)
             VALUES ' . implode(', ', $placeholders)
        );
        $statement->execute($parameters);
    }

    private function updateReceivedStock(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $quantityCases = [];
        $keyConditions = [];
        $parameters = [];

        foreach ($updates as $update) {
            $quantityCases[] = 'WHEN warehouse_id = ? AND product_id = ? THEN ?';
            $parameters[] = $update['warehouse_id'];
            $parameters[] = $update['product_id'];
            $parameters[] = $update['quantity'];

            $keyConditions[] = '(warehouse_id = ? AND product_id = ?)';
            $parameters[] = $update['warehouse_id'];
            $parameters[] = $update['product_id'];
        }

        $statement = $this->db->prepare(
            'UPDATE warehouse_stocks
             SET quantity = CASE ' . implode(' ', $quantityCases) . ' ELSE quantity END
             WHERE ' . implode(' OR ', $keyConditions)
        );
        $statement->execute($parameters);
    }

    private function updateReceivedQuantities(string $purchaseRequestId, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $quantityCases = [];
        $keyConditions = [];
        $caseParameters = [];
        $keyParameters = [];

        foreach ($updates as $update) {
            $quantityCases[] = 'WHEN warehouse_id = ? AND product_id = ? THEN received_quantity + ?';
            $caseParameters[] = $update['warehouse_id'];
            $caseParameters[] = $update['product_id'];
            $caseParameters[] = $update['quantity'];

            $keyConditions[] = '(warehouse_id = ? AND product_id = ?)';
            $keyParameters[] = $update['warehouse_id'];
            $keyParameters[] = $update['product_id'];
        }

        $parameters = array_merge($caseParameters, [$purchaseRequestId], $keyParameters);
        $statement = $this->db->prepare(
            'UPDATE purchase_request_items
             SET received_quantity = CASE ' . implode(' ', $quantityCases) . ' ELSE received_quantity END
             WHERE purchase_request_id = ? AND (' . implode(' OR ', $keyConditions) . ')'
        );
        $statement->execute($parameters);
    }

    private function processWaitingOrders(): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $orders = $this->db->query(
                "SELECT id, warehouse_id, retail_id FROM warehouse_restock_orders
                 WHERE status = 'WAITING_SUPPLIER' ORDER BY created_at, id FOR UPDATE"
            )->fetchAll();
            $itemsByOrder = [];
            $stockRows = [];
            if ($orders !== []) {
                $orderIds = array_column($orders, 'id');
                $placeholders = \implode(',', \array_fill(0, \count($orderIds), '?'));
                $itemsStatement = $this->db->prepare(
                    "SELECT restock_order_id, product_id, quantity
                     FROM warehouse_restock_order_items
                     WHERE restock_order_id IN ({$placeholders})
                     ORDER BY restock_order_id, product_id"
                );
                $itemsStatement->execute($orderIds);
                $warehouseByOrder = array_column($orders, 'warehouse_id', 'id');
                foreach ($itemsStatement->fetchAll() as $item) {
                    $itemsByOrder[$item['restock_order_id']][] = $item;
                    $stockRows[] = [
                        'warehouse_id' => $warehouseByOrder[$item['restock_order_id']],
                        'product_id' => $item['product_id'],
                    ];
                }
            }
            $stockRows = $this->uniqueStockRows($stockRows);
            $this->insertStockRows($stockRows);

            $stockConditions = [];
            $stockParameters = [];
            foreach ($stockRows as $stockRow) {
                $stockConditions[] = '(warehouse_id = ? AND product_id = ?)';
                $stockParameters[] = $stockRow['warehouse_id'];
                $stockParameters[] = $stockRow['product_id'];
            }
            $stocksByKey = [];
            if ($stockConditions !== []) {
                $stockStatement = $this->db->prepare(
                    'SELECT warehouse_id, product_id, quantity, reserved, min_stock, updated_at
                     FROM warehouse_stocks WHERE ' . implode(' OR ', $stockConditions) . '
                     ORDER BY warehouse_id, product_id'
                );
                $stockStatement->execute($stockParameters);
                foreach ($stockStatement->fetchAll() as $stock) {
                    $stocksByKey[$this->stockKey($stock['warehouse_id'], $stock['product_id'])] = $stock;
                }
            }

            $reservedOrderIds = [];
            $reservedUpdates = [];
            $movements = [];
            $outboxEvents = [];
            foreach ($orders as $order) {
                $items = $itemsByOrder[$order['id']] ?? [];
                $available = true;
                foreach ($items as $item) {
                    $stock = $stocksByKey[$this->stockKey($order['warehouse_id'], $item['product_id'])] ?? null;
                    if (!$stock) {
                        throw new ApiException(409, 'Stock row could not be initialized');
                    }
                    if ((int) $stock['quantity'] - (int) $stock['reserved'] < (int) $item['quantity']) {
                        $available = false;
                    }
                }
                if (!$available) {
                    continue;
                }
                foreach ($items as $item) {
                    $key = $this->stockKey($order['warehouse_id'], $item['product_id']);
                    $stock = $stocksByKey[$key];
                    $reserved = (int) $stock['reserved'] + (int) $item['quantity'];
                    $reservedUpdates[$key] = [
                        'warehouse_id' => $order['warehouse_id'],
                        'product_id' => $item['product_id'],
                        'reserved' => $reserved,
                    ];
                    $movements[] = [
                        'warehouse_id' => $order['warehouse_id'],
                        'product_id' => $item['product_id'],
                        'movement_type' => 'RESERVE',
                        'quantity' => (int) $item['quantity'],
                        'reference_type' => 'RESTOCK_ORDER',
                        'reference_id' => $order['id'],
                        'quantity_before' => (int) $stock['quantity'],
                        'quantity_after' => (int) $stock['quantity'],
                        'reserved_before' => (int) $stock['reserved'],
                        'reserved_after' => $reserved,
                    ];
                    $stocksByKey[$key]['reserved'] = $reserved;
                }
                $reservedOrderIds[] = $order['id'];
                $outboxEvents[] = [
                    'event_type' => 'shipment.requested',
                    'payload' => [
                        'restock_order_id' => $order['id'],
                        'warehouse_id' => $order['warehouse_id'],
                        'retail_id' => $order['retail_id'],
                        'items' => array_map(
                            static fn(array $item): array => [
                                'product_id' => $item['product_id'],
                                'quantity' => (int) $item['quantity'],
                            ],
                            $items
                        ),
                    ],
                ];
            }
            $this->updateReservedStocks(array_values($reservedUpdates));
            $this->insertMovements($movements);
            $this->insertOutboxEvents($outboxEvents);

            if ($reservedOrderIds !== []) {
                $placeholders = \implode(',', \array_fill(0, \count($reservedOrderIds), '?'));
                $this->db->prepare(
                    "UPDATE warehouse_restock_orders SET status = 'RESERVED'
                     WHERE status = 'WAITING_SUPPLIER' AND id IN ({$placeholders})"
                )->execute($reservedOrderIds);
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

    private function assertActiveWarehouses(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }

        $placeholders = \implode(',', \array_fill(0, \count($ids), '?'));
        $statement = $this->db->prepare(
            "SELECT id, is_active FROM warehouses WHERE id IN ({$placeholders})"
        );
        $statement->execute($ids);
        $warehousesById = [];
        foreach ($statement->fetchAll() as $warehouse) {
            $warehousesById[$warehouse['id']] = (bool) $warehouse['is_active'];
        }
        foreach ($ids as $id) {
            if (!\array_key_exists($id, $warehousesById)) {
                throw new ApiException(404, 'Warehouse not found');
            }
            if (!$warehousesById[$id]) {
                throw new ApiException(409, 'Warehouse is inactive');
            }
        }
    }

    private function insertStockRows(array $stockRows): void
    {
        $uniqueRows = $this->uniqueStockRows($stockRows);
        if ($uniqueRows === []) {
            return;
        }

        $placeholders = [];
        $parameters = [];
        foreach ($uniqueRows as $row) {
            $placeholders[] = '(?, ?, 0, 0, 0)';
            $parameters[] = $row['warehouse_id'];
            $parameters[] = $row['product_id'];
        }
        $statement = $this->db->prepare(
            'INSERT INTO warehouse_stocks (warehouse_id, product_id, quantity, reserved, min_stock)
             VALUES ' . implode(', ', $placeholders) . '
             ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)'
        );
        // The upsert takes InnoDB row locks until the enclosing transaction completes.
        $statement->execute($parameters);
    }

    private function uniqueStockRows(array $stockRows): array
    {
        $uniqueRows = [];
        foreach ($stockRows as $row) {
            $uniqueRows[$this->stockKey($row['warehouse_id'], $row['product_id'])] = $row;
        }
        return array_values($uniqueRows);
    }

    private function stockKey(string $warehouseId, string $productId): string
    {
        return $warehouseId . "\0" . $productId;
    }

    private function updateReservedStocks(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $cases = [];
        $conditions = [];
        $parameters = [];
        foreach ($updates as $update) {
            $cases[] = 'WHEN warehouse_id = ? AND product_id = ? THEN ?';
            $parameters[] = $update['warehouse_id'];
            $parameters[] = $update['product_id'];
            $parameters[] = $update['reserved'];
            $conditions[] = '(warehouse_id = ? AND product_id = ?)';
        }
        foreach ($updates as $update) {
            $parameters[] = $update['warehouse_id'];
            $parameters[] = $update['product_id'];
        }
        $statement = $this->db->prepare(
            'UPDATE warehouse_stocks
             SET reserved = CASE ' . implode(' ', $cases) . ' ELSE reserved END
             WHERE ' . implode(' OR ', $conditions)
        );
        $statement->execute($parameters);
    }

    private function insertMovements(array $movements): void
    {
        if ($movements === []) {
            return;
        }

        $placeholders = [];
        $parameters = [];
        foreach ($movements as $movement) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $parameters[] = $this->uuid();
            $parameters[] = $movement['warehouse_id'];
            $parameters[] = $movement['product_id'];
            $parameters[] = $movement['movement_type'];
            $parameters[] = $movement['quantity'];
            $parameters[] = $movement['reference_type'];
            $parameters[] = $movement['reference_id'];
            $parameters[] = $movement['quantity_before'];
            $parameters[] = $movement['quantity_after'];
            $parameters[] = $movement['reserved_before'];
            $parameters[] = $movement['reserved_after'];
        }
        $statement = $this->db->prepare(
            'INSERT INTO stock_movements
             (id, warehouse_id, product_id, movement_type, quantity, reference_type, reference_id,
              quantity_before, quantity_after, reserved_before, reserved_after)
             VALUES ' . implode(', ', $placeholders)
        );
        $statement->execute($parameters);
    }

    private function insertOutboxEvents(array $events): void
    {
        if ($events === []) {
            return;
        }

        $placeholders = [];
        $parameters = [];
        foreach ($events as $event) {
            $placeholders[] = '(?, ?, ?)';
            $parameters[] = $this->uuid();
            $parameters[] = $event['event_type'];
            $parameters[] = json_encode(
                $event['payload'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
        $statement = $this->db->prepare(
            'INSERT INTO outbox_events (id, event_type, payload) VALUES ' . implode(', ', $placeholders)
        );
        $statement->execute($parameters);
    }

    private function stockRow(string $warehouseId, string $productId): array
    {
        $statement = $this->db->prepare(
            'SELECT warehouse_id, product_id, quantity, reserved, min_stock, updated_at,
                    quantity - reserved AS available
             FROM warehouse_stocks
             WHERE warehouse_id = ? AND product_id = ?'
        );
        $statement->execute([$warehouseId, $productId]);
        return $statement->fetch();
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
        $statement = $this->db->prepare(
            'SELECT id, warehouse_id, retail_id, status, rejection_reason, created_at, updated_at
             FROM warehouse_restock_orders WHERE id = ?'
        );
        $statement->execute([$id]);
        $order = $statement->fetch() ?: throw new ApiException(404, 'Restock order not found');
        $order['items'] = $this->restockItems($id);
        return $order;
    }

    private function restockItems(string $id): array
    {
        $statement = $this->db->prepare(
            'SELECT restock_order_id, product_id, quantity
             FROM warehouse_restock_order_items WHERE restock_order_id = ? ORDER BY product_id'
        );
        $statement->execute([$id]);
        return $statement->fetchAll();
    }

    private function purchaseRequest(string $id): array
    {
        $statement = $this->db->prepare(
            'SELECT id, po_id, supplier_id, status, created_at, updated_at
             FROM purchase_requests WHERE id = ?'
        );
        $statement->execute([$id]);
        $request = $statement->fetch() ?: throw new ApiException(404, 'Purchase request not found');
        $items = $this->db->prepare(
            'SELECT purchase_request_id, warehouse_id, product_id, quantity, received_quantity
             FROM purchase_request_items
             WHERE purchase_request_id = ? ORDER BY warehouse_id, product_id'
        );
        $items->execute([$id]);
        $request['items'] = $items->fetchAll();
        return $request;
    }

    private function goodsReceipt(string $id): array
    {
        $statement = $this->db->prepare(
            'SELECT id, purchase_request_id, created_at FROM goods_receipts WHERE id = ?'
        );
        $statement->execute([$id]);
        $receipt = $statement->fetch() ?: throw new ApiException(404, 'Goods receipt not found');
        $items = $this->db->prepare(
            'SELECT id, goods_receipt_id, warehouse_id, product_id, quantity
             FROM goods_receipt_items WHERE goods_receipt_id = ?'
        );
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
