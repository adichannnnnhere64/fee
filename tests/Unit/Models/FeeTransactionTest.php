<?php

use Repay\Fee\Enums\FeeTransactionStatus;
use Repay\Fee\Enums\FeeType;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;

beforeEach(function () {
    $this->user = $this->mockEntity('User', 1);
    $this->merchant = $this->mockEntity('Merchant', 2);
    $this->order = $this->mockEntity('Order', 3);

    // Clear existing data
    FeeTransaction::query()->delete();
    FeeRule::query()->delete();
});

test('fee transaction model can be created', function () {
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->merchant),
        'entity_id' => $this->merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $transaction = FeeTransaction::create([
        'transaction_id' => 'TXN-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 100.00,
        'fee_amount' => 10.00,
        'fee_type' => 'markup',
        'status' => 'applied',
        'reference_number' => 'INV-001',
        'currency' => 'PHP',
        'metadata' => ['test' => 'data'],
    ]);

    expect($transaction)
        ->not()->toBeNull()
        ->transaction_id->toBe('TXN-001')
        ->fee_rule_id->toBe($feeRule->id)
        ->fee_bearer_type->toBe(get_class($this->user))
        ->fee_bearer_id->toBe($this->user->id)
        ->feeable_type->toBe(get_class($this->order))
        ->feeable_id->toBe($this->order->id)
        ->transaction_amount->toBe('100.0000')
        ->fee_amount->toBe('10.0000')
        ->fee_type->toBe(FeeType::MARKUP)
        ->status->toBe(FeeTransactionStatus::APPLIED)
        ->reference_number->toBe('INV-001')
        ->currency->toBe('PHP')
        ->metadata->toBe(['test' => 'data']);
});

test('fee transaction model casts enums correctly', function () {
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->merchant),
        'entity_id' => $this->merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $transaction = FeeTransaction::create([
        'transaction_id' => 'TXN-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 100.00,
        'fee_amount' => 10.00,
        'fee_type' => FeeType::MARKUP->value,
        'status' => FeeTransactionStatus::APPLIED->value,
        'reference_number' => 'INV-001',
        'currency' => 'PHP',
    ]);

    expect($transaction->fee_type)
        ->toBeInstanceOf(FeeType::class);

    expect($transaction->fee_type->value)->toBe('markup');

    expect($transaction->status)
        ->toBeInstanceOf(FeeTransactionStatus::class);

    expect($transaction->status->value)->toBe('applied');
});

test('fee transaction model relationships work correctly', function () {
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->merchant),
        'entity_id' => $this->merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $transaction = FeeTransaction::create([
        'transaction_id' => 'TXN-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 100.00,
        'fee_amount' => 10.00,
        'fee_type' => 'markup',
        'status' => 'applied',
    ]);

    // Test fee rule relationship
    expect($transaction->feeRule)
        ->not()->toBeNull()
        ->id->toBe($feeRule->id);

    // Test morph relationships (they won't resolve without actual models)
    // But we can test the methods exist
    expect(method_exists($transaction, 'feeBearer'))->toBeTrue();
    expect(method_exists($transaction, 'feeable'))->toBeTrue();
});

test('scope for fee bearer filters correctly', function () {
    $feeRule = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    // Create transactions for different bearers
    FeeTransaction::create([
        'transaction_id' => 'TXN-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => 1,
        'transaction_amount' => 100.00,
        'fee_amount' => 10.00,
        'fee_type' => 'markup',
        'status' => 'applied',
    ]);

    FeeTransaction::create([
        'transaction_id' => 'TXN-002',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->merchant),
        'fee_bearer_id' => $this->merchant->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => 2,
        'transaction_amount' => 200.00,
        'fee_amount' => 20.00,
        'fee_type' => 'markup',
        'status' => 'applied',
    ]);

    $userTransactions = FeeTransaction::forFeeBearer($this->user)->get();
    $merchantTransactions = FeeTransaction::forFeeBearer($this->merchant)->get();

    expect($userTransactions)->toHaveCount(1)
        ->and($userTransactions->first()->transaction_id)->toBe('TXN-001')
        ->and($merchantTransactions)->toHaveCount(1)
        ->and($merchantTransactions->first()->transaction_id)->toBe('TXN-002');
});

test('scope for feeable filters correctly', function () {
    $feeRule = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    $order1 = $this->mockEntity('Order', 1);
    $order2 = $this->mockEntity('Order', 2);

    FeeTransaction::create([
        'transaction_id' => 'TXN-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($order1),
        'feeable_id' => $order1->id,
        'transaction_amount' => 100.00,
        'fee_amount' => 10.00,
        'fee_type' => 'markup',
        'status' => 'applied',
    ]);

    FeeTransaction::create([
        'transaction_id' => 'TXN-002',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($order2),
        'feeable_id' => $order2->id,
        'transaction_amount' => 200.00,
        'fee_amount' => 20.00,
        'fee_type' => 'markup',
        'status' => 'applied',
    ]);

    $order1Transactions = FeeTransaction::forFeeable($order1)->get();
    $order2Transactions = FeeTransaction::forFeeable($order2)->get();

    expect($order1Transactions)->toHaveCount(1)
        ->and($order1Transactions->first()->transaction_id)->toBe('TXN-001')
        ->and($order2Transactions)->toHaveCount(1)
        ->and($order2Transactions->first()->transaction_id)->toBe('TXN-002');
});

test('scope with status filters correctly', function () {
    $feeRule = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    FeeTransaction::create([
        'transaction_id' => 'TXN-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 100.00,
        'fee_amount' => 10.00,
        'fee_type' => 'markup',
        'status' => 'applied',
    ]);

    FeeTransaction::create([
        'transaction_id' => 'TXN-002',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 200.00,
        'fee_amount' => 20.00,
        'fee_type' => 'markup',
        'status' => 'pending',
    ]);

    FeeTransaction::create([
        'transaction_id' => 'TXN-003',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 300.00,
        'fee_amount' => 30.00,
        'fee_type' => 'markup',
        'status' => 'reversed',
    ]);

    $appliedTransactions = FeeTransaction::withStatus('applied')->get();
    $pendingTransactions = FeeTransaction::withStatus('pending')->get();
    $reversedTransactions = FeeTransaction::withStatus('reversed')->get();

    expect($appliedTransactions)->toHaveCount(1)
        ->and($appliedTransactions->first()->transaction_id)->toBe('TXN-001')
        ->and($pendingTransactions)->toHaveCount(1)
        ->and($pendingTransactions->first()->transaction_id)->toBe('TXN-002')
        ->and($reversedTransactions)->toHaveCount(1)
        ->and($reversedTransactions->first()->transaction_id)->toBe('TXN-003');
});

test('scope in date range filters correctly', function () {
    $feeRule = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    // Create transactions on different dates
    FeeTransaction::create([
        'transaction_id' => 'TXN-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 100.00,
        'fee_amount' => 10.00,
        'fee_type' => 'markup',
        'status' => 'applied',
        'applied_at' => '2024-01-01 10:00:00',
    ]);

    FeeTransaction::create([
        'transaction_id' => 'TXN-002',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 200.00,
        'fee_amount' => 20.00,
        'fee_type' => 'markup',
        'status' => 'applied',
        'applied_at' => '2024-01-15 10:00:00',
    ]);

    FeeTransaction::create([
        'transaction_id' => 'TXN-003',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 300.00,
        'fee_amount' => 30.00,
        'fee_type' => 'markup',
        'status' => 'applied',
        'applied_at' => '2024-01-31 10:00:00',
    ]);

    // Test start date only
    $jan15Onwards = FeeTransaction::inDateRange('2024-01-15')->get();
    expect($jan15Onwards)->toHaveCount(2); // Jan 15 & Jan 31

    // Test start and end date
    $jan1To15 = FeeTransaction::inDateRange('2024-01-01', '2024-01-15')->get();
    expect($jan1To15)->toHaveCount(2); // Jan 1 & Jan 15

    // Test specific date range
    $jan10To20 = FeeTransaction::inDateRange('2024-01-10', '2024-01-20')->get();
    expect($jan10To20)->toHaveCount(1); // Jan 15 only
    expect($jan10To20->first()->transaction_id)->toBe('TXN-002');
});

test('applied_at is set automatically on creation', function () {
    $feeRule = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    // Test without applied_at
    $transaction1 = FeeTransaction::create([
        'transaction_id' => 'TXN-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 100.00,
        'fee_amount' => 10.00,
        'fee_type' => 'markup',
        'status' => 'applied',
    ]);

    expect($transaction1->applied_at)->not()->toBeNull();

    // Test with custom applied_at
    $customDate = now()->subDays(5);
    $transaction2 = FeeTransaction::create([
        'transaction_id' => 'TXN-002',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 200.00,
        'fee_amount' => 20.00,
        'fee_type' => 'markup',
        'status' => 'applied',
        'applied_at' => $customDate,
    ]);

    expect($transaction2->applied_at->toDateTimeString())
        ->toBe($customDate->toDateTimeString());
});

test('metadata is properly cast to array', function () {
    $feeRule = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    $metadata = [
        'order_type' => 'online',
        'payment_method' => 'credit_card',
        'items' => [
            ['id' => 1, 'name' => 'Product A', 'price' => 100.00],
        ],
        'tax_amount' => 12.00,
        'discount_applied' => 10.00,
    ];

    $transaction = FeeTransaction::create([
        'transaction_id' => 'TXN-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 100.00,
        'fee_amount' => 10.00,
        'fee_type' => 'markup',
        'status' => 'applied',
        'metadata' => $metadata,
    ]);

    expect($transaction->metadata)
        ->toBeArray()
        ->toMatchArray($metadata)
        ->order_type->toBe('online')
        ->payment_method->toBe('credit_card')
        ->items->toBeArray();

    expect($transaction->metadata['items'][0]['id'])->toBe(1);
});
