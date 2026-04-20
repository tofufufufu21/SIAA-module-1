<?php
// app/controllers/DashboardController.php

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\AssetService;
use App\Services\InventoryService;

/**
 * DashboardController — aggregates Module 1 summary stats.
 */
class DashboardController extends BaseController
{
    private AssetService     $assetService;
    private InventoryService $inventoryService;

    public function __construct()
    {
        parent::__construct();
        $this->assetService     = new AssetService();
        $this->inventoryService = new InventoryService();
    }

    // GET /dashboard/stats
    public function stats(): never
    {
        $this->requireMethod('GET');

        $assetStats = $this->assetService->getDashboardStats();
        $stockStats = $this->inventoryService->getDashboardStats();

        $this->success(array_merge($assetStats, $stockStats));
    }
}
