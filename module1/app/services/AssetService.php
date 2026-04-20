<?php
// app/services/AssetService.php

namespace App\Services;

use App\Core\Validator;
use App\Helpers\FileUploader;
use App\Models\Asset;
use App\Repositories\AssetRepository;

/**
 * AssetService — contains ALL business logic for assets.
 * Controllers call this. Repositories handle DB only.
 */
class AssetService
{
    private AssetRepository $repo;

    public function __construct()
    {
        $this->repo = new AssetRepository();
    }

    // ── LIST ─────────────────────────────────────────────────

    public function listAssets(array $filters, int $page, int $perPage): array
    {
        return $this->repo->findAll($filters, $page, $perPage);
    }

    // ── VIEW ─────────────────────────────────────────────────

    public function getAsset(int $id): array
    {
        $asset = $this->repo->findById('assets', $id);
        if (!$asset) throw new \RuntimeException('Asset not found.', 404);

        $asset['lifecycle_log'] = $this->repo->getLifecycleLog($id, 10);
        $asset['attachments']   = $this->repo->getAttachments($id);
        $asset['children']      = $this->repo->findChildren($id);
        return $asset;
    }

    // ── CREATE ───────────────────────────────────────────────

    public function createAsset(array $input, int $performedBy): array
    {
        // Validate
        $v = Validator::make($input)
            ->required('asset_tag',   'Asset Tag')
            ->required('category_id', 'Category')
            ->maxLength('asset_tag', 100, 'Asset Tag')
            ->inList('status', Asset::STATUSES, 'Status')
            ->date('date_acquired',  'Date Acquired')
            ->date('warranty_start', 'Warranty Start')
            ->date('warranty_end',   'Warranty End');

        if ($v->fails()) throw new \InvalidArgumentException($v->firstError());

        // Business rule: unique asset_tag
        if ($this->repo->tagExists($v->get('asset_tag'))) {
            throw new \InvalidArgumentException("Asset tag '{$v->get('asset_tag')}' already exists.");
        }

        $status = $v->get('status', Asset::STATUS_IN_STOCK);

        $data = [
            'asset_tag'            => $v->get('asset_tag'),
            'serial_number'        => $v->get('serial_number'),
            'barcode'              => $v->get('barcode'),
            'category_id'          => (int) $v->get('category_id'),
            'make'                 => $v->get('make'),
            'model'                => $v->get('model'),
            'cpu'                  => $v->get('cpu'),
            'ram'                  => $v->get('ram'),
            'storage'              => $v->get('storage'),
            'os'                   => $v->get('os'),
            'firmware_version'     => $v->get('firmware_version'),
            'vendor_id'            => $v->get('vendor_id') ? (int) $v->get('vendor_id') : null,
            'po_number'            => $v->get('po_number'),
            'invoice_number'       => $v->get('invoice_number'),
            'purchase_cost'        => $v->get('purchase_cost') ? (float) $v->get('purchase_cost') : null,
            'date_acquired'        => $v->get('date_acquired'),
            'warranty_start'       => $v->get('warranty_start'),
            'warranty_end'         => $v->get('warranty_end'),
            'sla_tier'             => $v->get('sla_tier'),
            'support_contract_ref' => $v->get('support_contract_ref'),
            'status'               => $status,
            'assigned_user_id'     => $v->get('assigned_user_id') ? (int) $v->get('assigned_user_id') : null,
            'department_id'        => $v->get('department_id') ? (int) $v->get('department_id') : null,
            'location_id'          => $v->get('location_id') ? (int) $v->get('location_id') : null,
            'cost_center'          => $v->get('cost_center'),
            'depreciation_method'  => $v->get('depreciation_method', 'None'),
            'useful_life_years'    => $v->get('useful_life_years') ? (int) $v->get('useful_life_years') : null,
            'salvage_value'        => $v->get('salvage_value') ? (float) $v->get('salvage_value') : null,
            'parent_asset_id'      => $v->get('parent_asset_id') ? (int) $v->get('parent_asset_id') : null,
            'installed_at'         => $v->get('installed_at'),
            'connected_to'         => $v->get('connected_to'),
            'notes'                => $v->get('notes'),
            'created_by'           => $performedBy,
        ];

        $id = $this->repo->create($data);

        $this->logLifecycle($id, [
            'action_type'  => Asset::LIFECYCLE_STATUS_CHG,
            'to_status'    => $status,
            'reason'       => 'Asset created',
            'performed_by' => $performedBy,
        ]);

        return ['id' => $id];
    }

    // ── UPDATE ───────────────────────────────────────────────

    public function updateAsset(int $id, array $input, int $performedBy): array
    {
        $existing = $this->repo->findByIdRaw($id);
        if (!$existing) throw new \RuntimeException('Asset not found.', 404);

        $v = Validator::make($input)
            ->inList('status', Asset::STATUSES, 'Status')
            ->date('date_acquired',  'Date Acquired')
            ->date('warranty_start', 'Warranty Start')
            ->date('warranty_end',   'Warranty End');

        if ($v->fails()) throw new \InvalidArgumentException($v->firstError());

        // If asset_tag is being changed, check uniqueness
        if (!empty($input['asset_tag']) && $input['asset_tag'] !== $existing['asset_tag']) {
            if ($this->repo->tagExists($input['asset_tag'], $id)) {
                throw new \InvalidArgumentException("Asset tag '{$input['asset_tag']}' already exists.");
            }
        }

        $allowed = [
            'asset_tag','serial_number','barcode','category_id','make','model',
            'cpu','ram','storage','os','firmware_version',
            'vendor_id','po_number','invoice_number','purchase_cost','date_acquired',
            'warranty_start','warranty_end','sla_tier','support_contract_ref',
            'status','assigned_user_id','department_id','location_id','cost_center',
            'depreciation_method','useful_life_years','salvage_value',
            'parent_asset_id','installed_at','connected_to','notes',
        ];

        $data = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $input)) {
                $data[$col] = $input[$col] === '' ? null : $input[$col];
            }
        }

        $this->repo->updateById($id, $data);

        if (isset($data['status']) && $data['status'] !== $existing['status']) {
            $this->logLifecycle($id, [
                'action_type'  => Asset::LIFECYCLE_STATUS_CHG,
                'from_status'  => $existing['status'],
                'to_status'    => $data['status'],
                'reason'       => $input['reason'] ?? 'Manual update',
                'performed_by' => $performedBy,
            ]);
        }

        return ['id' => $id];
    }

    // ── TRANSFER ─────────────────────────────────────────────

    public function transferAsset(array $input, int $performedBy): void
    {
        $v = Validator::make($input)
            ->required('asset_id', 'Asset ID');
        if ($v->fails()) throw new \InvalidArgumentException($v->firstError());

        $assetId = (int) $v->get('asset_id');
        $existing = $this->repo->findByIdRaw($assetId);
        if (!$existing) throw new \RuntimeException('Asset not found.', 404);

        $updates = [];
        if (!empty($input['to_user_id']))       $updates['assigned_user_id'] = (int) $input['to_user_id'];
        if (!empty($input['to_department_id']))  $updates['department_id']    = (int) $input['to_department_id'];
        if (!empty($input['to_location_id']))    $updates['location_id']      = (int) $input['to_location_id'];
        $updates['status'] = Asset::STATUS_IN_USE;

        $this->repo->updateById($assetId, $updates);

        $isTransfer = $existing['assigned_user_id'] !== null;

        $this->logLifecycle($assetId, [
            'action_type'      => $isTransfer ? Asset::LIFECYCLE_TRANSFERRED : Asset::LIFECYCLE_ASSIGNED,
            'from_user_id'     => $existing['assigned_user_id'],
            'to_user_id'       => $input['to_user_id'] ?? null,
            'from_location_id' => $existing['location_id'],
            'to_location_id'   => $input['to_location_id'] ?? null,
            'from_status'      => $existing['status'],
            'to_status'        => Asset::STATUS_IN_USE,
            'reason'           => $input['reason'] ?? '',
            'approved_by'      => $input['approved_by'] ?? null,
            'signoff_note'     => $input['signoff_note'] ?? null,
            'performed_by'     => $performedBy,
        ]);
    }

    // ── STATUS CHANGE ─────────────────────────────────────────

    public function changeStatus(int $assetId, string $newStatus, string $reason, int $performedBy): void
    {
        if (!in_array($newStatus, Asset::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status '{$newStatus}'.");
        }

        $existing = $this->repo->findByIdRaw($assetId);
        if (!$existing) throw new \RuntimeException('Asset not found.', 404);

        $this->repo->updateById($assetId, ['status' => $newStatus]);

        $this->logLifecycle($assetId, [
            'action_type'  => Asset::LIFECYCLE_STATUS_CHG,
            'from_status'  => $existing['status'],
            'to_status'    => $newStatus,
            'reason'       => $reason,
            'performed_by' => $performedBy,
        ]);
    }

    // ── LIFECYCLE LOG ─────────────────────────────────────────

    public function logLifecycle(int $assetId, array $data): void
    {
        $this->repo->logLifecycle(array_merge([
            'asset_id'         => $assetId,
            'action_type'      => $data['action_type'],
            'from_user_id'     => $data['from_user_id']     ?? null,
            'to_user_id'       => $data['to_user_id']       ?? null,
            'from_location_id' => $data['from_location_id'] ?? null,
            'to_location_id'   => $data['to_location_id']   ?? null,
            'from_status'      => $data['from_status']      ?? null,
            'to_status'        => $data['to_status']        ?? null,
            'reason'           => $data['reason']           ?? null,
            'approved_by'      => $data['approved_by']      ?? null,
            'signoff_note'     => $data['signoff_note']     ?? null,
            'performed_by'     => $data['performed_by'],
        ]));
    }

    // ── ATTACHMENTS ───────────────────────────────────────────

    public function uploadAttachment(int $assetId, array $file, string $label, int $userId): array
    {
        $asset = $this->repo->findByIdRaw($assetId);
        if (!$asset) throw new \RuntimeException('Asset not found.', 404);

        $uploader = new FileUploader();
        $uploaded = $uploader->upload($file, $assetId);

        $id = $this->repo->createAttachment([
            'asset_id'    => $assetId,
            'file_name'   => $uploaded['name'],
            'file_path'   => $uploaded['path'],
            'file_type'   => $uploaded['type'],
            'file_size'   => $uploaded['size'],
            'label'       => $label,
            'uploaded_by' => $userId,
        ]);

        return array_merge(['id' => $id], $uploaded);
    }

    public function deleteAttachment(int $attachId): void
    {
        $att = $this->repo->getAttachmentById($attachId);
        if (!$att) throw new \RuntimeException('Attachment not found.', 404);

        (new FileUploader())->delete($att['file_path']);
        $this->repo->deleteAttachment($attachId);
    }

    public function getAttachments(int $assetId): array
    {
        $rows = $this->repo->getAttachments($assetId);
        $appCfg = require __DIR__ . '/../../config/app.php';
        foreach ($rows as &$r) {
            $r['download_url'] = '/api/attachment/download?id=' . $r['id'];
            $r['file_size_kb'] = $r['file_size'] ? round($r['file_size'] / 1024, 1) . ' KB' : null;
        }
        return $rows;
    }

    // ── DASHBOARD ─────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        $byStatus = $this->repo->countByStatus();
        $total    = array_sum($byStatus);
        return [
            'total_assets'  => $total,
            'by_status'     => $byStatus,
            'in_use'        => $byStatus[Asset::STATUS_IN_USE]       ?? 0,
            'in_stock'      => $byStatus[Asset::STATUS_IN_STOCK]     ?? 0,
            'under_repair'  => $byStatus[Asset::STATUS_UNDER_REPAIR] ?? 0,
            'retired'       => ($byStatus[Asset::STATUS_RETIRED]  ?? 0) + ($byStatus[Asset::STATUS_DISPOSED] ?? 0),
        ];
    }
}
