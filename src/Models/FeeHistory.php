<?php

namespace Repay\Fee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Repay\Fee\Enums\CalculationType;

/**
 * @property int $id
 * @property int $fee_rule_id
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string $action // created, updated, deactivated
 * @property array|null $old_data
 * @property array|null $new_data
 * @property string|null $reason
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property FeeRule $feeRule
 *
 * @property-read array{
 *     amount: string,
 *     type: string,
 *     item_type: string
 * } $formatted_changes Human-readable changes between old and new data
 *
 * @property-read array{
 *   id?: array{old: int|null, new: int|null},
 *   value?: array{old: string|null, new: string|null
 *   , value_formatted?: array{old: string|null, new: string|null}},
 *   fee_type?: array{old: string|null, new: string|null},
 *   entity_id?: array{old: int|null, new: int|null},
 *   is_active?: array{old: bool|null, new: bool|null},
 *   is_global?: array{old: bool|null, new: bool|null},
 *   item_type?: array{old: string|null, new: string|null},
 *   created_at?: array{old: string|null, new: string|null},
 *   updated_at?: array{old: string|null, new: string|null},
 *   entity_type?: array{old: string|null, new: string|null},
 *   effective_from?: array{old: string|null, new: string|null},
 *   calculation_type?: array{old: int|null, new: int|null},
 *   apply_to_existing_entity?: array{old: bool|null, new: bool|null}
 * } $changes Raw diff between old_data and new_data
 *
 * @method static \Illuminate\Database\Eloquent\Builder|FeeHistory whereFeeRuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FeeHistory whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FeeHistory whereEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FeeHistory whereEntityId($value)
 */
class FeeHistory extends Model
{
    protected $table = 'fee_histories';

    protected $fillable = [
        'fee_rule_id',
        'entity_type',
        'entity_id',
        'action',
        'old_data',
        'new_data',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
    ];

    public function feeRule()
    {
        return $this->belongsTo(FeeRule::class);
    }

    /**
     * Format a value based on calculation type.
     */
    private function formatValue(?string $value, ?int $calcType): ?string
    {
        if ($value === null) return null;

        return $calcType === CalculationType::PERCENTAGE->value
            ? number_format((float)$value, 2) . '%'
            : Number::currency((float)$value, 'PHP');
    }

    /**
     * Human-readable changes (formatted like "55.00 -> 45%").
     *
     * @return array{amount?: string, type?: string, item_type?: string}
     */
    public function getFormattedChangesAttribute(): array
    {
        $old = $this->old_data ?? [];
        $new = $this->new_data ?? [];

        $changes = [
            'amount' => '-',
            'type' => '-',
            'item_type' => '-',
        ];

        if (!empty($old)) {
            if (isset($old['value'], $new['value'], $new['calculation_type'])) {
                $oldValue = $this->formatValue($old['value'], $old['calculation_type']);
                $newValue = $this->formatValue($new['value'], $new['calculation_type']);
                $changes['amount'] = "$oldValue -> $newValue";
            }

            if (isset($old['fee_type'], $new['fee_type']) && $new['fee_type'] !== $old['fee_type']) {
                $changes['type'] = "{$old['fee_type']} -> {$new['fee_type']}";
            } else {
                $changes['type'] = $old['fee_type'];
            }

            if (isset($old['item_type'], $new['item_type']) && $new['item_type'] !== $old['item_type'] ) {
                $changes['item_type'] = "{$old['item_type']} -> {$new['item_type']}";
            } else {
                $changes['item_type'] = "{$new['item_type']}";
            }
        }

        return $changes;
    }

    /**
     * Raw diff between old_data and new_data with optional value formatting.
     *
     * @return array<string, array{old: mixed, new: mixed, value_formatted?: array{old: string|null, new: string|null}}>
     */
    public function getChangesAttribute(): array
    {
        $old = $this->old_data ?? [];
        $new = $this->new_data ?? [];

        if (empty($new)) return [];

        $changes = [];

        foreach ($new as $key => $newValue) {
            $oldValue = $old[$key] ?? null;

            $entry = [
                'old' => $oldValue,
                'new' => $newValue,
            ];

            // Add formatted value for 'value' key only
            if ($key === 'value') {
                $calcType = $new['calculation_type'] ?? null;
                $oldType = $old['calculation_type'] ?? null;
                $entry['value_formatted'] = [
                    'old' => $this->formatValue($oldValue, $oldType),
                    'new' => $this->formatValue($newValue, $calcType),
                ];
            }

            if ($oldValue !== $newValue) {
                $changes[$key] = $entry;
            }
        }

        return $changes;
    }
}

