<?php
// app/core/BaseRepository.php

namespace App\Core;

use PDO;

/**
 * BaseRepository — shared DB helpers.
 * All concrete repositories extend this.
 * Contains ONLY query execution logic.
 */
abstract class BaseRepository
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Generic finders ─────────────────────────────────────

    /**
     * Find a single record by primary key.
     */
    protected function findById(string $table, int $id, string $pk = 'id'): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE `{$pk}` = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Generic paginated query.
     */
    protected function paginate(
        string $sql,
        string $countSql,
        array  $params  = [],
        int    $page    = 1,
        int    $perPage = 20
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $cnt = $this->db->prepare($countSql);
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();

        $stmt = $this->db->prepare($sql . " LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        return [
            'items'       => $stmt->fetchAll(),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Simple insert — returns last insert ID.
     */
    protected function insert(string $table, array $data): int
    {
        $cols   = array_keys($data);
        $placeh = array_map(fn($c) => ":{$c}", $cols);
        $sql    = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeh) . ")";
        $stmt   = $this->db->prepare($sql);
        foreach ($data as $col => $val) {
            $stmt->bindValue(":{$col}", $val);
        }
        $stmt->execute();
        return (int) $this->db->lastInsertId();
    }

    /**
     * Dynamic update — only updates provided columns.
     */
    protected function update(string $table, int $id, array $data, string $pk = 'id'): bool
    {
        if (empty($data)) return false;
        $sets   = array_map(fn($c) => "`{$c}` = :{$c}", array_keys($data));
        $sql    = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$pk}` = :__pk";
        $stmt   = $this->db->prepare($sql);
        foreach ($data as $col => $val) {
            $stmt->bindValue(":{$col}", $val);
        }
        $stmt->bindValue(':__pk', $id);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Soft delete (set is_active = 0).
     */
    protected function softDelete(string $table, int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE `{$table}` SET `is_active` = 0 WHERE `id` = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Begin / commit / rollback wrappers.
     */
    protected function beginTransaction(): void   { $this->db->beginTransaction(); }
    protected function commit(): void             { $this->db->commit(); }
    protected function rollback(): void           { if ($this->db->inTransaction()) $this->db->rollBack(); }
}
