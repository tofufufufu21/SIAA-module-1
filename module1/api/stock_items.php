<?php
// ============================================================
//  api/stock_items.php
//  Module 1 — Consumables / Stock Items API
//  Routes:
//    GET    /api/stock_items.php            → list with stock levels
//    GET    /api/stock_items.php?id=X       → single item
//    POST   /api/stock_items.php            → create item
//    PUT    /api/stock_items.php?id=X       → update item
//    DELETE /api/stock_items.php?id=X       → soft delete
//    GET    /api/stock_items.php?action=low_stock          → items below ROP
//    GET    /api/stock_items.php?action=transactions&id=X  → ledger
// ============================================================

require_once __DIR__ . '/../includes/api_helpers.php';

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

match (true) {
    $action === 'low_stock'    => handle_low_stock($pdo),
    $action === 'transactions' => handle_transactions($pdo, $id),
    $method === 'GET' && $id   => get_item($pdo, $id),
    $method === 'GET'          => list_items($pdo),
    $method === 'POST'         => create_item($pdo),
    $method === 'PUT' && $id   => update_item($pdo, $id),
    $method === 'DELETE' && $id=> delete_item($pdo, $id),
    default                    => respond_error('Invalid route.', 404),
};

// ── LIST ─────────────────────────────────────────────────────
function list_items(PDO $pdo): never {
    allow_methods('GET');

    $page    = (int) ($_GET['page']     ?? 1);
    $perPage = (int) ($_GET['per_page'] ?? 20);
    $search  = $_GET['search'] ?? '';
    $cat     = $_GET['category_id'] ?? '';

    $where  = ['si.is_active = 1'];
    $params = [];

    if ($search !== '') {
        $where[]           = '(si.item_code LIKE :s OR si.name LIKE :s)';
        $params[':s']      = "%{$search}%";
    }
    if ($cat !== '') {
        $where[]           = 'si.category_id = :cat';
        $params[':cat']    = (int) $cat;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM stock_items si {$whereClause}";
    $dataSql  = "
        SELECT si.*,
               c.name AS category_name,
               COALESCE(SUM(sl.quantity_on_hand), 0) AS total_qty_on_hand
        FROM stock_items si
        LEFT JOIN categories  c  ON c.id = si.category_id
        LEFT JOIN stock_levels sl ON sl.item_id = si.id
        {$whereClause}
        GROUP BY si.id
        ORDER BY si.name ASC
        LIMIT :limit OFFSET :offset
    ";

    respond_ok(paginate($pdo, $countSql, $dataSql, $params, $page, $perPage));
}

// ── GET SINGLE ───────────────────────────────────────────────
function get_item(PDO $pdo, int $id): never {
    allow_methods('GET');

    $stmt = $pdo->prepare("
        SELECT si.*, c.name AS category_name
        FROM stock_items si
        LEFT JOIN categories c ON c.id = si.category_id
        WHERE si.id = :id AND si.is_active = 1
    ");
    $stmt->execute([':id' => $id]);
    $item = $stmt->fetch();
    if (!$item) respond_error('Item not found.', 404);

    // Stock levels by location
    $lvl = $pdo->prepare("
        SELECT sl.*, l.name AS location_name, s.name AS site_name
        FROM stock_levels sl
        LEFT JOIN locations l ON l.id = sl.location_id
        LEFT JOIN sites     s ON s.id = l.site_id
        WHERE sl.item_id = :id
    ");
    $lvl->execute([':id' => $id]);
    $item['stock_levels'] = $lvl->fetchAll();

    // Active batches
    $bat = $pdo->prepare("
        SELECT sb.*, l.name AS location_name
        FROM stock_batches sb
        LEFT JOIN locations l ON l.id = sb.location_id
        WHERE sb.item_id = :id AND sb.quantity > 0
        ORDER BY sb.expiry_date ASC
    ");
    $bat->execute([':id' => $id]);
    $item['batches'] = $bat->fetchAll();

    respond_ok($item);
}

// ── CREATE ───────────────────────────────────────────────────
function create_item(PDO $pdo): never {
    allow_methods('POST');
    $body = get_body();

    $code = require_field($body, 'item_code', 'Item Code');
    $name = require_field($body, 'name',      'Name');

    $chk = $pdo->prepare("SELECT id FROM stock_items WHERE item_code = :code");
    $chk->execute([':code' => $code]);
    if ($chk->fetch()) respond_error("Item code '{$code}' already exists.");

    $pdo->prepare("
        INSERT INTO stock_items (item_code, name, description, category_id, unit_of_measure, compatible_models, has_expiry, unit_cost)
        VALUES (:code, :name, :desc, :cat, :uom, :compat, :exp, :cost)
    ")->execute([
        ':code'   => $code,
        ':name'   => $name,
        ':desc'   => optional($body, 'description'),
        ':cat'    => optional($body, 'category_id'),
        ':uom'    => optional($body, 'unit_of_measure', 'pcs'),
        ':compat' => optional($body, 'compatible_models'),
        ':exp'    => optional($body, 'has_expiry', 0),
        ':cost'   => optional($body, 'unit_cost'),
    ]);

    $newId = (int) $pdo->lastInsertId();

    // If initial stock level provided, set it
    if (isset($body['location_id']) && isset($body['quantity_on_hand'])) {
        set_stock_level($pdo, $newId, (int) $body['location_id'], $body);
    }

    respond_ok(['id' => $newId], 'Stock item created.', 201);
}

// ── UPDATE ───────────────────────────────────────────────────
function update_item(PDO $pdo, int $id): never {
    allow_methods('PUT');
    $body = get_body();

    $chk = $pdo->prepare("SELECT id FROM stock_items WHERE id = :id AND is_active = 1");
    $chk->execute([':id' => $id]);
    if (!$chk->fetch()) respond_error('Item not found.', 404);

    $allowed = ['name','description','category_id','unit_of_measure','compatible_models','has_expiry','unit_cost'];
    $sets    = [];
    $params  = [':id' => $id];

    foreach ($allowed as $col) {
        if (array_key_exists($col, $body)) {
            $sets[]             = "{$col} = :{$col}";
            $params[":{$col}"]  = $body[$col] === '' ? null : $body[$col];
        }
    }

    if (empty($sets)) respond_error('No fields to update.');

    $pdo->prepare("UPDATE stock_items SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);

    respond_ok(['id' => $id], 'Item updated.');
}

// ── DELETE ───────────────────────────────────────────────────
function delete_item(PDO $pdo, int $id): never {
    allow_methods('DELETE');

    $stmt = $pdo->prepare("UPDATE stock_items SET is_active = 0 WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) respond_error('Item not found.', 404);
    respond_ok(null, 'Item deactivated.');
}

// ── LOW STOCK ────────────────────────────────────────────────
function handle_low_stock(PDO $pdo): never {
    allow_methods('GET');

    $stmt = $pdo->prepare("
        SELECT si.id, si.item_code, si.name, si.unit_of_measure,
               sl.location_id, l.name AS location_name,
               sl.quantity_on_hand, sl.reorder_point, sl.min_level, sl.max_level, sl.lead_time_days
        FROM stock_levels sl
        JOIN stock_items  si ON si.id = sl.item_id
        JOIN locations    l  ON l.id  = sl.location_id
        WHERE si.is_active = 1
          AND sl.quantity_on_hand <= COALESCE(sl.reorder_point, sl.min_level)
        ORDER BY (sl.quantity_on_hand - COALESCE(sl.reorder_point, sl.min_level)) ASC
    ");
    $stmt->execute();
    respond_ok($stmt->fetchAll());
}

// ── TRANSACTIONS ─────────────────────────────────────────────
function handle_transactions(PDO $pdo, ?int $id): never {
    allow_methods('GET');
    if (!$id) respond_error('Item id required.');

    $page    = (int) ($_GET['page']     ?? 1);
    $perPage = (int) ($_GET['per_page'] ?? 20);

    $countSql = "SELECT COUNT(*) FROM stock_transactions WHERE item_id = :id";
    $dataSql  = "
        SELECT st.*, 
               fl.name AS from_location_name,
               tl.name AS to_location_name,
               u.full_name AS performed_by_name
        FROM stock_transactions st
        LEFT JOIN locations fl ON fl.id = st.from_location_id
        LEFT JOIN locations tl ON tl.id = st.to_location_id
        LEFT JOIN users     u  ON u.id  = st.performed_by
        WHERE st.item_id = :id
        ORDER BY st.performed_at DESC
        LIMIT :limit OFFSET :offset
    ";

    respond_ok(paginate($pdo, $countSql, $dataSql, [':id' => $id], $page, $perPage));
}

// ── INTERNAL: set or update stock level ──────────────────────
function set_stock_level(PDO $pdo, int $itemId, int $locId, array $data): void {
    $pdo->prepare("
        INSERT INTO stock_levels (item_id, location_id, quantity_on_hand, min_level, max_level, reorder_point, lead_time_days)
        VALUES (:item, :loc, :qty, :min, :max, :rop, :ltd)
        ON DUPLICATE KEY UPDATE
            quantity_on_hand = VALUES(quantity_on_hand),
            min_level        = COALESCE(VALUES(min_level), min_level),
            max_level        = COALESCE(VALUES(max_level), max_level),
            reorder_point    = COALESCE(VALUES(reorder_point), reorder_point),
            lead_time_days   = COALESCE(VALUES(lead_time_days), lead_time_days)
    ")->execute([
        ':item' => $itemId,
        ':loc'  => $locId,
        ':qty'  => $data['quantity_on_hand'] ?? 0,
        ':min'  => $data['min_level']        ?? 0,
        ':max'  => $data['max_level']        ?? null,
        ':rop'  => $data['reorder_point']    ?? null,
        ':ltd'  => $data['lead_time_days']   ?? null,
    ]);
}
