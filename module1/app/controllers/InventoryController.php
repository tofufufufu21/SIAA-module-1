<?php
// app/controllers/InventoryController.php

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\InventoryService;

/**
 * InventoryController — THIN.
 * Parse request → call InventoryService → return JSON.
 */
class InventoryController extends BaseController
{
    private InventoryService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new InventoryService();
    }

    // GET /item/list
    public function itemList(): never
    {
        $this->requireMethod('GET');
        $filters = [
            'search'      => $this->getQuery('search', ''),
            'category_id' => $this->getQuery('category_id', ''),
        ];
        $page    = (int) $this->getQuery('page', 1);
        $perPage = (int) $this->getQuery('per_page', 15);
        $this->success($this->service->listItems($filters, $page, $perPage));
    }

    // POST /item/create
    public function itemCreate(): never
    {
        $this->requireMethod('POST');
        try {
            $this->created($this->service->createItem($this->getBody()), 'Item created.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->error('Server error: ' . $e->getMessage(), 500);
        }
    }

    // POST /item/update
    public function itemUpdate(): never
    {
        $this->requireMethod('POST');
        $body = $this->getBody();
        $id   = (int) ($body['id'] ?? 0);
        if (!$id) $this->error('Item ID required.');
        try {
            $this->success($this->service->updateItem($id, $body), 'Item updated.');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 404);
        }
    }

    // GET /stock/list
    public function stockList(): never
    {
        $this->requireMethod('GET');
        $filters = [
            'search'      => $this->getQuery('search', ''),
            'category_id' => $this->getQuery('category_id', ''),
        ];
        $this->success($this->service->listItems($filters, (int) $this->getQuery('page', 1), 15));
    }

    // POST /stock/receive
    public function stockReceive(): never
    {
        $this->requireMethod('POST');
        try {
            $this->service->receiveStock($this->getBody(), $this->currentUserId());
            $this->success(null, 'Stock received (GRN recorded).');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    // POST /stock/issue
    public function stockIssue(): never
    {
        $this->requireMethod('POST');
        try {
            $this->service->issueStock($this->getBody(), $this->currentUserId());
            $this->success(null, 'Stock issued.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    // POST /stock/transfer
    public function stockTransfer(): never
    {
        $this->requireMethod('POST');
        try {
            $this->service->transferStock($this->getBody(), $this->currentUserId());
            $this->success(null, 'Stock transferred.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    // POST /stock/adjust
    public function stockAdjust(): never
    {
        $this->requireMethod('POST');
        try {
            $this->service->adjustStock($this->getBody(), $this->currentUserId());
            $this->success(null, 'Stock adjusted.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    // GET /stock/low
    public function stockLow(): never
    {
        $this->requireMethod('GET');
        $this->success($this->service->getLowStockItems());
    }

    // GET /stock/transactions
    public function stockTransactions(): never
    {
        $this->requireMethod('GET');
        $filters = [
            'item_id' => $this->getQuery('item_id', ''),
            'type'    => $this->getQuery('type', ''),
        ];
        $this->success($this->service->listTransactions($filters, (int) $this->getQuery('page', 1), 20));
    }
}
