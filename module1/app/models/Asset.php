<?php
// app/models/Asset.php

namespace App\Models;

/**
 * Asset — value object / DTO.
 * Represents one row from the `assets` table.
 * NO database logic here.
 */
class Asset
{
    public const STATUS_IN_USE      = 'In-Use';
    public const STATUS_IN_STOCK    = 'In-Stock';
    public const STATUS_UNDER_REPAIR= 'Under Repair';
    public const STATUS_RETIRED     = 'Retired';
    public const STATUS_DISPOSED    = 'Disposed';
    public const STATUS_LOST        = 'Lost';

    public const STATUSES = [
        self::STATUS_IN_USE,
        self::STATUS_IN_STOCK,
        self::STATUS_UNDER_REPAIR,
        self::STATUS_RETIRED,
        self::STATUS_DISPOSED,
        self::STATUS_LOST,
    ];

    public const LIFECYCLE_ASSIGNED    = 'Assigned';
    public const LIFECYCLE_TRANSFERRED = 'Transferred';
    public const LIFECYCLE_CHECKED_OUT = 'CheckedOut';
    public const LIFECYCLE_CHECKED_IN  = 'CheckedIn';
    public const LIFECYCLE_RETIRED     = 'Retired';
    public const LIFECYCLE_DISPOSED    = 'Disposed';
    public const LIFECYCLE_STATUS_CHG  = 'StatusChange';

    // Properties mirror `assets` table columns exactly
    public int     $id;
    public string  $asset_tag;
    public ?string $serial_number;
    public ?string $barcode;
    public int     $category_id;
    public ?string $make;
    public ?string $model;
    public ?string $cpu;
    public ?string $ram;
    public ?string $storage;
    public ?string $os;
    public ?string $firmware_version;
    public ?int    $vendor_id;
    public ?string $po_number;
    public ?string $invoice_number;
    public ?float  $purchase_cost;
    public ?string $date_acquired;
    public ?string $warranty_start;
    public ?string $warranty_end;
    public ?string $sla_tier;
    public ?string $support_contract_ref;
    public string  $status           = self::STATUS_IN_STOCK;
    public ?int    $assigned_user_id;
    public ?int    $department_id;
    public ?int    $location_id;
    public ?string $cost_center;
    public string  $depreciation_method = 'None';
    public ?int    $useful_life_years;
    public ?float  $salvage_value;
    public ?int    $parent_asset_id;
    public ?string $installed_at;
    public ?string $connected_to;
    public ?string $notes;
    public int     $is_active        = 1;
    public ?int    $created_by;
    public ?string $created_at;
    public ?string $updated_at;

    // Joined fields (populated by repository)
    public ?string $category_name;
    public ?string $vendor_name;
    public ?string $assigned_user_name;
    public ?string $department_name;
    public ?string $location_name;
    public ?string $location_full;
    public ?string $parent_asset_tag;

    public static function fromArray(array $row): self
    {
        $a = new self();
        foreach ($row as $k => $v) {
            if (property_exists($a, $k)) $a->$k = $v;
        }
        return $a;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function isWarrantyExpiring(): bool
    {
        if (!$this->warranty_end) return false;
        return strtotime($this->warranty_end) < time() + (30 * 86400);
    }
}
