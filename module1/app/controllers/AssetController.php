<?php
// app/controllers/AssetController.php

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\AssetService;

/**
 * AssetController — THIN.
 * Parse request → call AssetService → return JSON.
 * Zero business logic here.
 */
class AssetController extends BaseController
{
    private AssetService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new AssetService();
    }

    // GET /asset/list
    public function list(): never
    {
        $this->requireMethod('GET');
        $filters = [
            'search'      => $this->getQuery('search', ''),
            'status'      => $this->getQuery('status', ''),
            'category_id' => $this->getQuery('category_id', ''),
            'department_id'=> $this->getQuery('department_id', ''),
            'location_id' => $this->getQuery('location_id', ''),
        ];
        $page    = (int) $this->getQuery('page', 1);
        $perPage = (int) $this->getQuery('per_page', 15);

        $this->success($this->service->listAssets($filters, $page, $perPage));
    }

    // GET /asset/view?id=X
    public function view(): never
    {
        $this->requireMethod('GET');
        $id = (int) $this->getQuery('id');
        if (!$id) $this->error('Asset ID is required.');
        try {
            $this->success($this->service->getAsset($id));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    // POST /asset/create
    public function create(): never
    {
        $this->requireMethod('POST');
        try {
            $result = $this->service->createAsset($this->getBody(), $this->currentUserId());
            $this->created($result, 'Asset created successfully.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->error('Server error: ' . $e->getMessage(), 500);
        }
    }

    // POST /asset/update
    public function update(): never
    {
        $this->requireMethod('POST');
        $body = $this->getBody();
        $id   = (int) ($body['id'] ?? 0);
        if (!$id) $this->error('Asset ID is required.');
        try {
            $result = $this->service->updateAsset($id, $body, $this->currentUserId());
            $this->success($result, 'Asset updated.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // POST /asset/transfer
    public function transfer(): never
    {
        $this->requireMethod('POST');
        try {
            $this->service->transferAsset($this->getBody(), $this->currentUserId());
            $this->success(null, 'Asset transferred successfully.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // POST /asset/status
    public function status(): never
    {
        $this->requireMethod('POST');
        $body    = $this->getBody();
        $id      = (int) ($body['asset_id'] ?? 0);
        $status  = $body['status']  ?? '';
        $reason  = $body['reason']  ?? '';
        if (!$id || !$status) $this->error('asset_id and status are required.');
        try {
            $this->service->changeStatus($id, $status, $reason, $this->currentUserId());
            $this->success(null, 'Status updated.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // GET /asset/history?id=X
    public function history(): never
    {
        $this->requireMethod('GET');
        $id = (int) $this->getQuery('id');
        if (!$id) $this->error('Asset ID required.');
        try {
            $asset = $this->service->getAsset($id);
            $this->success($asset['lifecycle_log']);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 404);
        }
    }

    // GET /asset/children?id=X
    public function children(): never
    {
        $this->requireMethod('GET');
        $id = (int) $this->getQuery('id');
        if (!$id) $this->error('Asset ID required.');
        try {
            $asset = $this->service->getAsset($id);
            $this->success($asset['children']);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 404);
        }
    }
}
