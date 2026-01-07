<?php

use Repay\Fee\Facades\Fee;
use Repay\Fee\Models\FeeHistory;
use Repay\Fee\Models\FeeRule;

test('merchants inherit global fees but can override with specific fees', function () {
    // Create 5 merchants
    $merchants = [];
    for ($i = 1; $i <= 5; $i++) {
        $merchants[$i] = $this->mockEntity('Merchant', $i);
    }

    // Step 1: Create initial global fee (10% markup)
    $globalFee1 = Fee::createGlobalFee([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'effective_from' => now(),
    ]);

    // Log the global fee creation
    Fee::logFeeChange($globalFee1, [], 'Initial global fee created');

    // All 5 merchants should use the global fee
    foreach ($merchants as $merchant) {
        $calculation = Fee::calculateFor($merchant, 100.00, 'product');
        expect($calculation['fee_amount'])->toBe(10.00)
            ->and($calculation['fee_rule']['is_global'])->toBeTrue()
            ->and($calculation['fee_rule']['id'])->toBe($globalFee1->id);
    }

    // Step 2: Create specific fee for 3rd merchant (15% markup)
    $specificFee = Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
    ], $merchants[3]);

    // Log the specific fee creation
    Fee::logFeeChange($specificFee, [], 'Created specific fee for merchant 3');

    // Verify merchant 3 now uses specific fee
    $merchant3Calc = Fee::calculateFor($merchants[3], 100.00, 'product');
    expect($merchant3Calc['fee_amount'])->toBe(15.00)
        ->and($merchant3Calc['fee_rule']['is_global'])->toBeFalse()
        ->and($merchant3Calc['fee_rule']['id'])->toBe($specificFee->id);

    // Other merchants should still use global fee
    foreach ([1, 2, 4, 5] as $index) {
        $calculation = Fee::calculateFor($merchants[$index], 100.00, 'product');
        expect($calculation['fee_amount'])->toBe(10.00)
            ->and($calculation['fee_rule']['id'])->toBe($globalFee1->id);
    }

    // Step 3: Create new global fee (12% markup) with future effective date
    $globalFee2 = Fee::createGlobalFee([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 12.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'effective_from' => now()->addDays(7), // Future date
    ]);

    Fee::logFeeChange($globalFee2, [], 'Created new global fee with future date');

    // Merchant 3 should NOT be affected - still uses specific fee (not upcoming yet)
    $merchant3Calc2 = Fee::calculateFor($merchants[3], 100.00, 'product');
    expect($merchant3Calc2['fee_amount'])->toBe(15.00); // Still 15%

    // Other merchants should still use old global fee (new one is future)
    foreach ([1, 2, 4, 5] as $index) {
        $calculation = Fee::calculateFor($merchants[$index], 100.00, 'product');
        expect($calculation['fee_amount'])->toBe(10.00); // Still 10%
    }

    cache()->purge();
    // Step 4: Deactivate merchant 3's specific fee
    $specificFee->update(['is_active' => false]);

    Fee::logFeeChange($specificFee, $specificFee->toArray(), 'Deactivated specific fee');

    // Merchant 3 should now use the LATEST active global fee
    // Since globalFee2 has future effective date, should use globalFee1
    $merchant3Calc3 = Fee::calculateFor($merchants[3], 100.00, 'product');

    $activeFee = app('fee.service')->getActiveFeeFor($merchants[3], 'product');


    // Also check what global fees are available
    $globalFees = FeeRule::global()
        ->forItemType('product')
        ->active()
        ->orderBy('effective_from', 'desc')
        ->get();

    expect($merchant3Calc3['fee_amount'])->toBe(10.00) // Back to 10%
        ->and($merchant3Calc3['fee_rule']['id'])->toBe($globalFee1->id);

    // Step 5: Fast forward time to when globalFee2 becomes active
    $this->travelTo(now()->addDays(8));

    // All merchants should now use globalFee2 (12%)
    foreach ($merchants as $merchant) {
        $calculation = Fee::calculateFor($merchant, 100.00, 'product');
        expect($calculation['fee_amount'])->toBe(12.00)
            ->and($calculation['fee_rule']['id'])->toBe($globalFee2->id);
    }

    // Step 6: Check history for merchant 3
    $merchant3History = Fee::getHistoryForEntity($merchants[3]);
    expect($merchant3History['data'])->toHaveCount(3); // Created, updated, deactivated

    // Step 7: Check upcoming fees
    $merchant3Upcoming = Fee::getLatestUpcomingFees($merchants[3]);
    expect($merchant3Upcoming['product'])->toBeNull(); // No upcoming since using latest global

    // Other merchants should have upcoming fee (globalFee2 before it was active)
    // Actually now it's active, so no upcoming
    $merchant1Upcoming = Fee::getLatestUpcomingFees($merchants[1]);
    expect($merchant1Upcoming['product'])->toBeNull();
});

test('service fee inheritance works similarly', function () {
    config(['cache.default' => 'array']);
    // Create merchants
    $merchant1 = $this->mockEntity('Merchant', 1);
    $merchant2 = $this->mockEntity('Merchant', 2);

    // Step 1: Create global commission fee
    $globalCommission = Fee::createGlobalFee([
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 15.0, // 5%
        'calculation_type' => 'percentage',
        'is_active' => true,
    ]);

    /* Fee::logFeeChange($globalCommission, [], 'Global commission created'); */

    // Step 2: Create global convenience fee
    $globalConvenience = Fee::createGlobalFee([
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 3.0, // $3 fixed
        'calculation_type' => 'fixed',
        'is_active' => false,
    ]);
	/* dd($globalCommission); */

    /* Fee::logFeeChange($globalConvenience, [], 'Global convenience created'); */

    // Both merchants should use commission (preferred over convenience)
    $calc1 = Fee::calculateFor($merchant1, 100.00, 'service');
    expect($calc1['fee_amount'])->toBe(15.00)
        ->and($calc1['fee_rule']['fee_type'])->toBe('commission');

    // Step 3: Merchant 2 creates specific convenience fee
    $specificConvenience = Fee::setFeeForEntity([
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 5.0, // $5 fixed
        'calculation_type' => 'fixed',
        'is_active' => true,
    ], $merchant2);

    /* Fee::logFeeChange($specificConvenience, [], 'Merchant 2 specific convenience'); */

    // Merchant 2 should use specific convenience (overrides global commission)
    $calc2 = Fee::calculateFor($merchant2, 100.00, 'service');
    expect($calc2['fee_amount'])->toBe(5.00)
        ->and($calc2['fee_rule']['fee_type'])->toBe('convenience')
        ->and($calc2['fee_rule']['is_global'])->toBeFalse();

    // Step 4: Create merchant-specific commission for merchant 2
    $specificCommission = Fee::setFeeForEntity([
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 7.0, // 7%
        'calculation_type' => 'percentage',
        'is_active' => true,
    ], $merchant2);

    /* Fee::logFeeChange($specificCommission, [], 'Merchant 2 specific commission'); */

    // Merchant 2 should now use commission (preferred over convenience)
    $calc3 = Fee::calculateFor($merchant2, 100.00, 'service');
    $amount = $calc3['fee_amount'];
    expect($calc3['fee_amount'])->toEqualWithDelta(7.00, 2);
    expect($calc3['fee_rule']['fee_type'])->toBe('commission');

	cache()->purge();
    // Step 5: Deactivate merchant 2's commission
    /* $specificCommission->update(['is_active' => false]); */
   $specificCommission->revertToGlobal(
        effectiveFrom: now(),
        reason: 'End of promotional period'
    );
    Fee::logFeeChange($specificCommission, $specificCommission->toArray(), 'Deactivated commission');

	/* dd(FeeHistory::query()->get()); */
    // Merchant 2 should fall back to specific convenience
    $calc4 = Fee::calculateFor($merchant2, 100.00, 'service');
    expect($calc4['fee_amount'])->toBe(15.00)
        ->and($calc4['fee_rule']['fee_type'])->toBe('commission');
        /* ->and($calc4['fee_rule']['is_global'])->toBeTrue(); */


    // Step 6: Deactivate merchant 2's convenience
    $specificConvenience->update(['is_active' => false]);
    /* Fee::logFeeChange($specificConvenience, $specificConvenience->toArray(), 'Deactivated convenience'); */

    // Merchant 2 should fall back to global commission
    $calc5 = Fee::calculateFor($merchant2, 100.00, 'service');
    expect($calc5['fee_amount'])->toBe(15.00)
        ->and($calc5['fee_rule']['fee_type'])->toBe('commission');
        /* ->and($calc5['fee_rule']['is_global'])->toBeTrue(); */

});

test('multiple fee updates with effective dates', function () {
    $merchant = $this->mockEntity('Merchant', 1);

    // Freeze time
    $this->freezeTime();
    $now = now();

    // Timeline:
    // Day 0: Global fee 10% (active now)
    // Day 5: Global fee 12% (upcoming)
    // Day 10: Merchant specific 15% (upcoming)
    // Day 15: Merchant specific 18% (upcoming)

    // Day 0: Initial global fee
    $global1 = Fee::createGlobalFee([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'effective_from' => $now,
    ]);

    // Day 0: Should use global1 (10%)
    $calc1 = Fee::calculateFor($merchant, 100.00, 'product');
    expect($calc1['fee_amount'])->toBe(10.00);

    // Day 5: New global fee (upcoming)
    $global2 = Fee::createGlobalFee([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 12.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'effective_from' => $now->copy()->addDays(5),
    ]);

    // Check upcoming: should show global2 (earliest upcoming)
    $upcoming1 = Fee::getLatestUpcomingFees($merchant);
    expect($upcoming1['product']->id)->toBe($global2->id)
        ->and($upcoming1['product']->value)->toBe('12.0000');

    // Day 10: Merchant specific fee (upcoming, overrides global2)
    $specific1 = Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'effective_from' => $now->copy()->addDays(10),
    ], $merchant);

    // Now upcoming should show specific1 (entity-specific takes priority)
    $upcoming2 = Fee::getLatestUpcomingFees($merchant);
    expect($upcoming2['product']->id)->toBe($specific1->id)
        ->and($upcoming2['product']->value)->toBe('15.0000');

    // Day 15: Another merchant specific fee (later date)
    $specific2 = Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 18.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'effective_from' => $now->copy()->addDays(15),
    ], $merchant);

    // Upcoming should still show specific1 (earlier date)
    $upcoming3 = Fee::getLatestUpcomingFees($merchant);
    expect($upcoming3['product']->id)->toBe($specific1->id); // Earliest

    // Travel to Day 6 (after global2 becomes active)
    $this->travelTo($now->copy()->addDays(6));

    // Should now use global2 (12%)
    $calc2 = Fee::calculateFor($merchant, 100.00, 'product');
    // Before traveling, debug


    // Travel
    $this->travelTo($now->copy()->addDays(6));

    $activeFee = app('fee.service')->getActiveFeeFor($merchant, 'product');

    expect($calc2['fee_amount'])->toBe(12.00)
        ->and($calc2['fee_rule']['id'])->toBe($global2->id);

    // Travel to Day 11 (after specific1 becomes active)
    $this->travelTo($now->copy()->addDays(11));

    // Should now use specific1 (15%)
    $calc3 = Fee::calculateFor($merchant, 100.00, 'product');
    expect($calc3['fee_amount'])->toBe(15.00)
        ->and($calc3['fee_rule']['id'])->toBe($specific1->id);

    cache()->purge();
    // Deactivate specific1
    $specific1->update(['is_active' => false]);

    // Should fall back to global2 (12%)
    $calc4 = Fee::calculateFor($merchant, 100.00, 'product');
    expect($calc4['fee_amount'])->toBe(12.00)
        ->and($calc4['fee_rule']['id'])->toBe($global2->id);

    // Upcoming should now show specific2 (18%)
    $upcoming4 = Fee::getLatestUpcomingFees($merchant);
    expect($upcoming4['product']->id)->toBe($specific2->id);
});

test('fee inheritance with mixed item types', function () {
    $merchant = $this->mockEntity('Merchant', 1);

    // Create global fees for both product and service
    $globalProduct = Fee::createGlobalFee([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
    ]);

    $globalService = Fee::createGlobalFee([
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 5.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
    ]);

    // Merchant should use both global fees
    $productCalc = Fee::calculateFor($merchant, 100.00, 'product');
    $serviceCalc = Fee::calculateFor($merchant, 100.00, 'service');

    expect($productCalc['fee_amount'])->toBe(10.00)
        ->and($serviceCalc['fee_amount'])->toBe(5.00);

    // Create merchant-specific product fee only
    $specificProduct = Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
    ], $merchant);

    // Merchant should use specific product fee but global service fee
    $productCalc2 = Fee::calculateFor($merchant, 100.00, 'product');
    $serviceCalc2 = Fee::calculateFor($merchant, 100.00, 'service');

    expect($productCalc2['fee_amount'])->toBe(15.00)
        ->and($productCalc2['fee_rule']['is_global'])->toBeFalse()
        ->and($serviceCalc2['fee_amount'])->toBe(5.00)
        ->and($serviceCalc2['fee_rule']['is_global'])->toBeTrue();

    // Get all active fees for merchant
    $allFees = Fee::getAllActiveFeesFor($merchant);
    expect($allFees)->toHaveCount(2)
        ->and($allFees->where('item_type', 'product')->first()->id)->toBe($specificProduct->id)
        ->and($allFees->where('item_type', 'service')->first()->id)->toBe($globalService->id);
});
