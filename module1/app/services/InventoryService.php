<?php
// app/services/InventoryService.php

namespace App\Services;

use App\Core\Validator;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Repositories\InventoryRepository;

/**
 * InventoryService — ALL business logic for stock items and transactions.
 * Reusable by Module 3 (parts from WO) and Module 4 (GRN from PO).
 */
class InventoryService
{
    private InventoryRepository $repo;

    public function __construct()
    {
        $this->repo = new InventoryRepository();
    }

    // ══════════════════════════════════════════════════════════
    //  ITEMS
    // ══════════════════════════════════════════════════════════

    public function listItems(array $filters, int $page, int $perPage): array
    {
        return $this->repo->findAllItems($filters, $page, $perPage);
    }

    public function getItem(int $id): array
    {
        $item = $this->repo->findItemById($id);
        if (!$item) throw new \RuntimeException('Item not found.', 404);
        return $item;
    }

    public function createItem(array $input): array
    {
        $v = Validator::make($input)
            ->required('item_code', 'Item Code')
            ->required('name',      'Name')
            ->maxLength('item_code', 100, 'Item Code');

        if ($v->fails()) throw new \InvalidArgumentException($v->firstError());

        if ($this->repo->itemCodeExists($v->get('item_code'))) {
            throw new \InvalidArgumentException("Item code '{$v->get('item_code')}' already exists.");
        }

        $id = $this->repo->createItem([
            'item_code'        => $v->get('item_code'),
            'name'             => $v->get('name'),
            'description'      => $v->get('description'),
            'category_id'      => $v->get('category_id') ? (int) $v->get('category_id') : null,
            'unit_of_measure'  => $v->get('unit_of_measure', 'pcs'),
            'compatible_models'=> $v->get('compatible_models'),
            'has_expiry'       => (int) $v->get('has_expiry', 0),
            'unit_cost'        => $v->get('unit_cost') ? (float) $v->get('unit_cost') : null,
        ]);

        // Optional: set initial stock level
        if ($v->get('location_id') && $v->get('quantity_on_hand') !== null) {
            $this->repo->upsertStockLevelConfig((int) $v->get('location_id'), $id, [
                'quantity_on_hand' => (float) $v->get('quantity_on_hand', 0),
                'min_level'        => (float) $v->get('min_level', 0),
                'max_level'        => $v->get('max_level') ? (float) $v->get('max_level') : null,
                'reorder_point'    => $v->get('reorder_point') ? (float) $v->get('reorder_point') : null,
                'lead_time_days'   => $v->get('lead_time_days') ? (int) $v->get('lead_time_days') : null,
            ]);
        }

        return ['id' => $id];
    }

    public function updateItem(int $id, array $input): array
    {
        $item = $this->repo->findItemById($id);
        if (!$item) throw new \RuntimeException('Item not found.', 404);

        $allowed = ['name','description','category_id','unit_of_measure','compatible_models','has_expiry','unit_cost'];
        $data    = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $input)) {
                $data[$col] = $input[$col] === '' ? null : $input[$col];
            }
        }

        $this->repo->updateItem($id, $data);
        return ['id' => $id];
    }

    // ══════════════════════════════════════════════════════════
    //  STOCK TRANSACTIONS
    // ══════════════════════════════════════════════════════════

    /**
     * Receive stock (GRN) — increases stock.
     */
    public function receiveStock(array $input, int $performedBy): void
    {
        $v = Validator::make($input)
            ->required('item_id',     'Item')
            ->required('location_id', 'Location')
            ->required('quantity',    'Quantity')
            ->positive('quantity',    'Quantity');

        if ($v->fails()) throw new \InvalidArgumentException($v->firstError());

        $itemId  = (int) $v->get('item_id');
        $locId   = (int) $v->get('location_id');
        $qty     = (float) $v->get('quantity');

        $this->repo->beginTransaction();
        try {
            $this->repo->recordTransaction([
                'item_id'          => $itemId,
                'transaction_type' => StockTransaction::TYPE_GRN,
                'quantity'         => $qty,
                'to_location_id'   => $locId,
                'reference_type'   => $v->get('reference_type', 'Manual'),
                'reference_id'     => $v->get('reference_id') ? (int) $v->get('reference_id') : null,
                'reason_code'      => StockTransaction::REASON_NORMAL,
                'notes'            => $v->get('notes'),
                'performed_by'     => $performedBy,
            ]);
            $this->repo->adjustStockLevel($itemId, $locId, $qty);
            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    /**
     * Issue stock — decreases stock; enforces no negative.
     */
    public function issueStock(array $input, int $performedBy): void
    {
        $v = Validator::make($input)
            ->required('item_id',     'Item')
            ->required('location_id', 'Location')
            ->required('quantity',    'Quantity')
            ->positive('quantity',    'Quantity');

        if ($v->fails()) throw new \InvalidArgumentException($v->firstError());

        $itemId = (int) $v->get('item_id');
        $locId  = (int) $v->get('location_id');
        $qty    = (float) $v->get('quantity');

        // Validate availability
        $available = $this->validateStock($itemId, $locId, $qty);

        $this->repo->beginTransaction();
        try {
            $this->repo->recordTransaction([
                'item_id'          => $itemId,
                'transaction_type' => StockTransaction::TYPE_ISSUE,
                'quantity'         => -$qty,
                'from_location_id' => $locId,
                'reference_type'   => $v->get('reference_type'),
                'reference_id'     => $v->get('reference_id') ? (int) $v->get('reference_id') : null,
                'reason_code'      => $v->get('reason_code', StockTransaction::REASON_NORMAL),
                'notes'            => $v->get('notes'),
                'performed_by'     => $performedBy,
            ]);
            $this->repo->adjustStockLevel($itemId, $locId, -$qty);
            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    /**
     * Transfer stock between locations.
     */
    public function transferStock(array $input, int $performedBy): void
    {
        $v = Validator::make($input)
            ->required('item_id',          'Item')
            ->required('from_location_id', 'From Location')
            ->required('to_location_id',   'To Location')
            ->required('quantity',         'Quantity')
            ->positive('quantity',         'Quantity');

        if ($v->fails()) throw new \InvalidArgumentException($v->firstError());

        $itemId    = (int) $v->get('item_id');
        $fromLocId = (int) $v->get('from_location_id');
        $toLocId   = (int) $v->get('to_location_id');
        $qty       = (float) $v->get('quantity');

        if ($fromLocId === $toLocId) {
            throw new \InvalidArgumentException('Source and destination locations must differ.');
        }

        $this->validateStock($itemId, $fromLocId, $qty);

        $this->repo->beginTransaction();
        try {
            $this->repo->recordTransaction([
                'item_id'          => $itemId,
                'transaction_type' => StockTransaction::TYPE_TRANSFER,
                'quantity'         => $qty,
                'from_location_id' => $fromLocId,
                'to_location_id'   => $toLocId,
                'reason_code'      => $v->get('reason_code', StockTransaction::REASON_NORMAL),
                'notes'            => $v->get('notes'),
                'performed_by'     => $performedBy,
            ]);
            $this->repo->adjustStockLevel($itemId, $fromLocId, -$qty);
            $this->repo->adjustStockLevel($itemId, $toLocId,    $qty);
            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    /**
     * Manual stock adjustment with reason code.
     */
    public function adjustStock(array $input, int $performedBy): void
    {
        $v = Validator::make($input)
            ->required('item_id',     'Item')
            ->required('location_id', 'Location')
            ->required('new_quantity','New Quantity');

        if ($v->fails()) throw new \InvalidArgumentException($v->firstError());

        $itemId  = (int) $v->get('item_id');
        $locId   = (int) $v->get('location_id');
        $newQty  = (float) $v->get('new_quantity');
        $current = $this->repo->getStockQty($itemId, $locId);
        $delta   = $newQty - $current;

        $this->repo->beginTransaction();
        try {
            $this->repo->recordTransaction([
                'item_id'          => $itemId,
                'transaction_type' => StockTransaction::TYPE_ADJUST,
                'quantity'         => $delta,
                'to_location_id'   => $locId,
                'reason_code'      => $v->get('reason_code', StockTransaction::REASON_AUDIT),
                'notes'            => $v->get('notes'),
                'performed_by'     => $performedBy,
            ]);
            $this->repo->setStockLevel($itemId, $locId, $newQty);
            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    /**
     * Validate sufficient stock — throws if insufficient.
     * Returns available quantity.
     * Reusable by Module 3 (WO parts consumption).
     */
    public function validateStock(int $itemId, int $locationId, float $requiredQty): float
    {
        $available = $this->repo->getStockQty($itemId, $locationId);
        if ($available < $requiredQty) {
            throw new \RuntimeException(
                "Insufficient stock. Available: {$available}, Requested: {$requiredQty}.",
                422
            );
        }
        return $available;
    }

    // ── TRANSACTIONS LIST ─────────────────────────────────────

    public function listTransactions(array $filters, int $page, int $perPage): array
    {
        return $this->repo->findTransactions($filters, $page, $perPage);
    }

    // ── LOW STOCK ─────────────────────────────────────────────

    public function getLowStockItems(): array
    {
        return $this->repo->getLowStockItems();
    }

    // ── DASHBOARD ─────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        return [
            'total_skus'       => $this->repo->countActiveItems(),
            'total_value'      => $this->repo->totalInventoryValue(),
            'low_stock_count'  => count($this->repo->getLowStockItems()),
            'stockout_count'   => count(array_filter(
                $this->repo->getLowStockItems(),
                fn($i) => (float) $i['quantity_on_hand'] <= 0
            )),
        ];
    }
}
