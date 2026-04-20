<?php
// app/repositories/InventoryRepository.php

namespace App\Repositories;

use App\Core\BaseRepository;
use PDO;

/**
 * InventoryRepository — ALL database logic for stock items, levels, transactions.
 * Reused by Module 3 (parts) and Module 4 (procurement GRN).
 */
class InventoryRepository extends BaseRepository
{
    // ══════════════════════════════════════════════════════════
    //  STOCK ITEMS
    // ══════════════════════════════════════════════════════════

    public function findAllItems(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $where  = ['si.is_active = 1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]            = '(si.item_code LIKE :search OR si.name LIKE :search)';
            $params[':search']  = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[]            = 'si.category_id = :cat';
            $params[':cat']     = (int) $filters['category_id'];
        }

        $wc       = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM stock_items si {$wc}";
        $dataSql  = "
            SELECT si.*,
                   c.name AS category_name,
                   COALESCE(SUM(sl.quantity_on_hand), 0) AS total_qty_on_hand
            FROM stock_items si
            LEFT JOIN categories  c  ON c.id  = si.category_id
            LEFT JOIN stock_levels sl ON sl.item_id = si.id
            {$wc}
            GROUP BY si.id
            ORDER BY si.name ASC
        ";

        return $this->paginate($dataSql, $countSql, $params, $page, $perPage);
    }

    public function findItemById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT si.*, c.name AS category_name
            FROM stock_items si
            LEFT JOIN categories c ON c.id = si.category_id
            WHERE si.id = :id AND si.is_active = 1
        ");
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();
        if (!$item) return null;

        // Attach stock levels
        $stmt2 = $this->db->prepare("
            SELECT sl.*, l.name AS location_name, s.name AS site_name
            FROM stock_levels sl
            LEFT JOIN locations l ON l.id = sl.location_id
            LEFT JOIN sites     s ON s.id = l.site_id
            WHERE sl.item_id = :id
        ");
        $stmt2->execute([':id' => $id]);
        $item['stock_levels'] = $stmt2->fetchAll();

        return $item;
    }

    public function itemCodeExists(string $code, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM stock_items WHERE item_code = :code AND is_active = 1";
        $params = [':code' => $code];
        if ($excludeId) { $sql .= " AND id != :eid"; $params[':eid'] = $excludeId; }
        $stmt   = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function createItem(array $data): int
    {
        return $this->insert('stock_items', $data);
    }

    public function updateItem(int $id, array $data): bool
    {
        return $this->update('stock_items', $id, $data);
    }

    public function deactivateItem(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE stock_items SET is_active = 0 WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ══════════════════════════════════════════════════════════
    //  STOCK LEVELS
    // ══════════════════════════════════════════════════════════

    public function getStockQty(int $itemId, int $locationId): float
    {
        $stmt = $this->db->prepare("SELECT quantity_on_hand FROM stock_levels WHERE item_id = :item AND location_id = :loc");
        $stmt->execute([':item' => $itemId, ':loc' => $locationId]);
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Upsert stock level — insert or update quantity by delta.
     */
    public function adjustStockLevel(int $itemId, int $locationId, float $delta): void
    {
        $this->db->prepare("
            INSERT INTO stock_levels (item_id, location_id, quantity_on_hand)
            VALUES (:item, :loc, :delta)
            ON DUPLICATE KEY UPDATE quantity_on_hand = quantity_on_hand + :delta
        ")->execute([':item' => $itemId, ':loc' => $locationId, ':delta' => $delta]);
    }

    /**
     * Set stock level to exact quantity (for adjustments).
     */
    public function setStockLevel(int $itemId, int $locationId, float $qty): void
    {
        $this->db->prepare("
            INSERT INTO stock_levels (item_id, location_id, quantity_on_hand)
            VALUES (:item, :loc, :qty)
            ON DUPLICATE KEY UPDATE quantity_on_hand = :qty
        ")->execute([':item' => $itemId, ':loc' => $locationId, ':qty' => $qty]);
    }

    public function upsertStockLevelConfig(int $itemId, int $locationId, array $config): void
    {
        $this->db->prepare("
            INSERT INTO stock_levels (item_id, location_id, quantity_on_hand, min_level, max_level, reorder_point, lead_time_days)
            VALUES (:item, :loc, :qty, :min, :max, :rop, :ltd)
            ON DUPLICATE KEY UPDATE
                min_level     = COALESCE(VALUES(min_level), min_level),
                max_level     = COALESCE(VALUES(max_level), max_level),
                reorder_point = COALESCE(VALUES(reorder_point), reorder_point),
                lead_time_days= COALESCE(VALUES(lead_time_days), lead_time_days)
        ")->execute([
            ':item' => $itemId,
            ':loc'  => $locationId,
            ':qty'  => $config['quantity_on_hand'] ?? 0,
            ':min'  => $config['min_level']        ?? 0,
            ':max'  => $config['max_level']        ?? null,
            ':rop'  => $config['reorder_point']    ?? null,
            ':ltd'  => $config['lead_time_days']   ?? null,
        ]);
    }

    public function getLowStockItems(): array
    {
        $stmt = $this->db->query("
            SELECT si.id, si.item_code, si.name, si.unit_of_measure,
                   sl.location_id, l.name AS location_name,
                   sl.quantity_on_hand, sl.reorder_point, sl.min_level, sl.max_level
            FROM stock_levels sl
            JOIN stock_items  si ON si.id = sl.item_id
            JOIN locations    l  ON l.id  = sl.location_id
            WHERE si.is_active = 1
              AND sl.quantity_on_hand <= COALESCE(sl.reorder_point, sl.min_level)
            ORDER BY (sl.quantity_on_hand - COALESCE(sl.reorder_point, sl.min_level)) ASC
        ");
        return $stmt->fetchAll();
    }

    // ══════════════════════════════════════════════════════════
    //  TRANSACTIONS — reusable by Module 3 & 4
    // ══════════════════════════════════════════════════════════

    /**
     * Record a transaction to the ledger.
     * Called by service layer — do NOT add logic here.
     */
    public function recordTransaction(array $data): int
    {
        return $this->insert('stock_transactions', $data);
    }

    public function findTransactions(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['item_id'])) {
            $where[]             = 'st.item_id = :item_id';
            $params[':item_id']  = (int) $filters['item_id'];
        }
        if (!empty($filters['type'])) {
            $where[]             = 'st.transaction_type = :type';
            $params[':type']     = $filters['type'];
        }

        $wc       = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM stock_transactions st {$wc}";
        $dataSql  = "
            SELECT st.*,
                   si.item_code, si.name AS item_name, si.unit_of_measure,
                   fl.name AS from_location_name,
                   tl.name AS to_location_name,
                   u.full_name AS performed_by_name
            FROM stock_transactions st
            JOIN  stock_items si ON si.id = st.item_id
            LEFT JOIN locations fl ON fl.id = st.from_location_id
            LEFT JOIN locations tl ON tl.id = st.to_location_id
            LEFT JOIN users     u  ON u.id  = st.performed_by
            {$wc}
            ORDER BY st.performed_at DESC
        ";

        return $this->paginate($dataSql, $countSql, $params, $page, $perPage);
    }

    // ══════════════════════════════════════════════════════════
    //  COUNTS for dashboard
    // ══════════════════════════════════════════════════════════

    public function countActiveItems(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM stock_items WHERE is_active = 1")->fetchColumn();
    }

    public function totalInventoryValue(): float
    {
        $v = $this->db->query("
            SELECT COALESCE(SUM(si.unit_cost * sl.quantity_on_hand), 0)
            FROM stock_items si
            JOIN stock_levels sl ON sl.item_id = si.id
            WHERE si.is_active = 1 AND si.unit_cost IS NOT NULL
        ")->fetchColumn();
        return (float) $v;
    }
}
