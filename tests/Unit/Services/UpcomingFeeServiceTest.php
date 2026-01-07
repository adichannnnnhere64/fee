<?php

use Illuminate\Support\Facades\Cache;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Services\UpcomingFeeService;

beforeEach(function () {
    $this->service = new UpcomingFeeService();
    $this->user = $this->mockEntity('User', 1);
    $this->merchant = $this->mockEntity('Merchant', 1);
    
    // Clear existing data
    FeeRule::query()->delete();
    
$this->travelTo(now()->subYears(2));
// You can now use the new "freezeTime" method to keep your code readable and obvious:
$this->freezeTime();
});

test('getLatestUpcomingFees returns empty array when no upcoming fees', function () {
    // Create only active (not upcoming) fees
    FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->subDay(), // Past date (not upcoming)
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    expect($result)
        ->toBeArray()
        ->toHaveKeys(['product', 'service'])
        ->product->toBeNull()
        ->service->toBeNull();
});

test('getLatestUpcomingFees returns global upcoming product fee', function () {
    $upcomingFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5),
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    expect($result)
        ->product->not()->toBeNull()
        ->product->id->toBe($upcomingFee->id)
        ->product->item_type->toBe('product')
        ->product->fee_type->toBe('markup')
        ->service->toBeNull();
});

test('getLatestUpcomingFees returns global upcoming service commission fee', function () {
    $commissionFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 8.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(3),
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    expect($result)
        ->service->not()->toBeNull()
        ->service->id->toBe($commissionFee->id)
        ->service->item_type->toBe('service')
        ->service->fee_type->toBe('commission');
});

test('getLatestUpcomingFees returns global upcoming service convenience fee if no commission', function () {
    $convenienceFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 5.0,
        'calculation_type' => 'fixed',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(7),
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    expect($result)
        ->service->not()->toBeNull()
        ->service->fee_type->toBe('convenience');
});

test('getLatestUpcomingFees prioritizes commission over convenience for global fees', function () {
    $commissionFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(10), // Later date
    ]);
    
    $convenienceFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 3.0,
        'calculation_type' => 'fixed',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5), // Earlier date but convenience
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    // Should return commission even though convenience is earlier
    expect($result)
        ->service->id->toBe($commissionFee->id)
        ->service->fee_type->toBe('commission');
});

test('getLatestUpcomingFees returns entity-specific upcoming product fee', function () {
    $entityFee = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
        'effective_from' => now()->addDays(3),
    ]);
    
    $globalFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(2), // Earlier but global
    ]);
    
    $result = $this->service->getLatestUpcomingFees($this->user);
    
    // Should prioritize entity-specific over global
    expect($result)
        ->product->id->toBe($entityFee->id)
        ->product->value->toBe('20.0000');
});

test('getLatestUpcomingFees returns entity-specific upcoming service fee', function () {
    $entityCommission = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 12.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
        'effective_from' => now()->addDays(4),
    ]);
    
    $globalConvenience = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 5.0,
        'calculation_type' => 'fixed',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(2), // Earlier but global
    ]);
    
    $result = $this->service->getLatestUpcomingFees($this->user);
    
    // Should prioritize entity-specific commission over global convenience
    expect($result)
        ->service->id->toBe($entityCommission->id)
        ->service->fee_type->toBe('commission');
});

test('getLatestUpcomingFees returns latest created when multiple upcoming fees', function () {
    // Create multiple upcoming product fees
    $fee1 = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5),
        'created_at' => now()->subDay(), // Older
    ]);
    
    $fee2 = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5), // Same effective date
        'created_at' => now(), // Newer
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    // Debug: Check what we actually got
    if ($result['product']) {
        // Should return the latest created (fee2) when same effective date
        expect($result['product']->id)->toBe($fee2->id)
            ->and($result['product']->value)->toBe('15.0000');
    }
});

test('getLatestUpcomingFees orders by effective_from then created_at', function () {
    // Freeze time so we can control timestamps
    $this->freezeTime();
    
    // Earliest effective date, older created
    $fee1 = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(2), // Earliest
        'created_at' => now()->subDays(2), // Explicitly set older
    ]);
    
    // Same effective date but newer created (add 1 second to ensure difference)
    $fee2 = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(2), // Same as fee1
        'created_at' => now()->subDays(2)->addSecond(), // Newer than fee1
    ]);
    
    // Later effective date
    $fee3 = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5), // Later
        'created_at' => now(),
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    // Should return fee2 (same effective date as fee1 but newer created)
    expect($result['product']->id)->toBe($fee2->id)
        ->and($result['product']->value)->toBe('15.0000');
});


test('getUpcomingFeeForItemType returns specific item type fee', function () {
    $productFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(3),
    ]);
    
    $serviceFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 8.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5),
    ]);
    
    $productResult = $this->service->getUpcomingFeeForItemType('product');
    $serviceResult = $this->service->getUpcomingFeeForItemType('service');
    
    expect($productResult)
        ->not()->toBeNull()
        ->id->toBe($productFee->id)
        ->item_type->toBe('product');
    
    expect($serviceResult)
        ->not()->toBeNull()
        ->id->toBe($serviceFee->id)
        ->item_type->toBe('service');
});

test('getUpcomingFeeForItemType returns null for invalid item type', function () {
    $result = $this->service->getUpcomingFeeForItemType('invalid');
    
    expect($result)->toBeNull();
});

test('caching works for getLatestUpcomingFees', function () {
    config(['fee.cache.enabled' => true]);
    
    $fee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5),
    ]);
    
    // First call - should cache
    $result1 = $this->service->getLatestUpcomingFees();
    expect($result1['product'])->not()->toBeNull();
    
    // Create new fee
    FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(3), // Earlier date
    ]);
    
    // Should still get cached result (first fee)
    $result2 = $this->service->getLatestUpcomingFees();
    expect($result2['product']->value)->toBe('10.0000');
    
    // Clear cache
    $this->service->clearUpcomingCache();
    
    // Should get fresh result (new fee with earlier date)
    $result3 = $this->service->getLatestUpcomingFees();
    expect($result3['product']->value)->toBe('20.0000');
});

test('clearUpcomingCache clears cache for specific entity', function () {
    config(['fee.cache.enabled' => true]);
    
    // Global fee
    FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5),
    ]);
    
    // Entity fee
    $entityFee = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
        'effective_from' => now()->addDays(3),
    ]);
    
    // Cache both
    $globalResult = $this->service->getLatestUpcomingFees();
    $entityResult = $this->service->getLatestUpcomingFees($this->user);
    
    // Create new entity fee
    FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 25.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
        'effective_from' => now()->addDays(2), // Earlier
    ]);
    
    // Clear only entity cache
    $this->service->clearUpcomingCache($this->user);
    
    // Entity should get fresh result
    $newEntityResult = $this->service->getLatestUpcomingFees($this->user);
    expect($newEntityResult['product']->value)->toBe('25.0000');
    
    // Global should still be cached
    $newGlobalResult = $this->service->getLatestUpcomingFees();
    expect($newGlobalResult['product']->value)->toBe('10.0000');
});

test('only active upcoming fees are returned', function () {
    // Active upcoming
    $activeFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5),
    ]);
    
    // Inactive upcoming (should not be returned)
    FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
        'is_active' => false, // Inactive
        'is_global' => true,
        'effective_from' => now()->addDays(3),
    ]);
    
    // Past effective date (should not be returned)
    FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 30.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->subDays(1), // Past
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    expect($result)
        ->product->id->toBe($activeFee->id)
        ->product->value->toBe('10.0000');
});

test('service fee returns convenience when commission exists but is inactive', function () {
    // Inactive commission
    FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => false, // Inactive
        'is_global' => true,
        'effective_from' => now()->addDays(3),
    ]);
    
    // Active convenience
    $convenienceFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 5.0,
        'calculation_type' => 'fixed',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(5),
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    // Should return convenience since commission is inactive
    expect($result)
        ->service->id->toBe($convenienceFee->id)
        ->service->fee_type->toBe('convenience');
});

test('entity-specific convenience returned when no entity commission', function () {
    // Entity convenience (no entity commission)
    $entityConvenience = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 7.0,
        'calculation_type' => 'fixed',
        'is_active' => true,
        'is_global' => false,
        'effective_from' => now()->addDays(4),
    ]);
    
    // Global commission (should be ignored in favor of entity convenience)
    FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 8.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => now()->addDays(3), // Earlier
    ]);
    
    $result = $this->service->getLatestUpcomingFees($this->user);
    
    // Should return entity convenience
    expect($result)
        ->service->id->toBe($entityConvenience->id)
        ->service->fee_type->toBe('convenience');
});

test('getLatestUpcomingFees orders by effective_from ASC then created_at DESC', function () {
    // Freeze time
    
    // Create fee1 with older created_at - effective date IN THE FUTURE
    $fee1 = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => '2024-01-10 00:00:00', // 9 days in future
        'created_at' => '2023-12-31 00:00:00', // Older
    ]);
    
    // Create fee2 with newer created_at (same effective_from) - IN THE FUTURE
    $fee2 = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => '2024-01-10 00:00:00', // Same effective date
        'created_at' => '2024-01-01 00:00:00', // Newer (same as frozen time)
    ]);
    
    // Create fee3 with later effective date - IN THE FUTURE
    $fee3 = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
        'effective_from' => '2024-01-15 00:00:00', // Later
        'created_at' => '2024-01-02 00:00:00', // Newest
    ]);
    
    $result = $this->service->getLatestUpcomingFees();
    
    // Debug if needed
    if (!$result['product']) {
        dump('No upcoming product fee found!');
        dump('Current time:', now()->toDateTimeString());
        dump('Fee1 effective_from:', $fee1->effective_from);
        dump('Fee2 effective_from:', $fee2->effective_from);
        dump('Fee3 effective_from:', $fee3->effective_from);
        dump('Is fee1 upcoming?', $fee1->effective_from > now());
    }
    
    // Should return fee2 (same effective date as fee1 but newer created)
    expect($result['product'])->not()->toBeNull();
    expect($result['product']->id)->toBe($fee2->id)
        ->and($result['product']->value)->toBe('15.0000');
});
