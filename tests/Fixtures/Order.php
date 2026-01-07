<?php

namespace Repay\Fee\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Repay\Fee\Contracts\FeeableInterface;
use Repay\Fee\Traits\HasFee;

class Order extends Model implements FeeableInterface
{
    protected $fillable = ['name', 'merchant_id'];

    protected $table = 'orders';

    use HasFee;

    public $id = 1;

    public $amount = 100.00;

    public $item_type = 'product';

    public function __construct()
    {
        parent::__construct();
    }

    public function getFeeEntity()
    {
        return $this->merchant;
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function getFeeItemType(): string
    {
        return $this->item_type;
    }

    public function getFeeBaseAmount(): float
    {
        return (float) $this->amount;
    }
}
