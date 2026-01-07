<?php

namespace Repay\Fee\Tests\Unit\Services;

use Carbon\Carbon;
use Repay\Fee\DTO\AnalyticsFilter;
use Repay\Fee\DTO\MonthlyAnalyticsFilter;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;
use Repay\Fee\Services\AnalyticsService;

beforeEach(function (): void {
    $this->service = new AnalyticsService;
    FeeTransaction::query()->delete();
    FeeRule::query()->delete();

    $this->travelTo(Carbon::create(2024, 1, 15)); // Freeze time
});

test('get monthly revenue analytics returns correct structure', function (): void {
    // Create test transactions
    for ($i = 1; $i <= 3; $i++) {
        createTransaction('markup', 100.00, Carbon::create(2024, 1, $i));
        createTransaction('commission', 50.00, Carbon::create(2024, 1, $i));
    }

    $filter = MonthlyAnalyticsFilter::createForMonth(2024, 1);

    $analytics = $this->service->getMonthlyRevenueAnalytics($filter);

    expect($analytics)->toHaveKeys([
        'period', 'filters', 'total_revenue',
        'average_entity_revenue', 'daily_breakdown', 'summary',
    ]);

    /* dd($analytics); */
    expect($analytics['total_revenue']['markup']['total_amount'])->toBe(300.00);
    expect($analytics['total_revenue']['commission']['total_amount'])->toBe(150.00);
});

test('get revenue by date range with filters', function (): void {
    // Create transactions for different dates
    createTransaction('markup', 100.00, Carbon::create(2024, 1, 10));
    createTransaction('commission', 50.00, Carbon::create(2024, 1, 15));
    createTransaction('convenience', 25.00, Carbon::create(2024, 1, 20));

    $filter = AnalyticsFilter::create([
        'start_date' => '2024-01-10',
        'end_date' => '2024-01-20',
        'fee_types' => ['markup', 'commission'],
    ]);

    $result = $this->service->getRevenueByDateRange($filter);

    expect($result['daily_revenue'])->toHaveCount(11); // 10th to 20th inclusive
    expect($result['daily_revenue']['2024-01-10']['markup']['total_amount'])->toBe(100.00);
    expect($result['daily_revenue']['2024-01-15']['commission']['total_amount'])->toBe(50.00);
    expect($result['daily_revenue']['2024-01-20'])->toBeEmpty(); // convenience filtered out
});

test('get revenue by fee type with entity filter', function (): void {
    $user1 = $this->mockEntity('User', 1);
    $user2 = $this->mockEntity('User', 2);

    createTransaction('markup', 100.00, null, $user1);
    createTransaction('markup', 200.00, null, $user2);
    createTransaction('commission', 50.00, null, $user1);

    $filter = AnalyticsFilter::create([
        'entity_type' => get_class($user1),
        'entity_id' => $user1->id,
    ]);

    $result = $this->service->getRevenueByFeeType($filter);

    expect($result['markup']['total_amount'])->toBe(100.00);
    expect($result['commission']['total_amount'])->toBe(50.00);
    expect($result['markup']['entity_count'])->toBe(1);
});

test('get entity revenue with pagination', function (): void {
    // Create multiple entities with transactions
    for ($i = 1; $i <= 15; $i++) {
        $user = $this->mockEntity('User', $i);
        createTransaction('markup', $i * 100.00, null, $user);
    }

    $filter = AnalyticsFilter::create([
        'limit' => 5,
        'page' => 2,
        'order_by' => 'revenue',
        'order_direction' => 'desc',
    ]);

    $result = $this->service->getEntityRevenue($filter);

    expect($result['entities'])->toHaveCount(5);
    expect($result['pagination']['total'])->toBe(15);
    expect($result['pagination']['per_page'])->toBe(5);
    expect($result['pagination']['current_page'])->toBe(2);
});

test('get daily breakdown returns array indexed by day', function (): void {
    // Create transactions on specific days
    createTransaction('markup', 100.00, Carbon::create(2024, 1, 5, 12, 0, 0)); // Add time
    createTransaction('markup', 200.00, Carbon::create(2024, 1, 5, 14, 0, 0)); // Same day
    createTransaction('markup', 150.00, Carbon::create(2024, 1, 15, 12, 0, 0));
    createTransaction('commission', 50.00, Carbon::create(2024, 1, 10, 12, 0, 0));

    $filter = AnalyticsFilter::create([
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
    ]);

    // Debug: Check what transactions exist
    $transactions = FeeTransaction::all();

    $result = $this->service->getDailyBreakdown($filter);

    expect($result['daily_breakdown']['markup'][5])->toBe(300.00); // Day 5 total
});

test('get comparative analysis shows percentage changes', function (): void {
    // Period 1 transactions
    createTransaction('markup', 100.00, Carbon::create(2024, 1, 1));
    createTransaction('commission', 50.00, Carbon::create(2024, 1, 1));

    // Period 2 transactions (increased)
    createTransaction('markup', 150.00, Carbon::create(2024, 2, 1));
    createTransaction('commission', 25.00, Carbon::create(2024, 2, 1)); // Decreased

    $filter1 = AnalyticsFilter::create([
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
    ]);

    $filter2 = AnalyticsFilter::create([
        'start_date' => '2024-02-01',
        'end_date' => '2024-02-28',
    ]);

    $result = $this->service->getComparativeAnalysis($filter1, $filter2);

    expect($result['comparison']['markup']['change']['amount'])->toBe(50.00); // 150 - 100
    expect($result['comparison']['markup']['change']['percentage'])->toBe(50.00); // 50% increase
    expect($result['comparison']['markup']['change']['direction'])->toBe('increase');

    expect($result['comparison']['commission']['change']['amount'])->toBe(-25.00); // 25 - 50
    expect($result['comparison']['commission']['change']['direction'])->toBe('decrease');
});

test('get custom report with specific metrics', function (): void {

    createTransaction('markup', 100.00);
    createTransaction('markup', 200.00);
    createTransaction('commission', 50.00);

    $filter = AnalyticsFilter::create();
    $metrics = ['total_revenue', 'total_transactions', 'unique_entities', 'avg_fee_amount'];

    $result = $this->service->getCustomReport($filter, $metrics);

    expect($result['data']['total_revenue'])->toBe(350); // 100 + 200 + 50
    expect($result['data']['total_transactions'])->toBe(3);
    expect($result['data']['unique_entities'])->toBe(1); // Same entity
    $ave = $result['data']['avg_fee_amount'];
    expect(round($ave, 2))->toBe(116.67); // 350 / 3
});

test('filters by item type work correctly', function (): void {
    // Create fee rules with different item types
    $productRule = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    $serviceRule = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    $user = $this->mockEntity('User', 1);

    // Create transactions linked to rules
    createTransactionWithRule('markup', 100.00, null, $user, $productRule);
    createTransactionWithRule('commission', 50.00, null, $user, $serviceRule);

    $filter = AnalyticsFilter::create([
        'item_type' => 'product',
    ]);

    $result = $this->service->getRevenueByFeeType($filter);

    expect($result['markup']['total_amount'])->toBe(100.00);
    expect($result['commission']['total_amount'])->toBe(0); // Filtered out
});

test('get top revenue generators returns sorted list', function (): void {
    // Create entities with different revenue amounts
    $entities = [];
    for ($i = 1; $i <= 3; $i++) {
        $entities[$i] = $this->mockEntity('User', $i);
        createTransaction('markup', $i * 100.00, null, $entities[$i]);
    }

    $filter = AnalyticsFilter::create([
        'limit' => 2,
    ]);

    $result = $this->service->getTopRevenueGenerators($filter);

    expect($result['entities'])->toHaveCount(2);
    expect($result['entities'][0]['total_revenue'])->toBe(300.00); // Highest first
    expect($result['entities'][1]['total_revenue'])->toBe(200.00); // Second highest
});

test('get hourly breakdown identifies peak hours', function (): void {
    // Create transactions at different hours
    createTransaction('markup', 100.00, Carbon::create(2024, 1, 1, 10, 0, 0));
    createTransaction('markup', 200.00, Carbon::create(2024, 1, 1, 10, 30, 0)); // Same hour
    createTransaction('markup', 150.00, Carbon::create(2024, 1, 1, 14, 0, 0));
    createTransaction('commission', 50.00, Carbon::create(2024, 1, 1, 14, 15, 0));

    $filter = AnalyticsFilter::create([
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-01',
    ]);

    $result = $this->service->getHourlyBreakdown($filter);

    expect($result['hourly_breakdown'][10]['markup']['total_amount'])->toBe(300.00);
    expect($result['hourly_breakdown'][14]['markup']['total_amount'])->toBe(150.00);
    expect($result['hourly_breakdown'][14]['commission']['total_amount'])->toBe(50.00);

    expect($result['peak_hours']['busiest_hour'])->toBe(10); // Hour 10 has 300 revenue
    expect($result['peak_hours']['top_hours'][10])->toBe(300);
});
