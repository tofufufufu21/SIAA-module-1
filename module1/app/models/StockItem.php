<?php
// app/models/StockItem.php

namespace App\Models;

/**
 * StockItem — mirrors `stock_items` table.
 */
class StockItem
{
    public int     $id;
    public string  $item_code;
    public string  $name;
    public ?string $description;
    public ?int    $category_id;
    public string  $unit_of_measure = 'pcs';
    public ?string $compatible_models;
    public int     $has_expiry      = 0;
    public ?float  $unit_cost;
    public int     $is_active       = 1;
    public ?string $created_at;
    public ?string $updated_at;

    // Joined
    public ?string $category_name;
    public float   $total_qty_on_hand = 0;

    public static function fromArray(array $row): self
    {
        $i = new self();
        foreach ($row as $k => $v) {
            if (property_exists($i, $k)) $i->$k = $v;
        }
        return $i;
    }
}

/**
 * StockTransaction — mirrors `stock_transactions` table.
 */
class StockTransaction
{
    public const TYPE_GRN       = 'GRN';
    public const TYPE_ISSUE     = 'Issue';
    public const TYPE_TRANSFER  = 'Transfer';
    public const TYPE_ADJUST    = 'Adjustment';
    public const TYPE_CYCLE_ADJ = 'CycleCountAdj';

    public const REASON_NORMAL      = 'Normal';
    public const REASON_DAMAGE      = 'Damage';
    public const REASON_LOSS        = 'Loss';
    public const REASON_AUDIT       = 'AuditCorrection';
    public const REASON_PROJECT     = 'ProjectAllocation';
    public const REASON_RETURN      = 'Return';

    public int     $id;
    public int     $item_id;
    public string  $transaction_type;
    public float   $quantity;
    public ?int    $from_location_id;
    public ?int    $to_location_id;
    public ?int    $batch_id;
    public ?string $reference_type;
    public ?int    $reference_id;
    public string  $reason_code     = self::REASON_NORMAL;
    public ?string $notes;
    public int     $performed_by;
    public ?string $performed_at;

    // Joined
    public ?string $item_code;
    public ?string $item_name;
    public ?string $unit_of_measure;
    public ?string $from_location_name;
    public ?string $to_location_name;
    public ?string $performed_by_name;
}
