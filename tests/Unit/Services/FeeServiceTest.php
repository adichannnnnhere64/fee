<?php

use Illuminate\Support\Facades\Cache;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Services\FeeService;

beforeEach(function (): void {
    $this->service = new FeeService;
    $this->user = $this->mockEntity('User', 1);
    $this->merchant = $this->mockEntity('Merchant', 1);

    // Clear any existing data
    FeeRule::query()->delete();
});

test('getActiveFeeFor returns entity-specific fee when exists', function (): void {
    // Create entity-specific fee
    $entityFee = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $result = $this->service->getActiveFeeFor($this->user, 'product');

    expect($result)
        ->not()->toBeNull()
        ->id->toBe($entityFee->id)
        ->entity_id->toBe($this->user->getKey())
        ->is_global->toBeFalse();
});

test('getActiveFeeFor returns global fee when no entity-specific fee', function (): void {
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

    $result = $this->service->getActiveFeeFor($this->user, 'product');

    expect($result)
        ->not()->toBeNull()
        ->id->toBe($globalFee->id)
        ->is_global->toBeTrue();
});

test('getActiveFeeFor returns null when no fees exist', function (): void {
    $result = $this->service->getActiveFeeFor($this->user, 'product');

    expect($result)->toBeNull();
});

test('getActiveFeeFor only returns active fees', function (): void {
    // Create inactive entity fee
    FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => false,
        'is_global' => false,
    ]);

    $result = $this->service->getActiveFeeFor($this->user, 'product');

    expect($result)->toBeNull();
});

test('getActiveFeeFor respects effective dates', function (): void {
    // Create future fee (not yet active)
    FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
        'effective_from' => now()->addDays(1),
    ]);

    $result = $this->service->getActiveFeeFor($this->user, 'product');

    expect($result)->toBeNull();
});

test('setFeeForEntity creates new fee and deactivates old ones', function (): void {
    // Create old active fee
    $oldFee = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $data = [
        'item_type' => 'product',
        'fee_type' => 'markup',
        'is_active' => true,
        'value' => 15.0,
        'calculation_type' => 'percentage',
    ];

    $newFee = $this->service->setFeeForEntity($data, $this->user);

    // Old fee should be inactive
    expect($oldFee->fresh()->is_active)->toBeFalse();

    // New fee should be active
    expect($newFee)
        ->not()->toBeNull()
        ->entity_id->toBe($this->user->getKey())
        ->item_type->toBe('product')
        ->fee_type->toBe('markup')
        ->value->toBe('15.0000')
        ->is_active->toBeTrue()
        ->is_global->toBeFalse();
});

test('createGlobalFee creates fee with global flag', function (): void {
    $data = [
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
    ];

    $fee = $this->service->createGlobalFee($data);

    expect($fee)
        ->entity_type->toBeNull()
        ->entity_id->toBeNull()
        ->is_global->toBeTrue()
        ->item_type->toBe('product')
        ->fee_type->toBe('markup')
        ->value->toBe('20.0000');
});

test('calculateFor returns correct calculation for percentage fee', function (): void {
    // Create a fee
    FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0, // 10%
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $result = $this->service->calculateFor($this->user, 100.00, 'product');

    expect($result)
        ->toHaveKeys(['amount', 'fee_amount', 'total', 'has_fee', 'fee_rule'])
        ->amount->toBe(100.00)
        ->fee_amount->toBe(10.00) // 10% of 100
        ->total->toBe(110.00)
        ->has_fee->toBeTrue();
});

test('calculateFor returns correct calculation for fixed fee', function (): void {
    // Create a fixed fee
    FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 5.0, // $5 fixed
        'calculation_type' => 'fixed',
        'is_active' => true,
        'is_global' => false,
    ]);

    $result = $this->service->calculateFor($this->user, 100.00, 'service');

    expect($result)
        ->fee_amount->toBe(5.00)
        ->total->toBe(105.00)
        ->fee_rule->calculation_type->toBe('fixed');
});

test('calculateFor returns no fee when none exists', function (): void {
    $result = $this->service->calculateFor($this->user, 100.00, 'product');

    expect($result)
        ->fee_amount->toBe(0)
        ->total->toBe(100.0)
        ->has_fee->toBeFalse();
});

test('getAllActiveFeesFor returns all item types', function (): void {
    // Create multiple fees for same entity
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

    $fees = $this->service->getAllActiveFeesFor($this->user);

    expect($fees)
        ->toBeInstanceOf(\Illuminate\Support\Collection::class)
        ->toHaveCount(2)
        ->sequence(
            fn ($fee) => $fee->item_type->toBe('product'),
            fn ($fee) => $fee->item_type->toBe('service')
        );
});

test('getGlobalFees returns only global active fees', function (): void {
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

    // Create entity fee (should not be returned)
    FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    // Create inactive global fee (should not be returned)
    FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 5.0,
        'calculation_type' => 'percentage',
        'is_active' => false,
        'is_global' => true,
    ]);

    $globalFees = $this->service->getGlobalFees();

    expect($globalFees)
        ->toHaveCount(1)
        ->first()->id->toBe($globalFee->id);
});

test('clearCacheForEntity removes cached fees', function (): void {
    // Enable cache for this test
    config(['fee.cache.enabled' => true]);

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

    // First call should cache
    $this->service->getActiveFeeFor($this->user, 'product');

    // Verify cache exists
    $cacheKey = $this->service->getCacheKey($this->user, 'product');
    expect(Cache::has($cacheKey))->toBeTrue();

    // Clear cache
    $this->service->clearCacheForEntity($this->user);

    // Cache should be gone
    expect(Cache::has($cacheKey))->toBeFalse();
});

test('validation prevents invalid fee types', function (): void {
    expect(function (): void {
        FeeRule::create([
            'entity_type' => get_class($this->user),
            'entity_id' => $this->user->getKey(),
            'item_type' => 'product',
            'fee_type' => 'commission', // Invalid! Product can only have markup
            'value' => 10.0,
            'calculation_type' => 'percentage',
            'is_active' => true,
            'is_global' => false,
        ]);
    })->toThrow(\InvalidArgumentException::class);
});

test('validation prevents invalid item types', function (): void {
    expect(function (): void {
        FeeRule::create([
            'entity_type' => get_class($this->user),
            'entity_id' => $this->user->getKey(),
            'item_type' => 'invalid', // Invalid item type
            'fee_type' => 'markup',
            'value' => 10.0,
            'calculation_type' => 'percentage',
            'is_active' => true,
            'is_global' => false,
        ]);
    })->toThrow(\InvalidArgumentException::class);
});

test('isCurrentlyActive returns correct status', function (): void {
    $fee = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
        'effective_from' => now()->subDay(),
        'effective_to' => now()->addDay(),
    ]);

    expect($fee->isCurrentlyActive())->toBeTrue();
});

test('isCurrentlyActive returns false for future effective date', function (): void {
    $fee = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
        'effective_from' => now()->addDay(),
    ]);

    expect($fee->isCurrentlyActive())->toBeFalse();
});

test('isCurrentlyActive returns false for expired fee', function (): void {
    $fee = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
        'effective_to' => now()->subDay(),
    ]);

    expect($fee->isCurrentlyActive())->toBeFalse();
});

test('multiple entities can have separate fees', function (): void {
    $user1 = $this->mockEntity('User', 1);
    $user2 = $this->mockEntity('User', 2);

    // Create fee for user1
    FeeRule::create([
        'entity_type' => get_class($user1),
        'entity_id' => $user1->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    // Create different fee for user2
    FeeRule::create([
        'entity_type' => get_class($user2),
        'entity_id' => $user2->getKey(),
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $fee1 = $this->service->getActiveFeeFor($user1, 'product');
    $fee2 = $this->service->getActiveFeeFor($user2, 'product');

    expect($fee1->value)->toBe('10.0000')
        ->and($fee2->value)->toBe('20.0000');
});
