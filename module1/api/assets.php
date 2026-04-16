<?php
// ============================================================
//  api/assets.php
//  Module 1 — Serialized Asset Master API
//  Routes:
//    GET    /api/assets.php            → list (paginated, filterable)
//    GET    /api/assets.php?id=X       → single asset detail
//    POST   /api/assets.php            → create asset
//    PUT    /api/assets.php?id=X       → update asset
//    DELETE /api/assets.php?id=X       → soft-delete (retire)
//
//    POST   /api/assets.php?action=assign      → assign/transfer
//    POST   /api/assets.php?action=checkout    → check out
//    POST   /api/assets.php?action=checkin     → check in
//    POST   /api/assets.php?action=retire      → retire asset
//    POST   /api/assets.php?action=dispose     → dispose asset
//    GET    /api/assets.php?action=history&id=X → lifecycle log
//    GET    /api/assets.php?action=children&id=X → child assets
// ============================================================

require_once __DIR__ . '/../includes/api_helpers.php';

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

// ── Route dispatcher ────────────────────────────────────────

match (true) {
    $action === 'assign'    => handle_assign($pdo),
    $action === 'checkout'  => handle_checkout($pdo),
    $action === 'checkin'   => handle_checkin($pdo),
    $action === 'retire'    => handle_retire($pdo),
    $action === 'dispose'   => handle_dispose($pdo),
    $action === 'history'   => handle_history($pdo, $id),
    $action === 'children'  => handle_children($pdo, $id),
    $method === 'GET' && $id => get_asset($pdo, $id),
    $method === 'GET'        => list_assets($pdo),
    $method === 'POST'       => create_asset($pdo),
    $method === 'PUT' && $id => update_asset($pdo, $id),
    $method === 'DELETE' && $id => delete_asset($pdo, $id),
    default                  => respond_error('Invalid route.', 404),
};

// ── Helpers ──────────────────────────────────────────────────

/**
 * Build the full SELECT for an asset with joins.
 */
function asset_select(): string {
    return "
        SELECT
            a.*,
            c.name             AS category_name,
            v.name             AS vendor_name,
            u.full_name        AS assigned_user_name,
            d.name             AS department_name,
            CONCAT(s.name, ' › ', l.name) AS location_full,
            l.name             AS location_name,
            s.name             AS site_name,
            pa.asset_tag       AS parent_asset_tag
        FROM assets a
        LEFT JOIN categories c  ON c.id = a.category_id
        LEFT JOIN vendors    v  ON v.id = a.vendor_id
        LEFT JOIN users      u  ON u.id = a.assigned_user_id
        LEFT JOIN departments d ON d.id = a.department_id
        LEFT JOIN locations  l  ON l.id = a.location_id
        LEFT JOIN sites      s  ON s.id = l.site_id
        LEFT JOIN assets     pa ON pa.id = a.parent_asset_id
    ";
}

// ── LIST ─────────────────────────────────────────────────────
function list_assets(PDO $pdo): never {
    allow_methods('GET');

    $page    = (int) ($_GET['page']     ?? 1);
    $perPage = (int) ($_GET['per_page'] ?? 20);
    $search  = $_GET['search']          ?? '';
    $status  = $_GET['status']          ?? '';
    $cat     = $_GET['category_id']     ?? '';
    $loc     = $_GET['location_id']     ?? '';
    $dept    = $_GET['department_id']   ?? '';

    $where  = ['a.is_active = 1'];
    $params = [];

    if ($search !== '') {
        $where[]          = '(a.asset_tag LIKE :search OR a.serial_number LIKE :search OR a.make LIKE :search OR a.model LIKE :search)';
        $params[':search'] = "%{$search}%";
    }
    if ($status !== '') {
        $where[]           = 'a.status = :status';
        $params[':status'] = $status;
    }
    if ($cat !== '') {
        $where[]               = 'a.category_id = :cat';
        $params[':cat']        = (int) $cat;
    }
    if ($loc !== '') {
        $where[]               = 'a.location_id = :loc';
        $params[':loc']        = (int) $loc;
    }
    if ($dept !== '') {
        $where[]               = 'a.department_id = :dept';
        $params[':dept']       = (int) $dept;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM assets a {$whereClause}";
    $dataSql  = asset_select() . " {$whereClause} ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset";

    respond_ok(paginate($pdo, $countSql, $dataSql, $params, $page, $perPage));
}

// ── GET SINGLE ───────────────────────────────────────────────
function get_asset(PDO $pdo, int $id): never {
    allow_methods('GET');

    $stmt = $pdo->prepare(asset_select() . ' WHERE a.id = :id AND a.is_active = 1');
    $stmt->execute([':id' => $id]);
    $asset = $stmt->fetch();

    if (!$asset) respond_error('Asset not found.', 404);

    // Attach recent lifecycle log (last 10)
    $logStmt = $pdo->prepare("
        SELECT ll.*, u.full_name AS performed_by_name
        FROM asset_lifecycle_log ll
        LEFT JOIN users u ON u.id = ll.performed_by
        WHERE ll.asset_id = :id
        ORDER BY ll.performed_at DESC
        LIMIT 10
    ");
    $logStmt->execute([':id' => $id]);
    $asset['lifecycle_log'] = $logStmt->fetchAll();

    // Attach attachments
    $attStmt = $pdo->prepare("SELECT * FROM asset_attachments WHERE asset_id = :id ORDER BY uploaded_at DESC");
    $attStmt->execute([':id' => $id]);
    $asset['attachments'] = $attStmt->fetchAll();

    respond_ok($asset);
}

// ── CREATE ───────────────────────────────────────────────────
function create_asset(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $assetTag = require_field($body, 'asset_tag',   'Asset Tag');
    $catId    = require_field($body, 'category_id', 'Category');

    // Check unique asset_tag
    $chk = $pdo->prepare("SELECT id FROM assets WHERE asset_tag = :tag");
    $chk->execute([':tag' => $assetTag]);
    if ($chk->fetch()) respond_error("Asset tag '{$assetTag}' already exists.");

    $allowedStatus = ['In-Use','In-Stock','Under Repair','Retired','Disposed','Lost'];
    $status = optional($body, 'status', 'In-Stock');
    validate_enum($status, $allowedStatus, 'status');

    $sql = "
        INSERT INTO assets (
            asset_tag, serial_number, barcode, category_id,
            make, model, cpu, ram, storage, os, firmware_version,
            vendor_id, po_number, invoice_number, purchase_cost, date_acquired,
            warranty_start, warranty_end, sla_tier, support_contract_ref,
            status, assigned_user_id, department_id, location_id, cost_center,
            depreciation_method, useful_life_years, salvage_value,
            parent_asset_id, installed_at, connected_to, notes, created_by
        ) VALUES (
            :asset_tag, :serial_number, :barcode, :category_id,
            :make, :model, :cpu, :ram, :storage, :os, :firmware_version,
            :vendor_id, :po_number, :invoice_number, :purchase_cost, :date_acquired,
            :warranty_start, :warranty_end, :sla_tier, :support_contract_ref,
            :status, :assigned_user_id, :department_id, :location_id, :cost_center,
            :depreciation_method, :useful_life_years, :salvage_value,
            :parent_asset_id, :installed_at, :connected_to, :notes, :created_by
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':asset_tag'           => $assetTag,
        ':serial_number'       => optional($body, 'serial_number'),
        ':barcode'             => optional($body, 'barcode'),
        ':category_id'         => (int) $catId,
        ':make'                => optional($body, 'make'),
        ':model'               => optional($body, 'model'),
        ':cpu'                 => optional($body, 'cpu'),
        ':ram'                 => optional($body, 'ram'),
        ':storage'             => optional($body, 'storage'),
        ':os'                  => optional($body, 'os'),
        ':firmware_version'    => optional($body, 'firmware_version'),
        ':vendor_id'           => optional($body, 'vendor_id'),
        ':po_number'           => optional($body, 'po_number'),
        ':invoice_number'      => optional($body, 'invoice_number'),
        ':purchase_cost'       => optional($body, 'purchase_cost'),
        ':date_acquired'       => validate_date(optional($body, 'date_acquired'), 'date_acquired'),
        ':warranty_start'      => validate_date(optional($body, 'warranty_start'), 'warranty_start'),
        ':warranty_end'        => validate_date(optional($body, 'warranty_end'), 'warranty_end'),
        ':sla_tier'            => optional($body, 'sla_tier'),
        ':support_contract_ref'=> optional($body, 'support_contract_ref'),
        ':status'              => $status,
        ':assigned_user_id'    => optional($body, 'assigned_user_id'),
        ':department_id'       => optional($body, 'department_id'),
        ':location_id'         => optional($body, 'location_id'),
        ':cost_center'         => optional($body, 'cost_center'),
        ':depreciation_method' => optional($body, 'depreciation_method', 'None'),
        ':useful_life_years'   => optional($body, 'useful_life_years'),
        ':salvage_value'       => optional($body, 'salvage_value'),
        ':parent_asset_id'     => optional($body, 'parent_asset_id'),
        ':installed_at'        => optional($body, 'installed_at'),
        ':connected_to'        => optional($body, 'connected_to'),
        ':notes'               => optional($body, 'notes'),
        ':created_by'          => optional($body, 'performed_by', 1), // default system user
    ]);

    $newId = (int) $pdo->lastInsertId();

    // Log creation
    log_lifecycle($pdo, [
        'asset_id'      => $newId,
        'action_type'   => 'StatusChange',
        'to_status'     => $status,
        'reason'        => 'Asset created',
        'performed_by'  => optional($body, 'performed_by', 1),
    ]);

    respond_ok(['id' => $newId], 'Asset created successfully.', 201);
}

// ── UPDATE ───────────────────────────────────────────────────
function update_asset(PDO $pdo, int $id): never {
    allow_methods('PUT');
    $body = get_body();

    $chk = $pdo->prepare("SELECT id, status FROM assets WHERE id = :id AND is_active = 1");
    $chk->execute([':id' => $id]);
    $existing = $chk->fetch();
    if (!$existing) respond_error('Asset not found.', 404);

    // Build dynamic SET clause
    $allowed = [
        'asset_tag','serial_number','barcode','category_id',
        'make','model','cpu','ram','storage','os','firmware_version',
        'vendor_id','po_number','invoice_number','purchase_cost','date_acquired',
        'warranty_start','warranty_end','sla_tier','support_contract_ref',
        'status','assigned_user_id','department_id','location_id','cost_center',
        'depreciation_method','useful_life_years','salvage_value',
        'parent_asset_id','installed_at','connected_to','notes',
    ];

    $sets   = [];
    $params = [':id' => $id];

    foreach ($allowed as $col) {
        if (array_key_exists($col, $body)) {
            $sets[]         = "{$col} = :{$col}";
            $params[":{$col}"] = $body[$col] === '' ? null : $body[$col];
        }
    }

    if (empty($sets)) respond_error('No fields provided to update.');

    $pdo->prepare("UPDATE assets SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);

    // Log status change if changed
    if (isset($body['status']) && $body['status'] !== $existing['status']) {
        log_lifecycle($pdo, [
            'asset_id'      => $id,
            'action_type'   => 'StatusChange',
            'from_status'   => $existing['status'],
            'to_status'     => $body['status'],
            'reason'        => optional($body, 'reason', 'Manual update'),
            'performed_by'  => optional($body, 'performed_by', 1),
        ]);
    }

    respond_ok(['id' => $id], 'Asset updated successfully.');
}

// ── SOFT DELETE ──────────────────────────────────────────────
function delete_asset(PDO $pdo, int $id): never {
    allow_methods('DELETE');
    $body = get_body();

    $stmt = $pdo->prepare("UPDATE assets SET is_active = 0, status = 'Retired' WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) respond_error('Asset not found or already deleted.', 404);

    log_lifecycle($pdo, [
        'asset_id'     => $id,
        'action_type'  => 'Retired',
        'to_status'    => 'Retired',
        'reason'       => optional($body, 'reason', 'Soft deleted via API'),
        'performed_by' => optional($body, 'performed_by', 1),
    ]);

    respond_ok(null, 'Asset retired.');
}

// ── LIFECYCLE ACTIONS ────────────────────────────────────────

function handle_assign(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $assetId    = (int) require_field($body, 'asset_id',    'asset_id');
    $performedBy= (int) require_field($body, 'performed_by','performed_by');

    $asset = $pdo->prepare("SELECT * FROM assets WHERE id = :id AND is_active = 1");
    $asset->execute([':id' => $assetId]);
    $row = $asset->fetch();
    if (!$row) respond_error('Asset not found.', 404);

    $toUserId   = optional($body, 'to_user_id');
    $toLocId    = optional($body, 'to_location_id');
    $toDeptId   = optional($body, 'to_department_id');

    // Update asset
    $pdo->prepare("
        UPDATE assets SET
            assigned_user_id = :uid,
            location_id      = COALESCE(:loc, location_id),
            department_id    = COALESCE(:dept, department_id),
            status           = 'In-Use'
        WHERE id = :id
    ")->execute([
        ':uid'  => $toUserId,
        ':loc'  => $toLocId,
        ':dept' => $toDeptId,
        ':id'   => $assetId,
    ]);

    $isTransfer = ($row['assigned_user_id'] !== null);

    log_lifecycle($pdo, [
        'asset_id'         => $assetId,
        'action_type'      => $isTransfer ? 'Transferred' : 'Assigned',
        'from_user_id'     => $row['assigned_user_id'],
        'to_user_id'       => $toUserId,
        'from_location_id' => $row['location_id'],
        'to_location_id'   => $toLocId,
        'from_status'      => $row['status'],
        'to_status'        => 'In-Use',
        'reason'           => optional($body, 'reason', ''),
        'approved_by'      => optional($body, 'approved_by'),
        'performed_by'     => $performedBy,
    ]);

    respond_ok(null, $isTransfer ? 'Asset transferred.' : 'Asset assigned.');
}

function handle_checkout(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $assetId       = (int) require_field($body, 'asset_id',       'asset_id');
    $checkedOutBy  = (int) require_field($body, 'checked_out_by', 'checked_out_by');

    // Ensure not already checked out
    $open = $pdo->prepare("SELECT id FROM asset_checkouts WHERE asset_id = :id AND checked_in_at IS NULL");
    $open->execute([':id' => $assetId]);
    if ($open->fetch()) respond_error('Asset is already checked out.');

    $pdo->prepare("
        INSERT INTO asset_checkouts (asset_id, checked_out_by, expected_return, notes)
        VALUES (:asset_id, :by, :ret, :notes)
    ")->execute([
        ':asset_id' => $assetId,
        ':by'       => $checkedOutBy,
        ':ret'      => optional($body, 'expected_return'),
        ':notes'    => optional($body, 'notes'),
    ]);

    $pdo->prepare("UPDATE assets SET status = 'In-Use' WHERE id = :id")->execute([':id' => $assetId]);

    log_lifecycle($pdo, [
        'asset_id'    => $assetId,
        'action_type' => 'CheckedOut',
        'to_user_id'  => $checkedOutBy,
        'to_status'   => 'In-Use',
        'reason'      => optional($body, 'notes', ''),
        'performed_by'=> $checkedOutBy,
    ]);

    respond_ok(null, 'Asset checked out.');
}

function handle_checkin(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $assetId     = (int) require_field($body, 'asset_id',     'asset_id');
    $checkedInBy = (int) require_field($body, 'checked_in_by','checked_in_by');

    $open = $pdo->prepare("SELECT * FROM asset_checkouts WHERE asset_id = :id AND checked_in_at IS NULL");
    $open->execute([':id' => $assetId]);
    $checkout = $open->fetch();
    if (!$checkout) respond_error('No open checkout found for this asset.');

    $pdo->prepare("
        UPDATE asset_checkouts SET checked_in_at = NOW(), checked_in_by = :by
        WHERE id = :id
    ")->execute([':by' => $checkedInBy, ':id' => $checkout['id']]);

    $pdo->prepare("UPDATE assets SET status = 'In-Stock', assigned_user_id = NULL WHERE id = :id")
        ->execute([':id' => $assetId]);

    log_lifecycle($pdo, [
        'asset_id'    => $assetId,
        'action_type' => 'CheckedIn',
        'from_user_id'=> $checkout['checked_out_by'],
        'from_status' => 'In-Use',
        'to_status'   => 'In-Stock',
        'reason'      => optional($body, 'notes', ''),
        'performed_by'=> $checkedInBy,
    ]);

    respond_ok(null, 'Asset checked in.');
}

function handle_retire(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $assetId     = (int) require_field($body, 'asset_id',    'asset_id');
    $performedBy = (int) require_field($body, 'performed_by','performed_by');
    $approvedBy  = optional($body, 'approved_by');

    $asset = $pdo->prepare("SELECT status FROM assets WHERE id = :id AND is_active = 1");
    $asset->execute([':id' => $assetId]);
    $row = $asset->fetch();
    if (!$row) respond_error('Asset not found.', 404);

    $pdo->prepare("UPDATE assets SET status = 'Retired' WHERE id = :id")->execute([':id' => $assetId]);

    log_lifecycle($pdo, [
        'asset_id'    => $assetId,
        'action_type' => 'Retired',
        'from_status' => $row['status'],
        'to_status'   => 'Retired',
        'reason'      => optional($body, 'reason', ''),
        'approved_by' => $approvedBy,
        'performed_by'=> $performedBy,
    ]);

    respond_ok(null, 'Asset retired.');
}

function handle_dispose(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $assetId     = (int) require_field($body, 'asset_id',    'asset_id');
    $performedBy = (int) require_field($body, 'performed_by','performed_by');
    $approvedBy  = optional($body, 'approved_by');

    $asset = $pdo->prepare("SELECT status FROM assets WHERE id = :id AND is_active = 1");
    $asset->execute([':id' => $assetId]);
    $row = $asset->fetch();
    if (!$row) respond_error('Asset not found.', 404);

    $pdo->prepare("UPDATE assets SET status = 'Disposed', is_active = 0 WHERE id = :id")
        ->execute([':id' => $assetId]);

    log_lifecycle($pdo, [
        'asset_id'    => $assetId,
        'action_type' => 'Disposed',
        'from_status' => $row['status'],
        'to_status'   => 'Disposed',
        'reason'      => optional($body, 'reason', ''),
        'approved_by' => $approvedBy,
        'signoff_note'=> optional($body, 'signoff_note'),
        'performed_by'=> $performedBy,
    ]);

    respond_ok(null, 'Asset disposed.');
}

function handle_history(PDO $pdo, ?int $id): never {
    allow_methods('GET');
    if (!$id) respond_error('Asset id required.');

    $page    = (int) ($_GET['page']     ?? 1);
    $perPage = (int) ($_GET['per_page'] ?? 20);

    $countSql = "SELECT COUNT(*) FROM asset_lifecycle_log WHERE asset_id = :id";
    $dataSql  = "
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
        LIMIT :limit OFFSET :offset
    ";

    respond_ok(paginate($pdo, $countSql, $dataSql, [':id' => $id], $page, $perPage));
}

function handle_children(PDO $pdo, ?int $id): never {
    allow_methods('GET');
    if (!$id) respond_error('Asset id required.');

    $stmt = $pdo->prepare(asset_select() . ' WHERE a.parent_asset_id = :id AND a.is_active = 1');
    $stmt->execute([':id' => $id]);
    respond_ok($stmt->fetchAll());
}

// ── Internal lifecycle logger (reused by all actions) ───────
function log_lifecycle(PDO $pdo, array $data): void {
    $pdo->prepare("
        INSERT INTO asset_lifecycle_log
            (asset_id, action_type, from_user_id, to_user_id,
             from_location_id, to_location_id, from_status, to_status,
             reason, approved_by, signoff_note, performed_by)
        VALUES
            (:asset_id, :action_type, :from_user_id, :to_user_id,
             :from_location_id, :to_location_id, :from_status, :to_status,
             :reason, :approved_by, :signoff_note, :performed_by)
    ")->execute([
        ':asset_id'         => $data['asset_id'],
        ':action_type'      => $data['action_type'],
        ':from_user_id'     => $data['from_user_id']     ?? null,
        ':to_user_id'       => $data['to_user_id']       ?? null,
        ':from_location_id' => $data['from_location_id'] ?? null,
        ':to_location_id'   => $data['to_location_id']   ?? null,
        ':from_status'      => $data['from_status']      ?? null,
        ':to_status'        => $data['to_status']        ?? null,
        ':reason'           => $data['reason']           ?? null,
        ':approved_by'      => $data['approved_by']      ?? null,
        ':signoff_note'     => $data['signoff_note']     ?? null,
        ':performed_by'     => $data['performed_by'],
    ]);
}
