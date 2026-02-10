<?php

namespace Repay\Fee\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Repay\Fee\Enums\CalculationType;

/**
 * @property int $id
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string $item_type
 * @property string $fee_type
 * @property string $type
 * @property float $value
 * @property CalculationType $calculation_type
 * @property bool $is_active
 * @property bool $is_global
 * @property string $formatted_value
 * @property bool $apply_to_existing_entity
 * @property \Carbon\Carbon|null $effective_from
 * @property \Carbon\Carbon|null $effective_to
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property \Illuminate\Database\Eloquent\Collection|FeeHistory[] $fee_history
 * @property FeeHistory|null $latest_fee_history
 * @method static Builder|FeeRule active()
 * @method static Builder|FeeRule upcoming()
 * @method static Builder|FeeRule global()
 * @method static Builder|FeeRule forItemType(string $itemType)
 * @method static Builder|FeeRule forFeeType(string $feeType)
 * @method static Builder|FeeRule forEntity($entity)
 */
class FeeRule extends Model
{
    use HasFactory;

    protected $table = 'fee_rules';

    protected $fillable = [
        'entity_id',
        'entity_type',
        'item_type',
        'fee_type',
        'value',
        'calculation_type',
        'is_active',
        'is_global',
        'effective_from',
        'created_at',
        'apply_to_existing_entity',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'is_active' => 'boolean',
        'is_global' => 'boolean',
        'apply_to_existing_entity' => 'boolean',
        'effective_from' => 'datetime',
        'calculation_type' => CalculationType::class,
    ];

    protected static function booted()
    {
        static::saving(function (FeeRule $model): void {
            $model->validateRules();
        });
    }

    protected function validateRules(): void
    {
        $allowedTypes = config('fee.fee_types', [
            'product' => ['markup'],
            'service' => ['commission', 'convenience'],
        ]);

        if (! isset($allowedTypes[$this->item_type])) {
            throw new \InvalidArgumentException("Invalid item type: {$this->item_type}");
        }

        if (! in_array($this->fee_type, $allowedTypes[$this->item_type])) {
            throw new \InvalidArgumentException(
                "Fee type '{$this->fee_type}' not allowed for item type '{$this->item_type}'"
            );
        }
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function ($q) use ($now): void {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $now);
            })
            /* ->where(function ($q) use ($now): void { */
            /*     $q->whereNull('effective_to') */
            /*         ->orWhere('effective_to', '>', $now); */
            /* }) */
            ->orderBy('id', 'desc') // Most recent first
            ->limit(1); // Get only the most recent
    }

    public function scopeForEntity(Builder $query, $entity): Builder
    {
        return $query->where('entity_type', get_class($entity))
            ->where('entity_id', $entity->getKey());
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('is_global', true)
            ->whereNull('entity_id')
            ->whereNull('entity_type');
    }

    public function scopeForItemType(Builder $query, string $itemType): Builder
    {
        return $query->where('item_type', $itemType);
    }

    public function scopeForFeeType(Builder $query, string $feeType): Builder
    {
        return $query->where('fee_type', $feeType);
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->effective_from && $this->effective_from > $now) {
            return false;
        }

        /* if ($this->effective_to && $this->effective_to <= $now) { */
        /*     return false; */
        /* } */

        return true;
    }

    // Add to your existing scopes
    public function scopeUpcoming($query)
    {
        $now = now();

        return $query->where('is_active', true)
            ->whereNotNull('effective_from')
            ->where('effective_from', '>', $now)
            ;
        /* ->where(function ($q) use ($now): void { */

        /*     $q->whereNull('effective_to') */
        /*         ->orWhere('effective_to', '>', $now); */
        /* }); */
    }

    public function calculate(float $amount): float
    {
        if ($this->calculation_type === CalculationType::PERCENTAGE) {
            return $amount * ($this->value / 100);
        }

        return $this->value;
    }

    // In Fee model

    // In Fee model
    /**
     * Get the current active global fee for the same item_type and fee_type
     */
    public function getCurrentGlobalFee(): ?self
    {
        return self::whereNull('entity_type')
            ->whereNull('entity_id')
            ->where('item_type', $this->item_type)
            ->where('fee_type', $this->fee_type)
            ->active()
            ->orderBy('effective_from', 'desc')
            ->first();
    }

    /**
     * Get all global fee attributes (for reverting)
     */
    public function getGlobalFeeAttributes(): array
    {
        $globalFee = $this->getCurrentGlobalFee();

        if (! $globalFee) {
            throw new \Exception("No active global fee found for {$this->item_type}/{$this->fee_type}");
        }

        return [
            'value' => $globalFee->value,
            'calculation_type' => $globalFee->calculation_type,
            'item_type' => $globalFee->item_type,
            'fee_type' => $globalFee->fee_type,
            'global_fee_id' => $globalFee->id, // Track which global fee was used
            'global_fee_effective_from' => $globalFee->effective_from, // When the global fee became active
            'is_revert_to_global' => true,
            'apply_to_existing_entity' => $globalFee->apply_to_existing_entity,
        ];
    }

    /**
     * Deactivate this fee and revert to global
     */
    public function revertToGlobal(Carbon $effectiveFrom, string $reason, ?User $user = null): self
    {
        DB::beginTransaction();

        try {
            // Get global fee configuration
            $globalAttributes = $this->getGlobalFeeAttributes();

            // Create a new specific fee that copies global values
            $newFee = self::create([
                'entity_type' => $this->entity_type,
                'entity_id' => $this->entity_id,
                'item_type' => $globalAttributes['item_type'],
                'fee_type' => $globalAttributes['fee_type'],
                'value' => $globalAttributes['value'],
                'calculation_type' => $globalAttributes['calculation_type'],
                'is_active' => true,
                'effective_from' => $effectiveFrom,
                'apply_to_existing_entity' => $globalAttributes['apply_to_existing_entity'],

                // Metadata for tracking
                'parent_fee_id' => $this->id,
                'global_fee_id' => $globalAttributes['global_fee_id'],
                'global_fee_effective_from' => $globalAttributes['global_fee_effective_from'],
                'revert_reason' => $reason,
                'reverted_by_user_id' => $user?->id,
            ]);

            // Deactivate the old fee (set effective_to)
            $this->update([
                /* 'effective_to' => $effectiveFrom, */
                'replaced_by_fee_id' => $newFee->id,
            ]);

            // Log the change
            FeeHistory::create([
                'fee_rule_id' => $newFee->id,
                'action' => 'revert_to_global',
                'old_fee_id' => $this->id,
                'global_fee_id' => $globalAttributes['global_fee_id'],
                'details' => [
                    'reason' => $reason,
                    'old_value' => $this->value,
                    'old_calculation_type' => $this->calculation_type,
                    'new_value' => $newFee->value,
                    'new_calculation_type' => $newFee->calculation_type,
                    'effective_from' => $effectiveFrom,
                    'global_fee_snapshot' => $globalAttributes,
                ],
                'user_id' => $user?->id,
            ]);

            DB::commit();

            return $newFee;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Alternative: Soft deactivation with revert option
     */
    public function deactivateWithRevert(?Carbon $effectiveFrom = null, ?string $reason = null): void
    {
        if ($this->is_global) {
            throw new \Exception('Cannot deactivate global fees directly. Use effective_to instead.');
        }

        if (! $effectiveFrom) {
            $effectiveFrom = now();
        }

        if (! $reason) {
            throw new \Exception('Reason is required for deactivation');
        }

        $this->revertToGlobal($effectiveFrom, $reason);
    }

    public function deactivate(): void
    {
        cache()->purge();
        $this->update([
            'is_active' => false,
        ]);
    }

    /**
     * Get a formatted value string for display
     */
    public function getFormattedValueAttribute(): string
    {
        if ($this->calculation_type === CalculationType::PERCENTAGE) {
            return rtrim(rtrim(number_format($this->value, 2), '0'), '.').'%';
        }

        return number_format($this->value, 2);
    }

        public function fee_history(): HasMany
    {
        return $this->hasMany(FeeHistory::class, 'fee_rule_id', 'id');
    }

    // Optional: latest history
    public function latest_fee_history(): HasOne
    {
        return $this->hasOne(FeeHistory::class, 'fee_rule_id', 'id')
                    ->latest('created_at');

    }

}
