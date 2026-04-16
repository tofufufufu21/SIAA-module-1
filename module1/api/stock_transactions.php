<?php
// ============================================================
//  api/stock_transactions.php
//  Module 1 — Stock Transaction API
//  Handles: GRN, Issue, Transfer, Adjustment, CycleCountAdj
//  Reused by Module 3 (Parts from WO) and Module 4 (GRN from PO)
//
//  Routes:
//    POST /api/stock_transactions.php?action=grn        → Goods Received
//    POST /api/stock_transactions.php?action=issue      → Issue stock
//    POST /api/stock_transactions.php?action=transfer   → Transfer between locations
//    POST /api/stock_transactions.php?action=adjust     → Manual adjustment
//    GET  /api/stock_transactions.php                   → List all (paginated)
// ============================================================

require_once __DIR__ . '/../includes/api_helpers.php';

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

match (true) {
    $action === 'grn'      => handle_grn($pdo),
    $action === 'issue'    => handle_issue($pdo),
    $action === 'transfer' => handle_transfer($pdo),
    $action === 'adjust'   => handle_adjust($pdo),
    $method === 'GET'      => list_transactions($pdo),
    default                => respond_error('Invalid route.', 404),
};

// ── LIST ─────────────────────────────────────────────────────
function list_transactions(PDO $pdo): never {
    allow_methods('GET');

    $page    = (int) ($_GET['page']     ?? 1);
    $perPage = (int) ($_GET['per_page'] ?? 20);
    $type    = $_GET['type']    ?? '';
    $itemId  = $_GET['item_id'] ?? '';

    $where  = ['1=1'];
    $params = [];

    if ($type !== '') {
        $where[]        = 'st.transaction_type = :type';
        $params[':type']= $type;
    }
    if ($itemId !== '') {
        $where[]         = 'st.item_id = :item_id';
        $params[':item_id'] = (int) $itemId;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);
    $countSql    = "SELECT COUNT(*) FROM stock_transactions st {$whereClause}";
    $dataSql     = "
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
        {$whereClause}
        ORDER BY st.performed_at DESC
        LIMIT :limit OFFSET :offset
    ";

    respond_ok(paginate($pdo, $countSql, $dataSql, $params, $page, $perPage));
}

// ── GRN (Goods Received) ─────────────────────────────────────
function handle_grn(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $itemId      = (int) require_field($body, 'item_id',     'item_id');
    $locId       = (int) require_field($body, 'location_id', 'location_id');
    $qty         = (float) require_field($body, 'quantity',  'quantity');
    $performedBy = (int) require_field($body, 'performed_by','performed_by');

    if ($qty <= 0) respond_error('Quantity must be positive for GRN.');

    $pdo->beginTransaction();
    try {
        record_transaction($pdo, [
            'item_id'          => $itemId,
            'transaction_type' => 'GRN',
            'quantity'         => $qty,
            'to_location_id'   => $locId,
            'batch_id'         => optional($body, 'batch_id'),
            'reference_type'   => optional($body, 'reference_type', 'PO'),
            'reference_id'     => optional($body, 'reference_id'),
            'reason_code'      => 'Normal',
            'notes'            => optional($body, 'notes'),
            'performed_by'     => $performedBy,
        ]);

        adjust_stock($pdo, $itemId, $locId, $qty);

        // Handle batch if provided
        if (isset($body['batch_number'])) {
            create_batch($pdo, $itemId, $locId, $qty, $body);
        }

        $pdo->commit();
        respond_ok(null, "GRN recorded. {$qty} units received.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond_error('GRN failed: ' . $e->getMessage(), 500);
    }
}

// ── ISSUE ─────────────────────────────────────────────────────
function handle_issue(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $itemId      = (int) require_field($body, 'item_id',     'item_id');
    $locId       = (int) require_field($body, 'location_id', 'location_id');
    $qty         = (float) require_field($body, 'quantity',  'quantity');
    $performedBy = (int) require_field($body, 'performed_by','performed_by');

    if ($qty <= 0) respond_error('Quantity must be positive.');

    // Check availability
    $avail = get_stock_qty($pdo, $itemId, $locId);
    if ($avail < $qty) {
        respond_error("Insufficient stock. Available: {$avail}, Requested: {$qty}.");
    }

    $pdo->beginTransaction();
    try {
        record_transaction($pdo, [
            'item_id'          => $itemId,
            'transaction_type' => 'Issue',
            'quantity'         => -$qty,       // negative = out
            'from_location_id' => $locId,
            'reference_type'   => optional($body, 'reference_type'),
            'reference_id'     => optional($body, 'reference_id'),
            'reason_code'      => optional($body, 'reason_code', 'Normal'),
            'notes'            => optional($body, 'notes'),
            'performed_by'     => $performedBy,
        ]);

        adjust_stock($pdo, $itemId, $locId, -$qty);

        $pdo->commit();
        respond_ok(null, "{$qty} units issued.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond_error('Issue failed: ' . $e->getMessage(), 500);
    }
}

// ── TRANSFER ─────────────────────────────────────────────────
function handle_transfer(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $itemId      = (int) require_field($body, 'item_id',          'item_id');
    $fromLocId   = (int) require_field($body, 'from_location_id', 'from_location_id');
    $toLocId     = (int) require_field($body, 'to_location_id',   'to_location_id');
    $qty         = (float) require_field($body, 'quantity',       'quantity');
    $performedBy = (int) require_field($body, 'performed_by',     'performed_by');

    if ($qty <= 0) respond_error('Quantity must be positive.');
    if ($fromLocId === $toLocId) respond_error('Source and destination locations must differ.');

    $avail = get_stock_qty($pdo, $itemId, $fromLocId);
    if ($avail < $qty) {
        respond_error("Insufficient stock at source. Available: {$avail}, Requested: {$qty}.");
    }

    $pdo->beginTransaction();
    try {
        record_transaction($pdo, [
            'item_id'          => $itemId,
            'transaction_type' => 'Transfer',
            'quantity'         => $qty,
            'from_location_id' => $fromLocId,
            'to_location_id'   => $toLocId,
            'reason_code'      => optional($body, 'reason_code', 'Normal'),
            'notes'            => optional($body, 'notes'),
            'performed_by'     => $performedBy,
        ]);

        adjust_stock($pdo, $itemId, $fromLocId, -$qty);
        adjust_stock($pdo, $itemId, $toLocId,    $qty);

        $pdo->commit();
        respond_ok(null, "{$qty} units transferred.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond_error('Transfer failed: ' . $e->getMessage(), 500);
    }
}

// ── MANUAL ADJUSTMENT ────────────────────────────────────────
function handle_adjust(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $itemId      = (int) require_field($body, 'item_id',     'item_id');
    $locId       = (int) require_field($body, 'location_id', 'location_id');
    $newQty      = (float) require_field($body, 'new_quantity', 'new_quantity');
    $performedBy = (int) require_field($body, 'performed_by', 'performed_by');

    $currentQty = get_stock_qty($pdo, $itemId, $locId);
    $delta      = $newQty - $currentQty;

    $pdo->beginTransaction();
    try {
        record_transaction($pdo, [
            'item_id'          => $itemId,
            'transaction_type' => 'Adjustment',
            'quantity'         => $delta,
            'to_location_id'   => $locId,
            'reason_code'      => optional($body, 'reason_code', 'AuditCorrection'),
            'notes'            => optional($body, 'notes'),
            'performed_by'     => $performedBy,
        ]);

        // Upsert stock level to exact value
        $pdo->prepare("
            INSERT INTO stock_levels (item_id, location_id, quantity_on_hand)
            VALUES (:item, :loc, :qty)
            ON DUPLICATE KEY UPDATE quantity_on_hand = :qty
        ")->execute([':item' => $itemId, ':loc' => $locId, ':qty' => $newQty]);

        $pdo->commit();
        respond_ok(['delta' => $delta, 'new_qty' => $newQty], "Stock adjusted. Delta: {$delta}.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond_error('Adjustment failed: ' . $e->getMessage(), 500);
    }
}

// ── Internal Helpers ─────────────────────────────────────────

/**
 * Insert a transaction record. Reusable by Modules 3 & 4.
 */
function record_transaction(PDO $pdo, array $d): void {
    $pdo->prepare("
        INSERT INTO stock_transactions
            (item_id, transaction_type, quantity,
             from_location_id, to_location_id, batch_id,
             reference_type, reference_id, reason_code, notes, performed_by)
        VALUES
            (:item_id, :type, :qty,
             :from_loc, :to_loc, :batch,
             :ref_type, :ref_id, :reason, :notes, :by)
    ")->execute([
        ':item_id'  => $d['item_id'],
        ':type'     => $d['transaction_type'],
        ':qty'      => $d['quantity'],
        ':from_loc' => $d['from_location_id'] ?? null,
        ':to_loc'   => $d['to_location_id']   ?? null,
        ':batch'    => $d['batch_id']          ?? null,
        ':ref_type' => $d['reference_type']    ?? null,
        ':ref_id'   => $d['reference_id']      ?? null,
        ':reason'   => $d['reason_code']       ?? 'Normal',
        ':notes'    => $d['notes']             ?? null,
        ':by'       => $d['performed_by'],
    ]);
}

/**
 * Adjust stock_levels by delta (positive or negative).
 */
function adjust_stock(PDO $pdo, int $itemId, int $locId, float $delta): void {
    $pdo->prepare("
        INSERT INTO stock_levels (item_id, location_id, quantity_on_hand)
        VALUES (:item, :loc, :delta)
        ON DUPLICATE KEY UPDATE quantity_on_hand = quantity_on_hand + :delta
    ")->execute([':item' => $itemId, ':loc' => $locId, ':delta' => $delta]);
}

/**
 * Get current stock quantity for an item at a location.
 */
function get_stock_qty(PDO $pdo, int $itemId, int $locId): float {
    $stmt = $pdo->prepare("SELECT quantity_on_hand FROM stock_levels WHERE item_id = :item AND location_id = :loc");
    $stmt->execute([':item' => $itemId, ':loc' => $locId]);
    return (float) ($stmt->fetchColumn() ?: 0);
}

/**
 * Create or top-up a stock batch.
 */
function create_batch(PDO $pdo, int $itemId, int $locId, float $qty, array $data): void {
    $pdo->prepare("
        INSERT INTO stock_batches (item_id, location_id, batch_number, lot_number, expiry_date, quantity)
        VALUES (:item, :loc, :batch, :lot, :exp, :qty)
    ")->execute([
        ':item'  => $itemId,
        ':loc'   => $locId,
        ':batch' => $data['batch_number'] ?? null,
        ':lot'   => $data['lot_number']   ?? null,
        ':exp'   => $data['expiry_date']  ?? null,
        ':qty'   => $qty,
    ]);
}
