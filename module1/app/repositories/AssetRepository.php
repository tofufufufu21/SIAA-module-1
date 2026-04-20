<?php
// app/repositories/AssetRepository.php

namespace App\Repositories;

use App\Core\BaseRepository;
use PDO;

/**
 * AssetRepository — ALL database logic for assets.
 * Business logic must stay in AssetService.
 */
class AssetRepository extends BaseRepository
{
    // ── SELECT with full joins ───────────────────────────────

    private function selectSql(): string
    {
        return "
            SELECT
                a.*,
                c.name                            AS category_name,
                v.name                            AS vendor_name,
                u.full_name                       AS assigned_user_name,
                d.name                            AS department_name,
                l.name                            AS location_name,
                CONCAT(s.name, ' › ', l.name)    AS location_full,
                pa.asset_tag                      AS parent_asset_tag
            FROM assets a
            LEFT JOIN categories  c  ON c.id = a.category_id
            LEFT JOIN vendors     v  ON v.id = a.vendor_id
            LEFT JOIN users       u  ON u.id = a.assigned_user_id
            LEFT JOIN departments d  ON d.id = a.department_id
            LEFT JOIN locations   l  ON l.id = a.location_id
            LEFT JOIN sites       s  ON s.id = l.site_id
            LEFT JOIN assets      pa ON pa.id = a.parent_asset_id
        ";
    }

    // ── LIST (paginated, filterable) ─────────────────────────

    public function findAll(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $where  = ['a.is_active = 1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]            = '(a.asset_tag LIKE :search OR a.serial_number LIKE :search OR a.make LIKE :search OR a.model LIKE :search)';
            $params[':search']  = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[]            = 'a.status = :status';
            $params[':status']  = $filters['status'];
        }
        if (!empty($filters['category_id'])) {
            $where[]            = 'a.category_id = :cat';
            $params[':cat']     = (int) $filters['category_id'];
        }
        if (!empty($filters['department_id'])) {
            $where[]            = 'a.department_id = :dept';
            $params[':dept']    = (int) $filters['department_id'];
        }
        if (!empty($filters['location_id'])) {
            $where[]            = 'a.location_id = :loc';
            $params[':loc']     = (int) $filters['location_id'];
        }

        $wc       = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM assets a {$wc}";
        $dataSql  = $this->selectSql() . " {$wc} ORDER BY a.created_at DESC";

        return $this->paginate($dataSql, $countSql, $params, $page, $perPage);
    }

    // ── SINGLE ASSET ─────────────────────────────────────────

    public function findById(string $table, int $id, string $pk = 'id'): ?array
    {
        $stmt = $this->db->prepare($this->selectSql() . ' WHERE a.id = :id AND a.is_active = 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByIdRaw(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM assets WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function existsByTag(string $tag, ?int $excludeId = null): bool
    {
        $sql    = "SELECT id FROM assets WHERE asset_tag = :tag AND is_active = 1";
        $params = [':tag' => $tag];
        if ($excludeId) {
            $sql .= " AND id != :eid";
            $params[':eid'] = $excludeId;
        }
        return (bool) $this->db->prepare($sql)->execute($params) && $this->db->query("SELECT FOUND_ROWS()")->fetchColumn() > 0
            ? (bool) $this->db->prepare($sql)->execute($params)
            : false;
    }

    public function tagExists(string $tag, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM assets WHERE asset_tag = :tag AND is_active = 1";
        $params = [':tag' => $tag];
        if ($excludeId) { $sql .= " AND id != :eid"; $params[':eid'] = $excludeId; }
        $stmt   = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ── CREATE ───────────────────────────────────────────────

    public function create(array $data): int
    {
        return $this->insert('assets', $data);
    }

    // ── UPDATE ───────────────────────────────────────────────

    public function updateById(int $id, array $data): bool
    {
        return $this->update('assets', $id, $data);
    }

    // ── SOFT DELETE ──────────────────────────────────────────

    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE assets SET is_active = 0, status = 'Retired' WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── LIFECYCLE LOG ─────────────────────────────────────────

    public function logLifecycle(array $data): int
    {
        return $this->insert('asset_lifecycle_log', $data);
    }

    public function getLifecycleLog(int $assetId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT ll.*,
                   fu.full_name AS from_user_name,
                   tu.full_name AS to_user_name,
                   pb.full_name AS performed_by_name,
                   ab.full_name AS approved_by_name
            FROM asset_lifecycle_log ll
            LEFT JOIN users fu ON fu.id = ll.from_user_id
            LEFT JOIN users tu ON tu.id = ll.to_user_id
            LEFT JOIN users pb ON pb.id = ll.performed_by
            LEFT JOIN users ab ON ab.id = ll.approved_by
            WHERE ll.asset_id = :id
            ORDER BY ll.performed_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':id',  $assetId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,   PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── CHECKOUT ──────────────────────────────────────────────

    public function getOpenCheckout(int $assetId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM asset_checkouts WHERE asset_id = :id AND checked_in_at IS NULL LIMIT 1");
        $stmt->execute([':id' => $assetId]);
        return $stmt->fetch() ?: null;
    }

    public function createCheckout(array $data): int
    {
        return $this->insert('asset_checkouts', $data);
    }

    public function closeCheckout(int $checkoutId, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE asset_checkouts SET checked_in_at = NOW(), checked_in_by = :uid WHERE id = :id");
        $stmt->execute([':uid' => $userId, ':id' => $checkoutId]);
        return $stmt->rowCount() > 0;
    }

    // ── ATTACHMENTS ───────────────────────────────────────────

    public function getAttachments(int $assetId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name AS uploaded_by_name
            FROM asset_attachments a
            LEFT JOIN users u ON u.id = a.uploaded_by
            WHERE a.asset_id = :id ORDER BY a.uploaded_at DESC
        ");
        $stmt->execute([':id' => $assetId]);
        return $stmt->fetchAll();
    }

    public function createAttachment(array $data): int
    {
        return $this->insert('asset_attachments', $data);
    }

    public function getAttachmentById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM asset_attachments WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function deleteAttachment(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM asset_attachments WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── COUNTS for dashboard ──────────────────────────────────

    public function countByStatus(): array
    {
        $stmt = $this->db->query("
            SELECT status, COUNT(*) as count
            FROM assets WHERE is_active = 1
            GROUP BY status
        ");
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $r) { $result[$r['status']] = (int) $r['count']; }
        return $result;
    }

    // ── CHILDREN ──────────────────────────────────────────────

    public function findChildren(int $parentId): array
    {
        $stmt = $this->db->prepare($this->selectSql() . ' WHERE a.parent_asset_id = :id AND a.is_active = 1');
        $stmt->execute([':id' => $parentId]);
        return $stmt->fetchAll();
    }
}
