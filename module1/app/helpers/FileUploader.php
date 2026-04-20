<?php
// app/helpers/FileUploader.php

namespace App\Helpers;

/**
 * FileUploader — handles secure file uploads.
 * Reusable by asset attachments and any future modules.
 */
class FileUploader
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/app.php';
    }

    /**
     * Upload a file from $_FILES.
     *
     * @param array $file $_FILES['file'] element
     * @param int   $assetId For directory scoping
     * @return array ['path' => relative_path, 'name' => original_name, 'type' => mime, 'size' => bytes]
     * @throws \RuntimeException on failure
     */
    public function upload(array $file, int $assetId): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadError($file['error'] ?? -1));
        }

        if ($file['size'] > $this->config['max_file_size']) {
            throw new \RuntimeException('File too large. Maximum ' . ($this->config['max_file_size'] / 1024 / 1024) . ' MB.');
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $this->config['allowed_mime'], true)) {
            throw new \RuntimeException("File type '{$mimeType}' is not allowed.");
        }

        $originalName = basename($file['name']);
        $safeName     = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $originalName);
        $uniqueName   = time() . '_' . $safeName;

        $dir = rtrim($this->config['upload_dir'], '/') . '/' . $assetId;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $destPath     = $dir . '/' . $uniqueName;
        $relativePath = 'uploads/assets/' . $assetId . '/' . $uniqueName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new \RuntimeException('Failed to save file. Check server permissions.');
        }

        return [
            'path' => $relativePath,
            'name' => $originalName,
            'type' => $mimeType,
            'size' => $file['size'],
        ];
    }

    public function delete(string $relativePath): bool
    {
        $full = rtrim($this->config['upload_dir'], '/') . '/../../' . $relativePath;
        $abs  = realpath(dirname(__DIR__, 2) . '/public/' . $relativePath);
        return $abs && file_exists($abs) && unlink($abs);
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds upload size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            default               => 'Unknown upload error.',
        };
    }
}
