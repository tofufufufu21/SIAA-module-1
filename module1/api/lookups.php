<?php
// ============================================================
//  api/lookups.php
//  Shared Reference Data API — used by ALL modules
//  Categories, Departments, Sites, Locations, Vendors, Users
//
//  Routes:
//    GET /api/lookups.php?resource=categories
//    GET /api/lookups.php?resource=departments
//    GET /api/lookups.php?resource=sites
//    GET /api/lookups.php?resource=locations[&site_id=X]
//    GET /api/lookups.php?resource=vendors
//    GET /api/lookups.php?resource=users
//
//    POST   /api/lookups.php?resource=X    → create
//    PUT    /api/lookups.php?resource=X&id=Y → update
//    DELETE /api/lookups.php?resource=X&id=Y → soft delete
// ============================================================

require_once __DIR__ . '/../includes/api_helpers.php';

$pdo      = db();
$method   = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';
$id       = isset($_GET['id']) ? (int) $_GET['id'] : null;

$allowed = ['categories','departments','sites','locations','vendors','users'];
if (!in_array($resource, $allowed, true)) {
    respond_error("Unknown resource '{$resource}'. Allowed: " . implode(', ', $allowed), 400);
}

match ($method) {
    'GET'    => handle_get($pdo, $resource, $id),
    'POST'   => handle_post($pdo, $resource),
    'PUT'    => handle_put($pdo, $resource, $id),
    'DELETE' => handle_delete($pdo, $resource, $id),
    default  => respond_error('Method not allowed.', 405),
};

// ── GET ──────────────────────────────────────────────────────
function handle_get(PDO $pdo, string $resource, ?int $id): never {
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM {$resource} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) respond_error(ucfirst($resource) . ' not found.', 404);
        respond_ok($row);
    }

    // Special case: locations can be filtered by site
    if ($resource === 'locations' && isset($_GET['site_id'])) {
        $stmt = $pdo->prepare("
            SELECT l.*, s.name AS site_name
            FROM locations l JOIN sites s ON s.id = l.site_id
            WHERE l.site_id = :site AND l.is_active = 1
            ORDER BY l.name
        ");
        $stmt->execute([':site' => (int) $_GET['site_id']]);
        respond_ok($stmt->fetchAll());
    }

    // Users: join department
    if ($resource === 'users') {
        $search = $_GET['search'] ?? '';
        $where  = ['u.is_active = 1'];
        $params = [];
        if ($search !== '') {
            $where[]        = '(u.full_name LIKE :s OR u.email LIKE :s OR u.employee_id LIKE :s)';
            $params[':s']   = "%{$search}%";
        }
        $wc   = 'WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare("
            SELECT u.*, d.name AS department_name
            FROM users u LEFT JOIN departments d ON d.id = u.department_id
            {$wc} ORDER BY u.full_name LIMIT 200
        ");
        $stmt->execute($params);
        respond_ok($stmt->fetchAll());
    }

    // Vendors
    if ($resource === 'vendors') {
        $active = isset($_GET['active']) ? (int) $_GET['active'] : 1;
        $stmt   = $pdo->prepare("SELECT * FROM vendors WHERE is_active = :a ORDER BY name");
        $stmt->execute([':a' => $active]);
        respond_ok($stmt->fetchAll());
    }

    // Generic list
    $stmt = $pdo->prepare("SELECT * FROM {$resource} WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    respond_ok($stmt->fetchAll());
}

// ── POST ─────────────────────────────────────────────────────
function handle_post(PDO $pdo, string $resource): never {
    allow_methods('POST');
    $body = get_body();

    switch ($resource) {
        case 'categories':
            $name = require_field($body, 'name', 'Name');
            $pdo->prepare("INSERT INTO categories (name, description) VALUES (:n, :d)")
                ->execute([':n' => $name, ':d' => optional($body, 'description')]);
            break;

        case 'departments':
            $name = require_field($body, 'name', 'Name');
            $pdo->prepare("INSERT INTO departments (name, cost_center) VALUES (:n, :cc)")
                ->execute([':n' => $name, ':cc' => optional($body, 'cost_center')]);
            break;

        case 'sites':
            $name = require_field($body, 'name', 'Name');
            $pdo->prepare("INSERT INTO sites (name, address) VALUES (:n, :a)")
                ->execute([':n' => $name, ':a' => optional($body, 'address')]);
            break;

        case 'locations':
            $name   = require_field($body, 'name',    'Name');
            $siteId = require_field($body, 'site_id', 'Site');
            $pdo->prepare("INSERT INTO locations (site_id, name, description) VALUES (:s, :n, :d)")
                ->execute([':s' => (int) $siteId, ':n' => $name, ':d' => optional($body, 'description')]);
            break;

        case 'vendors':
            $name = require_field($body, 'name', 'Name');
            $pdo->prepare("
                INSERT INTO vendors (name, contact_name, email, phone, address, sla_notes)
                VALUES (:n, :cn, :e, :p, :a, :s)
            ")->execute([
                ':n'  => $name,
                ':cn' => optional($body, 'contact_name'),
                ':e'  => optional($body, 'email'),
                ':p'  => optional($body, 'phone'),
                ':a'  => optional($body, 'address'),
                ':s'  => optional($body, 'sla_notes'),
            ]);
            break;

        case 'users':
            $fullName = require_field($body, 'full_name', 'Full Name');
            $email    = require_field($body, 'email',     'Email');

            $chk = $pdo->prepare("SELECT id FROM users WHERE email = :e");
            $chk->execute([':e' => $email]);
            if ($chk->fetch()) respond_error("Email '{$email}' already registered.");

            $pdo->prepare("
                INSERT INTO users (employee_id, full_name, email, phone, department_id, role)
                VALUES (:eid, :fn, :e, :p, :d, :r)
            ")->execute([
                ':eid' => optional($body, 'employee_id'),
                ':fn'  => $fullName,
                ':e'   => $email,
                ':p'   => optional($body, 'phone'),
                ':d'   => optional($body, 'department_id'),
                ':r'   => optional($body, 'role', 'user'),
            ]);
            break;
    }

    respond_ok(['id' => (int) $pdo->lastInsertId()], ucfirst($resource) . ' created.', 201);
}

// ── PUT ──────────────────────────────────────────────────────
function handle_put(PDO $pdo, string $resource, ?int $id): never {
    allow_methods('PUT');
    if (!$id) respond_error('ID required for update.');
    $body = get_body();

    $fieldMap = [
        'categories'  => ['name', 'description'],
        'departments' => ['name', 'cost_center'],
        'sites'       => ['name', 'address'],
        'locations'   => ['name', 'description', 'site_id'],
        'vendors'     => ['name', 'contact_name', 'email', 'phone', 'address', 'sla_notes'],
        'users'       => ['employee_id', 'full_name', 'email', 'phone', 'department_id', 'role'],
    ];

    $allowed = $fieldMap[$resource] ?? [];
    $sets    = [];
    $params  = [':id' => $id];

    foreach ($allowed as $col) {
        if (array_key_exists($col, $body)) {
            $sets[]             = "{$col} = :{$col}";
            $params[":{$col}"]  = $body[$col] === '' ? null : $body[$col];
        }
    }

    if (empty($sets)) respond_error('No fields to update.');

    $pdo->prepare("UPDATE {$resource} SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
    respond_ok(['id' => $id], ucfirst($resource) . ' updated.');
}

// ── DELETE ───────────────────────────────────────────────────
function handle_delete(PDO $pdo, string $resource, ?int $id): never {
    allow_methods('DELETE');
    if (!$id) respond_error('ID required for delete.');

    $stmt = $pdo->prepare("UPDATE {$resource} SET is_active = 0 WHERE id = :id");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) respond_error(ucfirst($resource) . ' not found.', 404);
    respond_ok(null, ucfirst($resource) . ' deactivated.');
}
