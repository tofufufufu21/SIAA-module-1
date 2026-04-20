<?php
// routes/api.php
// Register all Module 1 routes.
// Future modules add their routes here too.

use App\Core\Router;
use App\Controllers\AssetController;
use App\Controllers\AttachmentController;
use App\Controllers\InventoryController;
use App\Controllers\LookupController;
use App\Controllers\DashboardController;

$router = new Router();

// ── Dashboard ─────────────────────────────────────────────────
$router->get('/dashboard/stats',         [DashboardController::class, 'stats']);

// ── Assets ────────────────────────────────────────────────────
$router->get( '/asset/list',             [AssetController::class, 'list']);
$router->get( '/asset/view',             [AssetController::class, 'view']);
$router->post('/asset/create',           [AssetController::class, 'create']);
$router->post('/asset/update',           [AssetController::class, 'update']);
$router->post('/asset/transfer',         [AssetController::class, 'transfer']);
$router->post('/asset/status',           [AssetController::class, 'status']);
$router->get( '/asset/history',          [AssetController::class, 'history']);
$router->get( '/asset/children',         [AssetController::class, 'children']);

// ── Attachments ───────────────────────────────────────────────
$router->get( '/attachment/list',        [AttachmentController::class, 'list']);
$router->post('/attachment/upload',      [AttachmentController::class, 'upload']);
$router->post('/attachment/delete',      [AttachmentController::class, 'delete']);
$router->get( '/attachment/download',    [AttachmentController::class, 'download']);

// ── Inventory Items ───────────────────────────────────────────
$router->get( '/item/list',              [InventoryController::class, 'itemList']);
$router->post('/item/create',            [InventoryController::class, 'itemCreate']);
$router->post('/item/update',            [InventoryController::class, 'itemUpdate']);

// ── Stock Transactions ────────────────────────────────────────
$router->get( '/stock/list',             [InventoryController::class, 'stockList']);
$router->post('/stock/receive',          [InventoryController::class, 'stockReceive']);
$router->post('/stock/issue',            [InventoryController::class, 'stockIssue']);
$router->post('/stock/transfer',         [InventoryController::class, 'stockTransfer']);
$router->post('/stock/adjust',           [InventoryController::class, 'stockAdjust']);
$router->get( '/stock/low',              [InventoryController::class, 'stockLow']);
$router->get( '/stock/transactions',     [InventoryController::class, 'stockTransactions']);

// ── Lookup / Reference Data (shared by all modules) ───────────
$router->get( '/lookup/list',            [LookupController::class, 'list']);
$router->get( '/lookup/view',            [LookupController::class, 'view']);
$router->post('/lookup/create',          [LookupController::class, 'create']);
$router->post('/lookup/update',          [LookupController::class, 'update']);
$router->post('/lookup/delete',          [LookupController::class, 'delete']);

return $router;
