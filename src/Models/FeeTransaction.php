<?php

namespace Repay\Fee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Repay\Fee\Enums\FeeTransactionStatus;
use Repay\Fee\Enums\FeeType;

class FeeTransaction extends Model
{
    use HasFactory;

    protected $table = 'fee_transactions';

    protected $fillable = [
        'transaction_id',
        'fee_rule_id',
        'fee_bearer_type',    // Who pays the fee (User, Merchant, etc.)
        'fee_bearer_id',
        'feeable_type',       // What the fee is applied to (Order, Payment, Invoice, etc.)
        'feeable_id',
        'transaction_amount',
        'fee_amount',
        'status',
        'reference_number',   // External reference (txn_no, invoice_id, etc.)
        'fee_type',
        'currency',
        'metadata',           // Additional data (rate_used, calculations, etc.)
        'applied_at',
    ];

    protected $casts = [
        'transaction_amount' => 'decimal:4',
        'fee_amount' => 'decimal:4',
        'fee_type' => FeeType::class,
        'status' => FeeTransactionStatus::class,
        'metadata' => 'array',
        'applied_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->applied_at)) {
                $model->applied_at = now();
            }
        });
    }

    public function feeRule(): BelongsTo
    {
        return $this->belongsTo(FeeRule::class);
    }

    public function feeBearer(): MorphTo
    {
        return $this->morphTo();
    }

    public function feeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForFeeBearer($query, $bearer)
    {
        return $query->where('fee_bearer_type', get_class($bearer))
            ->where('fee_bearer_id', $bearer->getKey());
    }

    public function scopeForFeeable($query, $feeable)
    {
        return $query->where('feeable_type', get_class($feeable))
            ->where('feeable_id', $feeable->getKey());
    }

    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeInDateRange($query, $startDate, $endDate = null)
    {
        $query->whereDate('applied_at', '>=', $startDate);
        
        if ($endDate) {
            $query->whereDate('applied_at', '<=', $endDate);
        }
        
        return $query;
    }
}
