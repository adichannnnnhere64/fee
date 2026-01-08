<?php

namespace Repay\Fee\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Repay\Fee\Contracts\FeeableInterface;
use Repay\Fee\Enums\CalculationType;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;
use Repay\Fee\Tests\Fixtures\Merchant;
use Repay\Fee\Tests\Fixtures\Order;
use Repay\Fee\Traits\HasFee;

beforeEach(function (): void {
    // Create a test model that implements the interface and uses the trait
    $this->testModel = new class extends Model implements FeeableInterface
    {
        use HasFee;

        protected $table = 'test_orders';

        public $id = 1;

        public $amount = 100.00;

        public $item_type = 'product';

        public $merchant;

        public function __construct()
        {
            parent::__construct();
            $this->merchant = new class
            {
                public $id = 999;

                public function getKey()
                {
                    return $this->id;
                }

                public function getMorphClass()
                {
                    return 'App\Models\Merchant';
                }
            };
        }

        public function getFeeEntity()
        {
            return $this->merchant;
        }

        public function getFeeItemType(): string
        {
            return $this->item_type;
        }

        public function getFeeBaseAmount(): float
        {
            return (float) $this->amount;
        }
    };

    $this->testModel->exists = true;
});

test('trait adds fee transaction relationship', function (): void {
    expect(method_exists($this->testModel, 'feeTransaction'))->toBeTrue();

    $relation = $this->testModel->feeTransaction();
    expect($relation)->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphOne::class);
});

test('fee rule attribute returns null when no fee rule exists', function (): void {
    $rule = $this->testModel->fee_rule;
    expect($rule)->toBeNull();
});

test('fee attribute returns zero when no fee rule or transaction', function (): void {
    $fee = $this->testModel->fee;
    expect($fee)->toBe(0.0);
});

test('total with fee attribute returns base amount when no fee', function (): void {
    $total = $this->testModel->total_with_fee;
    expect($total)->toBe(100.0);
});

test('has fee processed attribute returns false when no transaction', function (): void {
    expect($this->testModel->has_fee_processed)->toBeFalse();
});

test('fee rule attribute returns fee rule when exists', function (): void {
    // Create a fee rule for the merchant
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->testModel->merchant),
        'entity_id' => $this->testModel->merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
        'is_global' => false,
    ]);

    $rule = $this->testModel->fee_rule;
    expect($rule)->not()->toBeNull()
        ->id->toBe($feeRule->id)
        ->value->toBe('10.0000');
});

test('fee attribute calculates correctly when fee rule exists', function (): void {
    FeeRule::create([
        'entity_type' => get_class($this->testModel->merchant),
        'entity_id' => $this->testModel->merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
        'is_global' => false,
	'effective_from' => now()->subDays(2)
    ]);

    $fee = $this->testModel->fee;
    expect($fee)->toBe(10.0); // 10% of 100
});

test('total with fee attribute includes calculated fee', function (): void {
    FeeRule::create([
        'entity_type' => get_class($this->testModel->merchant),
        'entity_id' => $this->testModel->merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
        'is_global' => false,
    ]);

    $total = $this->testModel->total_with_fee;
    expect($total)->toBe(110.0); // 100 + 10
});

test('fee attribute uses transaction amount when exists', function (): void {
    $merchant = Merchant::create([
        'name' => 'merchant',
    ]);

    $merchant2 = Merchant::create([
        'name' => 'merchant',
    ]);

    $order = Order::create([
        'name' => 'test',
        'merchant_id' => $merchant->id,
    ]);
    // Create fee transaction
    $feeRule = FeeRule::create([
        'entity_type' => get_class($order->merchant),
        'entity_id' => $order->merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
        'is_global' => false,
    ]);

    FeeTransaction::create([
        'transaction_id' => 'TXN-TEST',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($order->merchant),
        'fee_bearer_id' => $order->merchant->id,
        'feeable_type' => get_class($order),
        'feeable_id' => $order->id,
        'transaction_amount' => 100.00,
        'fee_amount' => 15.00, // Different from calculated 10.00
        'fee_type' => 'markup',
        'status' => 'applied',
    ]);

    $fee = $order->fee;
    expect($fee)->toBe(15.0);
    expect($order->has_fee_processed)->toBeTrue();
});

test('trait works with fixed fee calculation', function (): void {
    $this->testModel->amount = 50.00;

    FeeRule::create([
        'entity_type' => get_class($this->testModel->merchant),
        'entity_id' => $this->testModel->merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 5.0, // Fixed amount
        'calculation_type' => CalculationType::FLAT,
        'is_active' => true,
        'is_global' => false,
    ]);

    $fee = $this->testModel->fee;
    expect($fee)->toBe(5.0); // Fixed fee, not percentage
});

test('process fee method returns error when not implementing interface', function (): void {
    $model = new class extends Model
    {
        use HasFee;

        public $id = 1;
    };
    $model->exists = true;

    $result = $model->processFee();
    expect($result)->toHaveKey('error');
});

test('trait gracefully handles missing fee entity', function (): void {
    $model = new class extends Model implements FeeableInterface
    {
        use HasFee;

        public $id = 1;

        public $amount = 100.00;

        public function getFeeEntity()
        {
            return null;
        }

        public function getFeeItemType(): string
        {
            return 'product';
        }

        public function getFeeBaseAmount(): float
        {
            return (float) $this->amount;
        }
    };

    $model->exists = true;

    $rule = $model->fee_rule;
    expect($rule)->toBeNull();

    $fee = $model->fee;
    expect($fee)->toBe(0.0);
});

test('trait handles service item type', function (): void {
    $this->testModel->item_type = 'service';

    FeeRule::create([
        'entity_type' => get_class($this->testModel->merchant),
        'entity_id' => $this->testModel->merchant->id,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 15.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
        'is_global' => false,
    ]);

    $rule = $this->testModel->fee_rule;
    expect($rule)->not()->toBeNull()
        ->item_type->toBe('service')
        ->fee_type->toBe('commission');
});
