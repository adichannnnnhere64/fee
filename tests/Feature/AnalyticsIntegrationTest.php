<?php

namespace Repay\Fee\Tests\Feature;

use Carbon\Carbon;
use Repay\Fee\Facades\Fee;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;

beforeEach(function (): void {
    FeeTransaction::query()->delete();
    FeeRule::query()->delete();

    $this->travelTo(Carbon::create(2024, 1, 15));
});

test('facade provides analytics methods', function (): void {
    // Create some test data
    $user = $this->mockEntity('User', 1);
    createTransaction('markup', 100.00, Carbon::create(2024, 1, 10), $user);
    createTransaction('commission', 50.00, Carbon::create(2024, 1, 15), $user);

    // Test monthly analytics
    $monthly = Fee::getMonthlyRevenueAnalytics(2024, 1);

    expect($monthly)->toBeArray();
    expect($monthly)->toHaveKeys(['total_revenue', 'average_entity_revenue', 'daily_breakdown']);
    expect($monthly['total_revenue']['markup']['total_amount'])->toBe(100.00);

    // Test with filters
    $filtered = Fee::getMonthlyRevenueAnalytics(2024, 1, [
        'entity_type' => get_class($user),
        'entity_id' => $user->id,
    ]);

    expect($filtered['total_revenue']['markup']['entity_count'])->toBe(1);

    // Test date range
    $dateRange = Fee::getRevenueByDateRange([
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
        'fee_types' => ['markup'],
    ]);

    expect($dateRange['daily_revenue']['2024-01-10']['markup']['total_amount'])->toBe(100.00);

    // Test entity revenue
    $entityRevenue = Fee::getEntityRevenue([
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
    ]);

    expect($entityRevenue['entities'])->toHaveCount(1);

    // Test top revenue generators
    $topGenerators = Fee::getTopRevenueGenerators(['limit' => 10]);
    expect($topGenerators['entities'][0]['total_revenue'])->toBe(150.00); // 100 + 50

    // Test daily breakdown
    $daily = Fee::getDailyBreakdown([
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
    ]);

    expect($daily['daily_breakdown']['markup'][10])->toBe(100.00);
    expect($daily['daily_breakdown']['commission'][15])->toBe(50.00);

    // Test comparative analysis
    $comparison = Fee::getComparativeAnalysis(
        ['start_date' => '2024-01-01', 'end_date' => '2024-01-15'],
        ['start_date' => '2024-01-16', 'end_date' => '2024-01-31']
    );

    expect($comparison)->toHaveKeys(['comparison', 'summary']);

    // Test custom report
    $custom = Fee::getCustomReport(
        ['start_date' => '2024-01-01', 'end_date' => '2024-01-31'],
        ['total_revenue', 'total_transactions']
    );

    expect($custom['data']['total_revenue'])->toBe(150);
    expect($custom['data']['total_transactions'])->toBe(2);
});

test('analytics handle empty results gracefully', function (): void {
    $monthly = Fee::getMonthlyRevenueAnalytics(2024, 1);

    expect($monthly['total_revenue']['markup']['total_amount'])->toBe(0);
    expect($monthly['average_entity_revenue']['markup']['average_amount'])->toBe(0);

    // Daily breakdown should have all days as 0
    expect(array_sum($monthly['daily_breakdown']['markup']))->toBe(0);
});

test('analytics respect status filters', function (): void {
    $user = $this->mockEntity('User', 1);

    // Applied transaction
    createTransaction('markup', 100.00, now(), $user);

    // Pending transaction
    FeeTransaction::create([
        'transaction_id' => 'TXN-PENDING',
        'fee_rule_id' => FeeRule::first()->id,
        'fee_bearer_type' => get_class($user),
        'fee_bearer_id' => $user->id,
        'feeable_type' => 'App\Models\Order',
        'feeable_id' => 1,
        'transaction_amount' => 1000.00,
        'fee_amount' => 200.00,
        'fee_type' => 'markup',
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    $appliedOnly = Fee::getRevenueByFeeType(['status' => 'applied']);
    $allStatuses = Fee::getRevenueByFeeType(['status' => null]);

    expect($appliedOnly['markup']['total_amount'])->toBe(100.00);
});

// Helper method
function createTransaction(
    string $feeType,
    float $feeAmount,
    ?Carbon $date = null,
    $feeBearer = null
): FeeTransaction {
    if (! $date) {
        $date = now();
    }
    mockEntity();

    if (! $feeBearer) {
        $feeBearer = mockEntity('User', 1);
    }

    $feeRule = FeeRule::create([
        'entity_type' => get_class($feeBearer),
        'entity_id' => $feeBearer->id,
        'item_type' => $feeType === 'markup' ? 'product' : 'service',
        'fee_type' => $feeType,
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    return FeeTransaction::create([
        'transaction_id' => 'TXN-'.uniqid(),
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($feeBearer),
        'fee_bearer_id' => $feeBearer->id,
        'feeable_type' => 'App\Models\Order',
        'feeable_id' => 1,
        'transaction_amount' => 1000.00,
        'fee_amount' => $feeAmount,
        'fee_type' => $feeType,
        'status' => 'applied',
        'applied_at' => $date,
    ]);
}
