<?php
// ============================================================
//  includes/api_helpers.php
//  Shared API utilities — reused by ALL modules
// ============================================================

require_once __DIR__ . '/../config/database.php';

// ── CORS & Headers ────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Response Helpers ─────────────────────────────────────────

/**
 * Send a JSON success response.
 */
function respond_ok(mixed $data = null, string $message = 'Success', int $code = 200): never {
    http_response_code($code);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send a JSON error response.
 */
function respond_error(string $message, int $code = 400, mixed $errors = null): never {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'errors'  => $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── Request Helpers ──────────────────────────────────────────

/**
 * Parse JSON body or fall back to $_POST.
 */
function get_body(): array {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return $_POST;
}

/**
 * Enforce allowed HTTP methods.
 */
function allow_methods(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        respond_error('Method not allowed.', 405);
    }
}

/**
 * Get a required field from an array or abort.
 */
function require_field(array $data, string $field, string $label = ''): mixed {
    if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
        $label = $label ?: $field;
        respond_error("Field '{$label}' is required.");
    }
    return $data[$field];
}

/**
 * Sanitize / coerce optional nullable field.
 */
function optional(array $data, string $field, mixed $default = null): mixed {
    return isset($data[$field]) && $data[$field] !== '' ? $data[$field] : $default;
}

// ── Pagination Helper ────────────────────────────────────────

/**
 * Build a paginated result set.
 *
 * @param PDO    $pdo
 * @param string $countSql   SELECT COUNT(*) query
 * @param string $dataSql    SELECT data query (must include LIMIT :limit OFFSET :offset)
 * @param array  $params     Bound params shared by both queries
 * @param int    $page
 * @param int    $perPage
 */
function paginate(PDO $pdo, string $countSql, string $dataSql, array $params = [], int $page = 1, int $perPage = 20): array {
    $page    = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset  = ($page - 1) * $perPage;

    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int) $stmtCount->fetchColumn();

    $stmtData = $pdo->prepare($dataSql);
    $stmtData->execute(array_merge($params, [':limit' => $perPage, ':offset' => $offset]));
    $rows = $stmtData->fetchAll();

    return [
        'items'       => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int) ceil($total / $perPage),
    ];
}

// ── Validation Helpers ───────────────────────────────────────

/**
 * Validate that a value is one of the allowed enum values.
 */
function validate_enum(string $value, array $allowed, string $field): void {
    if (!in_array($value, $allowed, true)) {
        respond_error("Invalid value for '{$field}'. Allowed: " . implode(', ', $allowed));
    }
}

/**
 * Validate date format (Y-m-d).
 */
function validate_date(?string $value, string $field): ?string {
    if ($value === null || $value === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        respond_error("Field '{$field}' must be a valid date (YYYY-MM-DD).");
    }
    return $value;
}
