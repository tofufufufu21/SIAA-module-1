<?php
// ============================================================
//  api/attachments.php
//  Module 1 — Asset Attachments API
//
//  Routes:
//    GET    /api/attachments.php?asset_id=X         → list attachments for asset
//    GET    /api/attachments.php?id=X&action=download → download/serve a file
//    POST   /api/attachments.php                    → upload file (multipart/form-data)
//    DELETE /api/attachments.php?id=X               → delete attachment
//
//  Upload expects multipart/form-data with fields:
//    file        (required) — the file
//    asset_id    (required) — asset to attach to
//    label       (optional) — Invoice, Photo, Manual, Warranty, Other
//    uploaded_by (required) — user id
// ============================================================

require_once __DIR__ . '/../config/database.php';

// NOTE: api_helpers.php sets Content-Type: application/json globally,
// but the download route needs to override that — so we include it
// after we check the action.
$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// Download must stream before any JSON header is set
if ($method === 'GET' && $action === 'download') {
    handle_download();
    exit;
}

require_once __DIR__ . '/../includes/api_helpers.php';

$id      = isset($_GET['id'])       ? (int) $_GET['id']       : null;
$assetId = isset($_GET['asset_id']) ? (int) $_GET['asset_id'] : null;

match (true) {
    $method === 'GET' && $assetId => list_attachments(db(), $assetId),
    $method === 'POST'            => handle_upload(db()),
    $method === 'DELETE' && $id   => handle_delete(db(), $id),
    default                       => respond_error('Invalid route.', 404),
};

// ── Config ───────────────────────────────────────────────────

/**
 * Base upload directory — relative to this file.
 * Files are stored as:  uploads/assets/{asset_id}/{filename}
 * Adjust path if your server layout differs.
 */
function upload_base(): string {
    $base = dirname(__DIR__) . '/uploads/assets';
    if (!is_dir($base)) {
        mkdir($base, 0755, true);
    }
    return $base;
}

const ALLOWED_MIME = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain', 'text/csv',
];

const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

const ALLOWED_LABELS = ['Invoice', 'Photo', 'Manual', 'Warranty', 'Contract', 'Other'];

// ── LIST ─────────────────────────────────────────────────────
function list_attachments(PDO $pdo, int $assetId): never {
    allow_methods('GET');

    // Verify asset exists
    $chk = $pdo->prepare("SELECT id FROM assets WHERE id = :id AND is_active = 1");
    $chk->execute([':id' => $assetId]);
    if (!$chk->fetch()) respond_error('Asset not found.', 404);

    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name AS uploaded_by_name
        FROM asset_attachments a
        LEFT JOIN users u ON u.id = a.uploaded_by
        WHERE a.asset_id = :asset_id
        ORDER BY a.uploaded_at DESC
    ");
    $stmt->execute([':asset_id' => $assetId]);
    $rows = $stmt->fetchAll();

    // Add download URL to each row
    foreach ($rows as &$row) {
        $row['download_url'] = "api/attachments.php?action=download&id={$row['id']}";
        $row['file_size_kb'] = $row['file_size'] ? round($row['file_size'] / 1024, 1) . ' KB' : null;
    }

    respond_ok($rows);
}

// ── UPLOAD ───────────────────────────────────────────────────
function handle_upload(PDO $pdo): never {
    allow_methods('POST');

    // Validate required POST fields
    $assetId    = isset($_POST['asset_id'])    && $_POST['asset_id']    !== '' ? (int) $_POST['asset_id']    : null;
    $uploadedBy = isset($_POST['uploaded_by']) && $_POST['uploaded_by'] !== '' ? (int) $_POST['uploaded_by'] : null;
    $label      = $_POST['label'] ?? 'Other';

    if (!$assetId)    respond_error("Field 'asset_id' is required.");
    if (!$uploadedBy) respond_error("Field 'uploaded_by' is required.");
    if (!in_array($label, ALLOWED_LABELS, true)) {
        respond_error("Invalid label. Allowed: " . implode(', ', ALLOWED_LABELS));
    }

    // Verify asset
    $chk = $pdo->prepare("SELECT id FROM assets WHERE id = :id AND is_active = 1");
    $chk->execute([':id' => $assetId]);
    if (!$chk->fetch()) respond_error('Asset not found.', 404);

    // Check file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        respond_error("No file uploaded. Send file via 'file' field in multipart/form-data.");
    }

    $file = $_FILES['file'];

    // Upload error check
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
        ];
        respond_error($errMap[$file['error']] ?? 'Unknown upload error.');
    }

    // Size check
    if ($file['size'] > MAX_FILE_SIZE) {
        respond_error('File too large. Maximum size is ' . (MAX_FILE_SIZE / 1024 / 1024) . ' MB.');
    }

    // MIME type check (use finfo for accuracy, not user-supplied type)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, ALLOWED_MIME, true)) {
        respond_error("File type '{$mimeType}' is not allowed.");
    }

    // Build safe filename: timestamp + sanitized original name
    $originalName = basename($file['name']);
    $safeName     = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $originalName);
    $uniqueName   = time() . '_' . $safeName;

    // Create asset-specific folder
    $assetDir = upload_base() . '/' . $assetId;
    if (!is_dir($assetDir)) {
        mkdir($assetDir, 0755, true);
    }

    $destPath     = $assetDir . '/' . $uniqueName;
    $relativePath = "uploads/assets/{$assetId}/{$uniqueName}";

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        respond_error('Failed to save file. Check server write permissions.', 500);
    }

    // Save record to DB
    $pdo->prepare("
        INSERT INTO asset_attachments (asset_id, file_name, file_path, file_type, file_size, label, uploaded_by)
        VALUES (:asset_id, :file_name, :file_path, :file_type, :file_size, :label, :uploaded_by)
    ")->execute([
        ':asset_id'   => $assetId,
        ':file_name'  => $originalName,
        ':file_path'  => $relativePath,
        ':file_type'  => $mimeType,
        ':file_size'  => $file['size'],
        ':label'      => $label,
        ':uploaded_by'=> $uploadedBy,
    ]);

    $newId = (int) $pdo->lastInsertId();

    respond_ok([
        'id'           => $newId,
        'file_name'    => $originalName,
        'file_path'    => $relativePath,
        'file_type'    => $mimeType,
        'file_size'    => $file['size'],
        'download_url' => "api/attachments.php?action=download&id={$newId}",
    ], 'File uploaded successfully.', 201);
}

// ── DOWNLOAD / SERVE ─────────────────────────────────────────
function handle_download(): void {
    // No JSON headers here — we stream the file
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (!$id) {
        http_response_code(400);
        echo 'Missing id.';
        return;
    }

    try {
        $pdo  = db();
        $stmt = $pdo->prepare("SELECT * FROM asset_attachments WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        http_response_code(500);
        echo 'Database error.';
        return;
    }

    if (!$row) {
        http_response_code(404);
        echo 'Attachment not found.';
        return;
    }

    $filePath = dirname(__DIR__) . '/' . $row['file_path'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'File not found on disk.';
        return;
    }

    // Inline for images/PDFs, attachment (download) for everything else
    $inlineTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $disposition = in_array($row['file_type'], $inlineTypes, true) ? 'inline' : 'attachment';

    header('Content-Type: '        . $row['file_type']);
    header('Content-Length: '      . filesize($filePath));
    header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($row['file_name']) . '"');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($filePath);
}

// ── DELETE ───────────────────────────────────────────────────
function handle_delete(PDO $pdo, int $id): never {
    allow_methods('DELETE');

    $stmt = $pdo->prepare("SELECT * FROM asset_attachments WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) respond_error('Attachment not found.', 404);

    // Delete physical file
    $filePath = dirname(__DIR__) . '/' . $row['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Delete DB record
    $pdo->prepare("DELETE FROM asset_attachments WHERE id = :id")->execute([':id' => $id]);

    respond_ok(null, 'Attachment deleted.');
}
