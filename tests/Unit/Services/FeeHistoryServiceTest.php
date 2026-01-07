<?php

use Illuminate\Support\Facades\Cache;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeHistory;
use Repay\Fee\Services\FeeHistoryService;

beforeEach(function () {
    $this->service = new FeeHistoryService();
    $this->user = $this->mockEntity('User', 1);
    $this->merchant = $this->mockEntity('Merchant', 1);
    
    // Clear existing data
    FeeRule::query()->delete();
    FeeHistory::query()->delete();
});

test('getForEntity returns paginated history for entity', function () {
    // Create fee rule for entity
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    // Create history entries
    for ($i = 1; $i <= 5; $i++) {
        FeeHistory::create([
            'fee_rule_id' => $feeRule->id,
            'entity_type' => get_class($this->user),
            'entity_id' => $this->user->id,
            'action' => 'updated',
            'old_data' => ['value' => ($i - 1) * 5],
            'new_data' => ['value' => $i * 5],
            'reason' => "Update {$i}",
        ]);
    }
    
    $result = $this->service->getForEntity($this->user);
    
    expect($result)
        ->toBeArray()
        ->toHaveKeys(['data', 'current_page', 'per_page', 'total'])
        ->data->toHaveCount(5)
        ->current_page->toBe(1)
        ->per_page->toBe(15);
});

test('getForEntity respects per_page filter', function () {
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    for ($i = 1; $i <= 10; $i++) {
        FeeHistory::create([
            'fee_rule_id' => $feeRule->id,
            'entity_type' => get_class($this->user),
            'entity_id' => $this->user->id,
            'action' => 'updated',
            'old_data' => null,
            'new_data' => ['value' => $i],
            'reason' => "Update {$i}",
        ]);
    }
    
    $result = $this->service->getForEntity($this->user, ['per_page' => 3]);
    
    expect($result)
        ->data->toHaveCount(3)
        ->per_page->toBe(3);
});

test('getForEntity filters by item_type', function () {
    $feeRule1 = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    $feeRule2 = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 5.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    // Create 3 product histories
    for ($i = 1; $i <= 3; $i++) {
        FeeHistory::create([
            'fee_rule_id' => $feeRule1->id,
            'entity_type' => get_class($this->user),
            'entity_id' => $this->user->id,
            'action' => 'updated',
            'old_data' => null,
            'new_data' => ['value' => $i],
            'reason' => "Product update {$i}",
        ]);
    }
    
    // Create 2 service histories
    for ($i = 1; $i <= 2; $i++) {
        FeeHistory::create([
            'fee_rule_id' => $feeRule2->id,
            'entity_type' => get_class($this->user),
            'entity_id' => $this->user->id,
            'action' => 'updated',
            'old_data' => null,
            'new_data' => ['value' => $i],
            'reason' => "Service update {$i}",
        ]);
    }
    
    // Filter by product
    $productResult = $this->service->getForEntity($this->user, ['item_type' => 'product']);
    expect($productResult['data'])->toHaveCount(3);
    
    // Filter by service
    $serviceResult = $this->service->getForEntity($this->user, ['item_type' => 'service']);
    expect($serviceResult['data'])->toHaveCount(2);
});

test('getForEntity filters by fee_type', function () {
    $feeRule1 = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    $feeRule2 = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 5.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    FeeHistory::create([
        'fee_rule_id' => $feeRule1->id,
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'action' => 'updated',
        'old_data' => null,
        'new_data' => $feeRule1->toArray(),
        'reason' => 'Commission update',
    ]);
    
    FeeHistory::create([
        'fee_rule_id' => $feeRule2->id,
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'action' => 'updated',
        'old_data' => null,
        'new_data' => $feeRule2->toArray(),
        'reason' => 'Convenience update',
    ]);
    
    $commissionResult = $this->service->getForEntity($this->user, ['fee_type' => 'commission']);
    expect($commissionResult['data'])->toHaveCount(1);
    
    $convenienceResult = $this->service->getForEntity($this->user, ['fee_type' => 'convenience']);
    expect($convenienceResult['data'])->toHaveCount(1);
});

test('getForEntity filters by date range', function () {
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    // Create histories on different dates
    $history1 = FeeHistory::create([
        'fee_rule_id' => $feeRule->id,
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'action' => 'created',
        'old_data' => null,
        'new_data' => $feeRule->toArray(),
        'reason' => 'Created',
        'created_at' => '2024-01-01 10:00:00',
    ]);
    
    $history2 = FeeHistory::create([
        'fee_rule_id' => $feeRule->id,
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'action' => 'updated',
        'old_data' => null,
        'new_data' => ['value' => 15.0],
        'reason' => 'Updated',
        'created_at' => '2024-01-15 10:00:00',
    ]);
    
    $history3 = FeeHistory::create([
        'fee_rule_id' => $feeRule->id,
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'action' => 'deactivated',
        'old_data' => null,
        'new_data' => ['is_active' => false],
        'reason' => 'Deactivated',
        'created_at' => '2024-01-30 10:00:00',
    ]);
    
    // Filter by start date (>= Jan 15)
    $result1 = $this->service->getForEntity($this->user, ['start_date' => '2024-01-15']);
    expect($result1['data'])->toHaveCount(2); // Jan 15 and Jan 30
    
    // Verify correct entries
    $ids1 = collect($result1['data'])->pluck('id')->toArray();
    expect($ids1)->toContain($history2->id, $history3->id);
    
    // Filter by end date (<= Jan 15)
    $result2 = $this->service->getForEntity($this->user, ['end_date' => '2024-01-15']);
    expect($result2['data'])->toHaveCount(2); // Jan 1 and Jan 15
    
    // Filter by both (Jan 10 - Jan 20)
    $result3 = $this->service->getForEntity($this->user, [
        'start_date' => '2024-01-10',
        'end_date' => '2024-01-20',
    ]);
    expect($result3['data'])->toHaveCount(1); // Only Jan 15
    expect($result3['data'][0]['id'])->toBe($history2->id);
});


test('getGlobal returns paginated global history', function () {
    // Create global fee rule
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
    
    // Create global history entries
    for ($i = 1; $i <= 4; $i++) {
        FeeHistory::create([
            'fee_rule_id' => $feeRule->id,
            'entity_type' => null,
            'entity_id' => null,
            'action' => 'updated',
            'old_data' => ['value' => ($i - 1) * 5],
            'new_data' => ['value' => $i * 5],
            'reason' => "Global update {$i}",
        ]);
    }
    
    // Create entity-specific history (should not appear in global results)
    $entityFeeRule = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    FeeHistory::create([
        'fee_rule_id' => $entityFeeRule->id,
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'action' => 'created',
        'old_data' => null,
        'new_data' => $entityFeeRule->toArray(),
        'reason' => 'Entity-specific update',
    ]);
    
    $result = $this->service->getGlobal();
    
    expect($result)
        ->toBeArray()
        ->data->toHaveCount(4); // Only global entries
});

test('getGlobal respects filters', function () {
    $feeRule = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);
    
    for ($i = 1; $i <= 3; $i++) {
        FeeHistory::create([
            'fee_rule_id' => $feeRule->id,
            'entity_type' => null,
            'entity_id' => null,
            'action' => 'updated',
            'old_data' => null,
            'new_data' => ['value' => $i * 5],
            'reason' => "Update {$i}",
        ]);
    }
    
    $result = $this->service->getGlobal([
        'fee_type' => 'commission',
        'per_page' => 2,
    ]);
    
    expect($result)
        ->data->toHaveCount(2)
        ->per_page->toBe(2);
});

test('logChange creates history entry', function () {
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    $oldData = $feeRule->toArray();
    $feeRule->update(['value' => 15.0]);
    
    $this->service->logChange($feeRule, $oldData, 'Rate increased from 10% to 15%');
    
    $history = FeeHistory::latest()->first();
    
    expect($history)
        ->not()->toBeNull()
        ->fee_rule_id->toBe($feeRule->id)
        ->entity_type->toBe(get_class($this->user))
        ->entity_id->toBe($this->user->id)
        ->action->toBe('updated')
        ->old_data->toBe($oldData)
        ->new_data->toBe($feeRule->toArray())
        ->reason->toBe('Rate increased from 10% to 15%');
});

test('logChange with empty oldData creates "created" action', function () {
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    $this->service->logChange($feeRule, [], 'New fee created');
    
    $history = FeeHistory::latest()->first();
    
    expect($history)
        ->action->toBe('created')
        ->old_data->toBe([])
        ->reason->toBe('New fee created');
});


test('caching works for getForEntity', function () {
    config(['fee.cache.enabled' => true]);
    
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    // Create initial history
    FeeHistory::create([
        'fee_rule_id' => $feeRule->id,
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'action' => 'created',
        'old_data' => null,
        'new_data' => $feeRule->toArray(),
        'reason' => 'Created',
    ]);
    
    // First call should cache (1 item)
    $result1 = $this->service->getForEntity($this->user);
    expect($result1['data'])->toHaveCount(1);
    
    // Add more history
    FeeHistory::create([
        'fee_rule_id' => $feeRule->id,
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'action' => 'updated',
        'old_data' => null,
        'new_data' => ['value' => 15.0],
        'reason' => 'Updated',
    ]);
    
    // Should still get cached result (1 item)
    $result2 = $this->service->getForEntity($this->user);
    expect($result2['data'])->toHaveCount(1);
    
    // Clear cache by logging a change - use proper old data
    $oldData = $feeRule->toArray();
    $this->service->logChange($feeRule, $oldData, 'Clearing cache');
    
    // Should now get fresh result: 
    // Original create (1) + update (1) + logChange (1) = 3 items
    $result3 = $this->service->getForEntity($this->user);
    expect($result3['data'])->toHaveCount(3);
});



test('caching works for getGlobal', function () {
    config(['fee.cache.enabled' => true]);
    
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
    
    FeeHistory::create([
        'fee_rule_id' => $feeRule->id,
        'entity_type' => null,
        'entity_id' => null,
        'action' => 'created',
        'old_data' => null,
        'new_data' => $feeRule->toArray(),
        'reason' => 'Global created',
    ]);
    
    // First call
    $result1 = $this->service->getGlobal();
    expect($result1['data'])->toHaveCount(1);
    
    // Add more
    FeeHistory::create([
        'fee_rule_id' => $feeRule->id,
        'entity_type' => null,
        'entity_id' => null,
        'action' => 'updated',
        'old_data' => null,
        'new_data' => ['value' => 15.0],
        'reason' => 'Global updated',
    ]);
    
    // Should still be cached
    $result2 = $this->service->getGlobal();
    expect($result2['data'])->toHaveCount(1);
    
    // Log change to clear cache
    $this->service->logChange($feeRule, [], 'Clear global cache');
    
    // Should get fresh
    $result3 = $this->service->getGlobal();
    expect($result3['data'])->toHaveCount(3);
});

/* test('different filters create different cache keys', function () { */
/*     config(['fee.cache.enabled' => true]); */
/**/
/*     $feeRule = FeeRule::create([ */
/*         'entity_type' => get_class($this->user), */
/*         'entity_id' => $this->user->id, */
/*         'item_type' => 'product', */
/*         'fee_type' => 'markup', */
/*         'value' => 10.0, */
/*         'calculation_type' => 'percentage', */
/*         'is_active' => true, */
/*         'is_global' => false, */
/*     ]); */
/**/
/*     // Create 5 history entries */
/*     for ($i = 1; $i <= 5; $i++) { */
/*         FeeHistory::create([ */
/*             'fee_rule_id' => $feeRule->id, */
/*             'entity_type' => get_class($this->user), */
/*             'entity_id' => $this->user->id, */
/*             'action' => 'updated', */
/*             'old_data' => null, */
/*             'new_data' => ['value' => $i], */
/*             'reason' => "Update {$i}", */
/*         ]); */
/*     } */
/**/
/*     // Get with no filters (should cache 5 items) */
/*     $result1 = $this->service->getForEntity($this->user); */
/*     expect($result1['data'])->toHaveCount(5); */
/**/
/*     // Get with per_page filter (should cache separately with 2 items) */
/*     $result2 = $this->service->getForEntity($this->user, ['per_page' => 2]); */
/*     expect($result2['data'])->toHaveCount(2); */
/**/
/*     // Get with item_type filter (should cache separately) */
/*     $result3 = $this->service->getForEntity($this->user, ['item_type' => 'product']); */
/*     expect($result3['data'])->toHaveCount(5); */
/**/
/*     // Manually clear cache instead of using logChange */
/*     $this->service->clearHistoryCacheForEntityTypeAndId( */
/*         get_class($this->user), */
/*         $this->user->id */
/*     ); */
/**/
/*     // Add one more history entry (now 6 total) */
/*     FeeHistory::create([ */
/*         'fee_rule_id' => $feeRule->id, */
/*         'entity_type' => get_class($this->user), */
/*         'entity_id' => $this->user->id, */
/*         'action' => 'updated', */
/*         'old_data' => null, */
/*         'new_data' => ['value' => 6], */
/*         'reason' => "Update 6", */
/*     ]); */
/**/
/*     // No filters: Should now have 6 items (5 original + 1 new) */
/*     $result4 = $this->service->getForEntity($this->user); */
/*     expect($result4['data'])->toHaveCount(6); */
/**/
/*     // Per page filter: Should have 2 items (pagination still applies) */
/*     $result5 = $this->service->getForEntity($this->user, ['per_page' => 2]); */
/*     expect($result5['data'])->toHaveCount(2); */
/**/
/*     // Item type filter: Should have 6 items */
/*     $result6 = $this->service->getForEntity($this->user, ['item_type' => 'product']); */
/*     expect($result6['data'])->toHaveCount(6); */
/* }); */


test('history includes feeRule relationship when requested', function () {
    $feeRule = FeeRule::create([
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);
    
    FeeHistory::create([
        'fee_rule_id' => $feeRule->id,
        'entity_type' => get_class($this->user),
        'entity_id' => $this->user->id,
        'action' => 'created',
        'old_data' => null,
        'new_data' => $feeRule->toArray(),
        'reason' => 'Created',
    ]);
    
    $result = $this->service->getForEntity($this->user);
    
    expect($result['data'][0])
        ->toHaveKey('fee_rule')
        ->fee_rule->toHaveKeys(['id', 'item_type', 'fee_type', 'value']);
});
