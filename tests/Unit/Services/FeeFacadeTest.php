<?php

use Illuminate\Support\Facades\Facade;
use Repay\Fee\Facades\Fee;
use Repay\Fee\Models\FeeRule;

beforeEach(function (): void {
    $this->user = $this->mockEntity('User', 1);
    $this->merchant = $this->mockEntity('Merchant', 1);

    // Clear any existing data
    FeeRule::query()->delete();
});

test('facade proxies to service correctly', function (): void {
    // Create a fee
    $fee = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    // Test facade methods
    $result = Fee::getActiveFeeFor($this->user, 'product');

    expect($result)->not()->toBeNull()
        ->id->toBe($fee->id);
});

test('facade calculateFor works correctly', function (): void {
    // Create a fee
    FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $result = Fee::calculateFor($this->user, 100.00, 'product');

    expect($result)
        ->fee_amount->toBe(10.00)
        ->total->toBe(110.00)
        ->has_fee->toBeTrue();
});

/* test('debug facade registration', function () { */
/*     // Check what class is registered as 'fee' */
/*     $registered = app('fee'); */
/*     dump('Registered class:', get_class($registered)); */
/**/
/*     // Check what the facade resolves to */
/*     $facadeRoot = Fee::getFacadeRoot(); */
/*     dump('Facade root class:', get_class($facadeRoot)); */
/**/
/*     // Check if Fee class has the methods */
/*     dump('Fee has logFeeChange?', method_exists($registered, 'logFeeChange')); */
/*     dump('Fee has getHistoryForEntity?', method_exists($registered, 'getHistoryForEntity')); */
/**/
/*     // Check if it has __call method */
/*     dump('Fee has __call?', method_exists($registered, '__call')); */
/**/
/*     expect(true)->toBeTrue(); */
/* }); */

test('facade setFeeForEntity works correctly', function (): void {
    $data = [
        'item_type' => 'product',

        'is_active' => true,
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
    ];

    $fee = Fee::setFeeForEntity($data, $this->user);

    expect($fee)
        ->not()->toBeNull()
        ->entity_id->toBe($this->user->getKey())
        ->value->toBe('15.0000')

        ->is_active->toBeTrue();

    // Verify it's saved in database
    $dbFee = FeeRule::find($fee->id);
    expect($dbFee)->not()->toBeNull();
});

test('facade createGlobalFee works correctly', function (): void {
    $data = [
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
    ];

    $fee = Fee::createGlobalFee($data);

    expect($fee)
        ->is_global->toBeTrue()
        ->entity_type->toBeNull()
        ->entity_id->toBeNull()
        ->value->toBe('20.0000');
});

test('facade getAllActiveFeesFor works correctly', function (): void {
    // Create multiple fees
    FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $fees = Fee::getAllActiveFeesFor($this->user);

    expect($fees)
        ->toHaveCount(2)
        ->sequence(
            fn ($fee) => $fee->item_type->toBe('product'),
            fn ($fee) => $fee->item_type->toBe('service')
        );
});

test('facade getGlobalFees works correctly', function (): void {
    // Create global fee
    $globalFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    $globalFees = Fee::getGlobalFees();

    expect($globalFees)
        ->toHaveCount(1)
        ->first()->id->toBe($globalFee->id);
});

test('facade clearCacheForEntity works', function (): void {
    // Enable cache
    config(['fee.cache.enabled' => true]);

    // This should not throw any errors
    Fee::clearCacheForEntity($this->user);

    // If we get here, the test passes
    expect(true)->toBeTrue();
});

test('facade handles different entity types', function (): void {
    // Test with User entity
    Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
    ], $this->user);

    // Test with Merchant entity
    Fee::setFeeForEntity([
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 15.0,
        'calculation_type' => 'percentage',
    ], $this->merchant);

    $userFee = Fee::getActiveFeeFor($this->user, 'product');
    $merchantFee = Fee::getActiveFeeFor($this->merchant, 'service');

    expect($userFee)->not()->toBeNull()
        ->and($merchantFee)->not()->toBeNull()
        ->and($userFee->entity_id)->toBe($this->user->getKey())
        ->and($merchantFee->entity_id)->toBe($this->merchant->getKey());
});
