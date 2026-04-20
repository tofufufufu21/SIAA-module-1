<?php
// app/controllers/AttachmentController.php

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\AssetService;

/**
 * AttachmentController — handles file upload/download/delete for assets.
 */
class AttachmentController extends BaseController
{
    private AssetService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new AssetService();
    }

    // GET /attachment/list?asset_id=X
    public function list(): never
    {
        $this->requireMethod('GET');
        $assetId = (int) $this->getQuery('asset_id');
        if (!$assetId) $this->error('asset_id is required.');
        try {
            $this->success($this->service->getAttachments($assetId));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 404);
        }
    }

    // POST /attachment/upload  (multipart/form-data)
    public function upload(): never
    {
        $this->requireMethod('POST');

        $assetId = isset($_POST['asset_id']) ? (int) $_POST['asset_id'] : 0;
        $label   = $_POST['label']       ?? 'Other';
        $userId  = $this->currentUserId();

        if (!$assetId) $this->error('asset_id is required.');
        if (empty($_FILES['file'])) $this->error('No file uploaded.');

        try {
            $result = $this->service->uploadAttachment($assetId, $_FILES['file'], $label, $userId);
            $this->created($result, 'File uploaded.');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    // POST /attachment/delete
    public function delete(): never
    {
        $this->requireMethod('POST');
        $id = (int) ($this->getBody()['id'] ?? 0);
        if (!$id) $this->error('Attachment ID required.');
        try {
            $this->service->deleteAttachment($id);
            $this->success(null, 'Attachment deleted.');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 404);
        }
    }

    // GET /attachment/download?id=X  — streams the file
    public function download(): never
    {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) { http_response_code(400); echo 'Missing id.'; exit; }

        // Bypass JSON headers for streaming
        $repo = new \App\Repositories\AssetRepository();
        $att  = $repo->getAttachmentById($id);
        if (!$att) { http_response_code(404); echo 'Not found.'; exit; }

        $appCfg   = require __DIR__ . '/../../config/app.php';
        $fullPath = dirname(__DIR__, 2) . '/public/' . $att['file_path'];

        if (!file_exists($fullPath)) { http_response_code(404); echo 'File missing.'; exit; }

        $inline = in_array($att['file_type'], ['image/jpeg','image/png','image/gif','image/webp','application/pdf'], true);

        header('Content-Type: '        . $att['file_type']);
        header('Content-Length: '      . filesize($fullPath));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($att['file_name']) . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($fullPath);
        exit;
    }
}
