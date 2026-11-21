<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/WarehouseService.php';
require_once __DIR__ . '/src/EventService.php';

use Warehouse\ApiException;
use Warehouse\Config;
use Warehouse\Database;
use Warehouse\EventService;
use Warehouse\Http;
use Warehouse\WarehouseService;

try {
    Config::load(__DIR__ . '/.env');
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim($scriptDirectory, '/.');
    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath));
    }
    $path = preg_replace('#/index\.php(?=/|$)#', '', $path) ?: '/';
    $path = rtrim($path, '/') ?: '/';
    $body = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? Http::jsonBody() : [];
    $db = Database::connection();
    $warehouse = new WarehouseService($db);
    $events = new EventService($db);

    if ($method === 'GET' && $path === '/health') {
        $db->query('SELECT 1');
        Http::json(['status' => 'ok']);
    }

    if ($method === 'POST' && $path === '/events') {
        Http::requireInternalToken();
        $result = $events->consume($body);
        Http::json($result, $result['duplicate'] ? 200 : 202);
    }

    if ($path === '/warehouses') {
        Http::requireRole(['OWNER']);
        if ($method === 'GET') {
            Http::json($warehouse->listWarehouses());
        }
        if ($method === 'POST') {
            Http::json($warehouse->createWarehouse($body), 201);
        }
    }

    if (preg_match('#^/warehouses/([^/]+)$#', $path, $matches)) {
        Http::requireRole(['OWNER']);
        if ($method === 'GET') {
            Http::json($warehouse->getWarehouse($matches[1]));
        }
        if ($method === 'PUT' || $method === 'PATCH') {
            Http::json($warehouse->updateWarehouse($matches[1], $body));
        }
        if ($method === 'DELETE') {
            $warehouse->deleteWarehouse($matches[1]);
            Http::json(['deleted' => true]);
        }
    }

    if ($method === 'GET' && $path === '/stocks') {
        Http::requireRole(['WAREHOUSE_MANAGER', 'OWNER']);
        Http::json($warehouse->listStocks($_GET));
    }
    if ($method === 'POST' && $path === '/stocks/adjustments') {
        Http::requireRole(['WAREHOUSE_MANAGER']);
        Http::json($warehouse->adjustStock($body), 201);
    }
    if ($method === 'GET' && $path === '/stock-movements') {
        Http::requireRole(['WAREHOUSE_MANAGER']);
        Http::json($warehouse->listMovements($_GET));
    }
    if ($method === 'GET' && $path === '/restock-orders') {
        Http::requireRole(['WAREHOUSE_MANAGER']);
        Http::json($warehouse->listRestockOrders($_GET));
    }
    if (preg_match('#^/restock-orders/([^/]+)/reject$#', $path, $matches) && $method === 'POST') {
        Http::requireRole(['WAREHOUSE_MANAGER']);
        Http::json($warehouse->rejectRestockOrder($matches[1], $body));
    }
    if ($path === '/purchase-requests') {
        Http::requireRole(['WAREHOUSE_MANAGER']);
        if ($method === 'POST') {
            Http::json($warehouse->createPurchaseRequest($body), 201);
        }
        if ($method === 'GET') {
            Http::json($warehouse->listPurchaseRequests($_GET));
        }
    }
    if ($method === 'POST' && $path === '/goods-receipts') {
        Http::requireRole(['WAREHOUSE_MANAGER']);
        Http::json($warehouse->receiveGoods($body), 201);
    }

    Http::json(['error' => 'Not found'], 404);
} catch (ApiException $exception) {
    Http::json(['error' => $exception->getMessage()], $exception->status);
} catch (Throwable $exception) {
    error_log((string) $exception);
    Http::json(['error' => 'Internal server error'], 500);
}
