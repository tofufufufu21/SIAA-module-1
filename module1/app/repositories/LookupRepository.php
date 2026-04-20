<?php
// app/repositories/LookupRepository.php

namespace App\Repositories;

use App\Core\BaseRepository;
use PDO;

/**
 * LookupRepository — shared reference data for ALL modules.
 * categories, departments, sites, locations, vendors, users
 */
class LookupRepository extends BaseRepository
{
    public function getAll(string $resource, array $extra = []): array
    {
        $allowed = ['categories','departments','sites','locations','vendors','users'];
        if (!in_array($resource, $allowed, true)) return [];

        if ($resource === 'users') {
            $search = $extra['search'] ?? '';
            $sql    = "SELECT u.*, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.is_active = 1";
            if ($search) {
                $sql  .= " AND (u.full_name LIKE :s OR u.email LIKE :s OR u.employee_id LIKE :s)";
                $stmt  = $this->db->prepare($sql . " ORDER BY u.full_name LIMIT 200");
                $stmt->execute([':s' => "%{$search}%"]);
                return $stmt->fetchAll();
            }
            return $this->db->query($sql . " ORDER BY full_name LIMIT 200")->fetchAll();
        }

        if ($resource === 'locations') {
            $siteId = $extra['site_id'] ?? null;
            if ($siteId) {
                $stmt = $this->db->prepare("SELECT l.*, s.name AS site_name FROM locations l JOIN sites s ON s.id = l.site_id WHERE l.site_id = :sid AND l.is_active = 1 ORDER BY l.name");
                $stmt->execute([':sid' => (int) $siteId]);
                return $stmt->fetchAll();
            }
            return $this->db->query("SELECT l.*, s.name AS site_name FROM locations l LEFT JOIN sites s ON s.id = l.site_id WHERE l.is_active = 1 ORDER BY l.name")->fetchAll();
        }

        return $this->db->query("SELECT * FROM `{$resource}` WHERE is_active = 1 ORDER BY name")->fetchAll();
    }

    public function findOne(string $resource, int $id): ?array
    {
        $allowed = ['categories','departments','sites','locations','vendors','users'];
        if (!in_array($resource, $allowed, true)) return null;
        $stmt = $this->db->prepare("SELECT * FROM `{$resource}` WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function createRecord(string $resource, array $data): int
    {
        return $this->insert($resource, $data);
    }

    public function updateRecord(string $resource, int $id, array $data): bool
    {
        return $this->update($resource, $id, $data);
    }

    public function deactivateRecord(string $resource, int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE `{$resource}` SET is_active = 0 WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
