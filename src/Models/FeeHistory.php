<?php

namespace Repay\Fee\Models;

use Illuminate\Database\Eloquent\Model;

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
}
