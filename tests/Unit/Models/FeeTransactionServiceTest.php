<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Repay\Fee\Enums\FeeTransactionStatus;
use Repay\Fee\Enums\FeeType;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;
use Repay\Fee\Services\FeeTransactionService;

beforeEach(function () {
    $this->service = new FeeTransactionService;
    $this->user = $this->mockEntity('User', 1);
    $this->merchant = $this->mockEntity('Merchant', 2);
    $this->customer = $this->mockEntity('Customer', 1);
    $this->order = $this->mockEntity('Order', 4);
    $this->payment = $this->mockEntity('Payment', 1);

    // Clear existing data
    FeeTransaction::query()->delete();
    FeeRule::query()->delete();
});

test('recordFee creates fee transaction with all required data', function () {
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

    $transaction = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->customer,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
        transactionId: 'TXN-12345',
        referenceNumber: 'INV-001',
        metadata: [
            'order_type' => 'online',
            'payment_method' => 'credit_card',
        ]
    );

    // Basic assertions
    expect($transaction)->not()->toBeNull();

    // Field-by-field assertions
    expect($transaction->transaction_id)->toBe('TXN-12345');
    expect($transaction->fee_rule_id)->toBe($feeRule->id);
    expect($transaction->fee_bearer_type)->toBe(get_class($this->customer));
    expect($transaction->fee_bearer_id)->toBe($this->customer->id);
    expect($transaction->feeable_type)->toBe(get_class($this->order));
    expect($transaction->feeable_id)->toBe($this->order->id);
    expect($transaction->transaction_amount)->toBe('100.0000');
    expect($transaction->fee_amount)->toBe('10.0000');
    expect($transaction->fee_type)->toBe(FeeType::MARKUP);
    expect($transaction->status)->toBe(FeeTransactionStatus::APPLIED);
    expect($transaction->reference_number)->toBe('INV-001');

    // Metadata assertions
    $metadata = $transaction->metadata;

    expect($metadata)->toBeArray();

    // User-provided metadata
    expect($metadata['order_type'])->toBe('online');
    expect($metadata['payment_method'])->toBe('credit_card');

    // System-added metadata
    expect($metadata['rate_used'])->toBe('10.0000');
    expect($metadata['calculation_type'])->toBe('percentage');
    expect($metadata['is_global'])->toBe(false);

    // Fee rule snapshot
    $snapshot = $metadata['fee_rule_snapshot'];
    expect($snapshot)->toBeArray();

    expect($snapshot['id'])->toBe($feeRule->id);
    expect($snapshot['value'])->toBe('10.0000');
    expect($snapshot['calculation_type'])->toBe('percentage');
    expect($snapshot['item_type'])->toBe('product');
    expect($snapshot['fee_type'])->toBe('markup');
    expect($snapshot['is_active'])->toBe(true);
    expect($snapshot['is_global'])->toBe(false);
    expect($snapshot['entity_type'])->toBe(get_class($this->merchant));
    expect($snapshot['entity_id'])->toBe($this->merchant->id);
});

test('recordFee generates transaction ID if not provided', function () {
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

    $transaction = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->customer,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
    );

    expect($transaction->transaction_id)
        ->not()->toBeNull()
        ->toMatch('/^FEE-\d{14}-\d{4}$/');
});

test('recordFee works with different fee types', function () {
    $markupFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    $commissionFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    $convenienceFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 5.0,
        'calculation_type' => 'fixed',
        'is_active' => true,
        'is_global' => true,
    ]);

    $markupTransaction = $this->service->recordFee(
        feeRule: $markupFee,
        feeBearer: $this->customer,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
    );

    $commissionTransaction = $this->service->recordFee(
        feeRule: $commissionFee,
        feeBearer: $this->customer,
        feeable: $this->order,
        transactionAmount: 200.00,
        feeAmount: 30.00,
    );

    $convenienceTransaction = $this->service->recordFee(
        feeRule: $convenienceFee,
        feeBearer: $this->customer,
        feeable: $this->order,
        transactionAmount: 300.00,
        feeAmount: 5.00,
    );

    expect($markupTransaction->fee_type)->toBe(FeeType::MARKUP)
        ->and($commissionTransaction->fee_type)->toBe(FeeType::COMMISSION)
        ->and($convenienceTransaction->fee_type)->toBe(FeeType::CONVENIENCE)
        ->and($markupTransaction->fee_amount)->toBe('10.0000')
        ->and($commissionTransaction->fee_amount)->toBe('30.0000')
        ->and($convenienceTransaction->fee_amount)->toBe('5.0000');
});

test('reverseFee updates transaction status and adds reversal metadata', function () {
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

    $originalTransaction = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->customer,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
        transactionId: 'TXN-001',
    );

    $reversedTransaction = $this->service->reverseFee(
        transaction: $originalTransaction,
        reason: 'Order cancelled'
    );

    expect($reversedTransaction)
        ->id->toBe($originalTransaction->id)
        ->status->toBe(FeeTransactionStatus::REVERSED)
        ->metadata->toBeArray()
        ->metadata->toHaveKeys(['reversed_at', 'reversal_reason']);
    /* ->metadata['reversal_reason']->toBe('Order cancelled'); */

    expect($reversedTransaction->metadata['reversal_reason'])->toBe('Order cancelled');

    // Verify the transaction was updated in database
    $dbTransaction = FeeTransaction::find($originalTransaction->id);
    expect($dbTransaction->status)->toBe(FeeTransactionStatus::REVERSED);
});

test('reverseFee preserves original metadata while adding reversal data', function () {
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

    $originalMetadata = [
        'order_type' => 'online',
        'payment_method' => 'credit_card',
        'items' => ['Product A', 'Product B'],
    ];

    $originalTransaction = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->customer,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
        transactionId: 'TXN-001',
        metadata: $originalMetadata,
    );

    $reversedTransaction = $this->service->reverseFee(
        transaction: $originalTransaction,
        reason: 'Refund requested'
    );

    // Check that original metadata is preserved
    expect($reversedTransaction->metadata)
        ->order_type->toBe('online')
        ->payment_method->toBe('credit_card')
        ->items->toBe(['Product A', 'Product B'])
        ->reversed_at->not()->toBeNull()
        ->reversal_reason->toBe('Refund requested');
});

test('getFeesForBearer returns paginated results', function () {
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

    // Create 15 transactions for user
    for ($i = 1; $i <= 15; $i++) {
        $this->service->recordFee(
            feeRule: $feeRule,
            feeBearer: $this->user,
            feeable: $this->order,
            transactionAmount: $i * 100,
            feeAmount: $i * 10,
            transactionId: "TXN-USER-$i",
        );
    }

    // Create 5 transactions for merchant (should not appear in user results)
    for ($i = 1; $i <= 5; $i++) {
        $this->service->recordFee(
            feeRule: $feeRule,
            feeBearer: $this->merchant,
            feeable: $this->order,
            transactionAmount: $i * 200,
            feeAmount: $i * 20,
            transactionId: "TXN-MERCHANT-$i",
        );
    }

    $userFees = $this->service->getFeesForBearer($this->user);
    $merchantFees = $this->service->getFeesForBearer($this->merchant);

    expect($userFees)
        ->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class)
        ->total()->toBe(15)
        ->perPage()->toBe(15);

    expect($merchantFees)
        ->total()->toBe(5)
        ->perPage()->toBe(15);

    // Verify user fees only contain user transactions
    $userTransactionIds = collect($userFees->items())->pluck('transaction_id')->toArray();
    expect($userTransactionIds)->not()->toContain('TXN-MERCHANT-1');
});

test('getFeesForBearer applies status filter correctly', function () {
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

    // Create transactions with different statuses
    $appliedTransaction = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
        transactionId: 'TXN-APPLIED',
    );

    $pendingTransaction = FeeTransaction::create([
        'transaction_id' => 'TXN-PENDING',
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

    // Reverse one transaction
    $reversedTransaction = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 300.00,
        feeAmount: 30.00,
        transactionId: 'TXN-TO-REVERSE',
    );
    $this->service->reverseFee($reversedTransaction, 'Test reversal');

    // Test filters
    $appliedFees = $this->service->getFeesForBearer($this->user, ['status' => 'applied']);
    $pendingFees = $this->service->getFeesForBearer($this->user, ['status' => 'pending']);
    $reversedFees = $this->service->getFeesForBearer($this->user, ['status' => 'reversed']);

    expect($appliedFees->total())->toBe(1)
        ->and($appliedFees->items()[0]->transaction_id)->toBe('TXN-APPLIED')
        ->and($pendingFees->total())->toBe(1)
        ->and($pendingFees->items()[0]->transaction_id)->toBe('TXN-PENDING')
        ->and($reversedFees->total())->toBe(1)
        ->and($reversedFees->items()[0]->transaction_id)->toBe('TXN-TO-REVERSE');
});

test('getFeesForBearer applies date range filter correctly', function () {
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
    $this->travelTo('2024-01-15 10:00:00');
    $transaction1 = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
        transactionId: 'TXN-JAN15',
    );

    $this->travelTo('2024-02-01 10:00:00');
    $transaction2 = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 200.00,
        feeAmount: 20.00,
        transactionId: 'TXN-FEB01',
    );

    $this->travelTo('2024-02-15 10:00:00');
    $transaction3 = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 300.00,
        feeAmount: 30.00,
        transactionId: 'TXN-FEB15',
    );

    $this->travelBack();

    // Test date filters
    $janFees = $this->service->getFeesForBearer($this->user, [
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
    ]);

    $febFees = $this->service->getFeesForBearer($this->user, [
        'start_date' => '2024-02-01',
        'end_date' => '2024-02-28',
    ]);

    $febFirstHalf = $this->service->getFeesForBearer($this->user, [
        'start_date' => '2024-02-01',
        'end_date' => '2024-02-10',
    ]);

    expect($janFees->total())->toBe(1)
        ->and($janFees->items()[0]->transaction_id)->toBe('TXN-JAN15')
        ->and($febFees->total())->toBe(2)
        ->and($febFirstHalf->total())->toBe(1)
        ->and($febFirstHalf->items()[0]->transaction_id)->toBe('TXN-FEB01');
});

test('getFeesForBearer applies fee type filter correctly', function () {
    $markupFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    $commissionFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    // Create markup transactions
    for ($i = 1; $i <= 3; $i++) {
        $this->service->recordFee(
            feeRule: $markupFee,
            feeBearer: $this->user,
            feeable: $this->order,
            transactionAmount: $i * 100,
            feeAmount: $i * 10,
            transactionId: "TXN-MARKUP-$i",
        );
    }

    // Create commission transactions
    for ($i = 1; $i <= 2; $i++) {
        $this->service->recordFee(
            feeRule: $commissionFee,
            feeBearer: $this->user,
            feeable: $this->order,
            transactionAmount: $i * 200,
            feeAmount: $i * 30,
            transactionId: "TXN-COMMISSION-$i",
        );
    }

    $markupFees = $this->service->getFeesForBearer($this->user, ['fee_type' => 'markup']);
    $commissionFees = $this->service->getFeesForBearer($this->user, ['fee_type' => 'commission']);
    $allFees = $this->service->getFeesForBearer($this->user);

    expect($markupFees->total())->toBe(3)
        ->and($commissionFees->total())->toBe(2)
        ->and($allFees->total())->toBe(5);
});

test('getFeesForBearer respects per_page parameter', function () {
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

    // Create 25 transactions
    for ($i = 1; $i <= 25; $i++) {
        $this->service->recordFee(
            feeRule: $feeRule,
            feeBearer: $this->user,
            feeable: $this->order,
            transactionAmount: $i * 100,
            feeAmount: $i * 10,
            transactionId: "TXN-$i",
        );
    }

    /** @var LengthAwarePaginator */
    $defaultPagination = $this->service->getFeesForBearer($this->user);
    $customPagination = $this->service->getFeesForBearer($this->user, ['per_page' => 5]);

    expect($defaultPagination)
        ->perPage()->toBe(15)
        ->total()->toBe(25)
        ->count()->toBe(15); // First page has 15 items

    expect($customPagination)
        ->perPage()->toBe(5)
        ->total()->toBe(25)
        ->count()->toBe(5);
});

test('getTotalFeesForBearer calculates correct totals', function () {
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

    // Create applied transactions
    $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
        transactionId: 'TXN-1',
    );

    $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 200.00,
        feeAmount: 20.00,
        transactionId: 'TXN-2',
    );

    $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 300.00,
        feeAmount: 30.00,
        transactionId: 'TXN-3',
    );

    // Create pending transaction (should not be counted in totals)
    FeeTransaction::create([
        'transaction_id' => 'TXN-PENDING',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($this->user),
        'fee_bearer_id' => $this->user->id,
        'feeable_type' => get_class($this->order),
        'feeable_id' => $this->order->id,
        'transaction_amount' => 400.00,
        'fee_amount' => 40.00,
        'fee_type' => 'markup',
        'status' => 'pending',
    ]);

    // Create reversed transaction (should not be counted in totals)
    $reversed = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 500.00,
        feeAmount: 50.00,
        transactionId: 'TXN-REVERSED',
    );
    $this->service->reverseFee($reversed, 'Test');

    $totals = $this->service->getTotalFeesForBearer($this->user);

    expect($totals)
        ->toHaveKeys(['total_transactions', 'total_fee_amount', 'total_transaction_amount'])
        ->total_transactions->toBe(3) // Only applied transactions
        ->total_fee_amount->toBe(60) // 10 + 20 + 30
        ->total_transaction_amount->toBe(600); // 100 + 200 + 300
});

test('getTotalFeesForBearer applies date range filter', function () {
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

    // Set dates for transactions
    $this->travelTo('2024-01-15 10:00:00');
    $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
        transactionId: 'TXN-JAN',
    );

    $this->travelTo('2024-02-01 10:00:00');
    $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 200.00,
        feeAmount: 20.00,
        transactionId: 'TXN-FEB1',
    );

    $this->travelTo('2024-02-15 10:00:00');
    $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 300.00,
        feeAmount: 30.00,
        transactionId: 'TXN-FEB2',
    );

    $this->travelBack();

    // Test different date ranges
    $janTotals = $this->service->getTotalFeesForBearer($this->user, [
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
    ]);

    $febTotals = $this->service->getTotalFeesForBearer($this->user, [
        'start_date' => '2024-02-01',
        'end_date' => '2024-02-28',
    ]);

    $allTimeTotals = $this->service->getTotalFeesForBearer($this->user);

    expect($janTotals)
        ->total_transactions->toBe(1)
        ->total_fee_amount->toBe(10)
        ->total_transaction_amount->toBe(100);

    expect($febTotals)
        ->total_transactions->toBe(2)
        ->total_fee_amount->toBe(50) // 20 + 30
        ->total_transaction_amount->toBe(500); // 200 + 300

    expect($allTimeTotals)
        ->total_transactions->toBe(3)
        ->total_fee_amount->toBe(60) // 10 + 20 + 30
        ->total_transaction_amount->toBe(600); // 100 + 200 + 300
});

test('getTotalFeesForBearer applies fee type filter', function () {
    $markupFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    $commissionFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    // Create markup transactions
    $this->service->recordFee(
        feeRule: $markupFee,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
        transactionId: 'TXN-MARKUP-1',
    );

    $this->service->recordFee(
        feeRule: $markupFee,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 200.00,
        feeAmount: 20.00,
        transactionId: 'TXN-MARKUP-2',
    );

    // Create commission transaction
    $this->service->recordFee(
        feeRule: $commissionFee,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 300.00,
        feeAmount: 45.00,
        transactionId: 'TXN-COMMISSION',
    );

    $markupTotals = $this->service->getTotalFeesForBearer($this->user, [
        'fee_type' => 'markup',
    ]);

    $commissionTotals = $this->service->getTotalFeesForBearer($this->user, [
        'fee_type' => 'commission',
    ]);

    $allTotals = $this->service->getTotalFeesForBearer($this->user);

    expect($markupTotals)
        ->total_transactions->toBe(2)
        ->total_fee_amount->toBe(30)
        ->total_transaction_amount->toBe(300);

    expect($commissionTotals)
        ->total_transactions->toBe(1)
        ->total_fee_amount->toBe(45)
        ->total_transaction_amount->toBe(300);

    expect($allTotals)
        ->total_transactions->toBe(3)
        ->total_fee_amount->toBe(75) // 30 + 45
        ->total_transaction_amount->toBe(600); // 300 + 300
});

test('getTotalFeesForBearer handles empty results gracefully', function () {
    $totals = $this->service->getTotalFeesForBearer($this->user);

    expect($totals)
        ->total_transactions->toBe(0)
        ->total_fee_amount->toBe(0)
        ->total_transaction_amount->toBe(0);
});

test('transaction ID generation follows correct format', function () {
    // Mock the random_int function to get predictable results
    $service = new class extends FeeTransactionService
    {
        protected function generateTransactionId(): string
        {
            // Fixed timestamp and random part for testing
            return 'FEE-20240101120000-1234';
        }
    };

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

    $transaction = $service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->user,
        feeable: $this->order,
        transactionAmount: 100.00,
        feeAmount: 10.00,
    );

    expect($transaction->transaction_id)
        ->toBe('FEE-20240101120000-1234');
});

/* test('recordFee can handle null fee rule (manual fee entry)', function () { */
/*     $transaction = $this->service->recordFee( */
/*         feeRule: null, */
/*         feeBearer: $this->user, */
/*         feeable: $this->order, */
/*         transactionAmount: 100.00, */
/*         feeAmount: 5.00, */
/*         transactionId: 'TXN-MANUAL', */
/*         metadata: ['reason' => 'Manual adjustment'] */
/*     ); */
/**/
/*     expect($transaction) */
/*         ->transaction_id->toBe('TXN-MANUAL') */
/*         ->fee_rule_id->toBeNull() */
/*         ->fee_amount->toBe('5.0000') */
/*         ->metadata['reason']->toBe('Manual adjustment'); */
/* }); */
/**/
test('recordFee includes fee rule snapshot when fee rule exists', function () {
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->merchant),
        'entity_id' => $this->merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 12.5,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
        'effective_from' => now()->subDay(),
        'effective_to' => now()->addMonth(),
    ]);

    $transaction = $this->service->recordFee(
        feeRule: $feeRule,
        feeBearer: $this->customer,
        feeable: $this->order,
        transactionAmount: 1000.00,
        feeAmount: 125.00,
        transactionId: 'TXN-SNAPSHOT',
    );

    $snapshot = $transaction->metadata['fee_rule_snapshot'];

    expect($snapshot)
        ->id->toBe($feeRule->id)
        ->value->toBe('12.5000')
        ->calculation_type->toBe('percentage')
        ->item_type->toBe('product')
        ->fee_type->toBe('markup')
        ->is_active->toBe(true)
        ->is_global->toBe(false)
        ->entity_type->toBe(get_class($this->merchant))
        ->entity_id->toBe($this->merchant->id);
});
