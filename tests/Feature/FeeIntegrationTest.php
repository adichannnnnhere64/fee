<?php

use Repay\Fee\Enums\CalculationType;
use Repay\Fee\Facades\Fee;

test('complete workflow: create fee, update, log history, check upcoming', function (): void {
    $user = $this->mockEntity('User', 1);

    // Create initial fee
    $fee = Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
    ], $user);

    $oldData = $fee->toArray();
    $fee->update([
        'value' => 15.0,
        'effective_from' => now()->addDays(7),
    ]);

    $history = Fee::getHistoryForEntity($user);
    expect($history['data'])->toHaveCount(1);

    // Check upcoming fees
    $upcoming = Fee::getLatestUpcomingFees($user);
    $product = $upcoming['product'];
    expect($product)->not()->toBeNull();
    expect($upcoming['product']->value)->toBe('15.0000');

});

test('global and entity fees work independently', function (): void {
    $user1 = $this->mockEntity('User', 1);
    $user2 = $this->mockEntity('User', 2);

    // Create global fee
    $globalFee = Fee::createGlobalFee([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 5.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
    ]);

    // Create entity-specific fee for user1
    $user1Fee = Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
    ], $user1);

    // User1 should use entity fee
    $user1Calc = Fee::calculateFor($user1, 100.00, 'product');
    expect($user1Calc['fee_amount'])->toBe(10.00);

    // User2 should use global fee
    $user2Calc = Fee::calculateFor($user2, 100.00, 'product');
    expect($user2Calc['fee_amount'])->toBe(5.00);

    // Both should have history
    $user1History = Fee::getHistoryForEntity($user1);
    $globalHistory = Fee::getGlobalHistory();

    expect($user1History['data'])->toHaveCount(1)
        ->and($globalHistory['data'])->toHaveCount(1);
});
