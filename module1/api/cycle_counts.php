<?php
// ============================================================
//  api/cycle_counts.php
//  Module 1 — Physical Inventory / Cycle Count API
//  Routes:
//    GET  /api/cycle_counts.php            → list plans
//    GET  /api/cycle_counts.php?id=X       → plan + entries
//    POST /api/cycle_counts.php            → create plan
//    POST /api/cycle_counts.php?action=start&id=X   → start count
//    POST /api/cycle_counts.php?action=entry        → submit count entry
//    POST /api/cycle_counts.php?action=approve&id=X → approve variance
//    POST /api/cycle_counts.php?action=reconcile&id=X → reconcile & apply
// ============================================================

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/stock_transactions.php';  // reuse adjust_stock & record_transaction

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

match (true) {
    $action === 'start'      => handle_start($pdo, $id),
    $action === 'entry'      => handle_entry($pdo),
    $action === 'approve'    => handle_approve_entry($pdo),
    $action === 'reconcile'  => handle_reconcile($pdo, $id),
    $method === 'GET' && $id => get_plan($pdo, $id),
    $method === 'GET'        => list_plans($pdo),
    $method === 'POST'       => create_plan($pdo),
    default                  => respond_error('Invalid route.', 404),
};

function list_plans(PDO $pdo): never {
    allow_methods('GET');
    $page    = (int) ($_GET['page']     ?? 1);
    $perPage = (int) ($_GET['per_page'] ?? 20);
    $status  = $_GET['status'] ?? '';

    $where  = ['1=1'];
    $params = [];
    if ($status !== '') {
        $where[]          = 'p.status = :status';
        $params[':status'] = $status;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);
    $countSql    = "SELECT COUNT(*) FROM cycle_count_plans p {$whereClause}";
    $dataSql     = "
        SELECT p.*, l.name AS location_name, u.full_name AS created_by_name
        FROM cycle_count_plans p
        LEFT JOIN locations l ON l.id = p.location_id
        LEFT JOIN users     u ON u.id = p.created_by
        {$whereClause}
        ORDER BY p.planned_date DESC
        LIMIT :limit OFFSET :offset
    ";
    respond_ok(paginate($pdo, $countSql, $dataSql, $params, $page, $perPage));
}

function get_plan(PDO $pdo, int $id): never {
    allow_methods('GET');

    $stmt = $pdo->prepare("SELECT * FROM cycle_count_plans WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $plan = $stmt->fetch();
    if (!$plan) respond_error('Plan not found.', 404);

    $entries = $pdo->prepare("
        SELECT e.*, si.item_code, si.name AS item_name, si.unit_of_measure,
               l.name AS location_name, u.full_name AS counted_by_name
        FROM cycle_count_entries e
        JOIN  stock_items si ON si.id = e.item_id
        JOIN  locations   l  ON l.id  = e.location_id
        LEFT JOIN users   u  ON u.id  = e.counted_by
        WHERE e.plan_id = :id
    ");
    $entries->execute([':id' => $id]);
    $plan['entries'] = $entries->fetchAll();

    respond_ok($plan);
}

function create_plan(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $name        = require_field($body, 'name',         'Name');
    $plannedDate = require_field($body, 'planned_date', 'Planned Date');
    $createdBy   = (int) require_field($body, 'created_by', 'created_by');
    validate_date($plannedDate, 'planned_date');

    $pdo->prepare("
        INSERT INTO cycle_count_plans (name, location_id, planned_date, notes, created_by)
        VALUES (:name, :loc, :date, :notes, :by)
    ")->execute([
        ':name'  => $name,
        ':loc'   => optional($body, 'location_id'),
        ':date'  => $plannedDate,
        ':notes' => optional($body, 'notes'),
        ':by'    => $createdBy,
    ]);

    $planId = (int) $pdo->lastInsertId();

    // Auto-populate entries from current stock levels
    if (isset($body['location_id'])) {
        $levels = $pdo->prepare("
            SELECT item_id, location_id, quantity_on_hand
            FROM stock_levels WHERE location_id = :loc
        ");
        $levels->execute([':loc' => $body['location_id']]);

        $ins = $pdo->prepare("
            INSERT INTO cycle_count_entries (plan_id, item_id, location_id, system_qty)
            VALUES (:plan, :item, :loc, :qty)
        ");
        foreach ($levels->fetchAll() as $row) {
            $ins->execute([
                ':plan' => $planId,
                ':item' => $row['item_id'],
                ':loc'  => $row['location_id'],
                ':qty'  => $row['quantity_on_hand'],
            ]);
        }
    }

    respond_ok(['id' => $planId], 'Cycle count plan created.', 201);
}

function handle_start(PDO $pdo, ?int $id): never {
    allow_methods('POST');
    if (!$id) respond_error('Plan id required.');

    $pdo->prepare("UPDATE cycle_count_plans SET status = 'In Progress' WHERE id = :id AND status = 'Draft'")
        ->execute([':id' => $id]);

    respond_ok(null, 'Count started.');
}

function handle_entry(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $entryId   = (int) require_field($body, 'entry_id',   'entry_id');
    $countedQty= (float) require_field($body, 'counted_qty', 'counted_qty');
    $countedBy = (int) require_field($body, 'counted_by', 'counted_by');

    $pdo->prepare("
        UPDATE cycle_count_entries
        SET counted_qty = :qty, counted_by = :by, counted_at = NOW(), status = 'Counted',
            variance_reason = :reason
        WHERE id = :id
    ")->execute([
        ':qty'    => $countedQty,
        ':by'     => $countedBy,
        ':reason' => optional($body, 'variance_reason'),
        ':id'     => $entryId,
    ]);

    respond_ok(null, 'Count entry saved.');
}

function handle_approve_entry(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $entryId    = (int) require_field($body, 'entry_id',   'entry_id');
    $approvedBy = (int) require_field($body, 'approved_by','approved_by');
    $status     = require_field($body, 'status', 'status');   // Approved or Rejected
    validate_enum($status, ['Approved','Rejected'], 'status');

    $pdo->prepare("
        UPDATE cycle_count_entries SET status = :status, approved_by = :by, approved_at = NOW()
        WHERE id = :id
    ")->execute([':status' => $status, ':by' => $approvedBy, ':id' => $entryId]);

    respond_ok(null, "Entry {$status}.");
}

function handle_reconcile(PDO $pdo, ?int $id): never {
    allow_methods('POST');
    if (!$id) respond_error('Plan id required.');
    $body = get_body();
    $performedBy = (int) require_field($body, 'performed_by', 'performed_by');

    // Fetch approved entries with variance
    $entries = $pdo->prepare("
        SELECT * FROM cycle_count_entries
        WHERE plan_id = :id AND status = 'Approved' AND variance != 0
    ");
    $entries->execute([':id' => $id]);
    $rows = $entries->fetchAll();

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            // Apply adjustment transaction
            record_transaction($pdo, [
                'item_id'          => $row['item_id'],
                'transaction_type' => 'CycleCountAdj',
                'quantity'         => $row['variance'],
                'to_location_id'   => $row['location_id'],
                'reason_code'      => 'AuditCorrection',
                'notes'            => "Cycle count plan #{$id}: " . ($row['variance_reason'] ?? ''),
                'performed_by'     => $performedBy,
            ]);

            // Update stock level
            $pdo->prepare("
                UPDATE stock_levels SET quantity_on_hand = quantity_on_hand + :v
                WHERE item_id = :item AND location_id = :loc
            ")->execute([
                ':v'    => $row['variance'],
                ':item' => $row['item_id'],
                ':loc'  => $row['location_id'],
            ]);
        }

        $pdo->prepare("UPDATE cycle_count_plans SET status = 'Approved' WHERE id = :id")
            ->execute([':id' => $id]);

        $pdo->commit();
        respond_ok(['adjusted_entries' => count($rows)], 'Reconciliation complete. Stock levels updated.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond_error('Reconciliation failed: ' . $e->getMessage(), 500);
    }
}
