<?php

namespace Repay\Fee\Services;

use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Repay\Fee\Contracts\AnalyticsInterface;
use Repay\Fee\DTO\AnalyticsFilter;
use Repay\Fee\DTO\MonthlyAnalyticsFilter;
use Repay\Fee\Enums\FeeType;
use Repay\Fee\Models\FeeTransaction;

class AnalyticsService implements AnalyticsInterface
{
    public function getMonthlyRevenueAnalytics(MonthlyAnalyticsFilter $filter): array
    {
        $startDate = $filter->startDate;
        $endDate = $filter->endDate;

        $daysInMonth = max(1, $endDate->diffInDays($startDate) + 1);

        $totalRevenue = $this->getRevenueByFeeType($filter);

        $avgEntityRevenue = [];
        foreach ($totalRevenue as $feeType => $data) {
            $avgEntityRevenue[$feeType] = [
                'average_amount' => $data['entity_count'] > 0
                    ? round($data['total_amount'] / $data['entity_count'], 4)
                    : 0,
                'entity_count' => $data['entity_count'],
                'total_amount' => $data['total_amount'],
            ];
        }

        $dailyBreakdown = $this->getDailyBreakdown($filter);

        return [
            'period' => [
                'year' => $filter->year,
                'month' => $filter->month,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'days_in_month' => $daysInMonth,
            ],
            'filters' => $filter->toArray(),
            'total_revenue' => $totalRevenue,
            'average_entity_revenue' => $avgEntityRevenue,
            ...$dailyBreakdown,
            'summary' => $this->calculateSummary($totalRevenue),
        ];
    }

    public function getRevenueByDateRange(AnalyticsFilter $filter): array
    {
        $query = $this->buildBaseQuery($filter);

        $results = $query
            ->select(
                DB::raw('DATE(applied_at) as date'),
                'fee_type',
                DB::raw('SUM(fee_amount) as total_amount'),
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('COUNT(DISTINCT CONCAT(fee_bearer_type, "-", fee_bearer_id)) as entity_count')
            )
            ->groupBy(DB::raw('DATE(applied_at)'), 'fee_type')
            ->orderBy('date')
            ->orderBy('fee_type')
            ->get();

        $groupedData = [];
        $dailyTotals = [];

        foreach ($results as $result) {
            $date = $result->date;
            $feeType = $result->fee_type;

            if (! isset($groupedData[$date])) {
                $groupedData[$date] = [];
                $dailyTotals[$date] = 0;
            }

            $groupedData[$date][$feeType->value] = [
                'total_amount' => (float) $result->total_amount,
                'transaction_count' => (int) $result->transaction_count,
                'entity_count' => (int) $result->entity_count,
                'average_per_transaction' => (int) $result->transaction_count > 0
                    ? (float) $result->total_amount / $result->transaction_count
                    : 0,
                'average_per_entity' => (int) $result->entity_count > 0
                    ? (float) $result->total_amount / $result->entity_count
                    : 0,
            ];

            $dailyTotals[$date] += (float) $result->total_amount;
        }

        $period = CarbonPeriod::create($filter->startDate, $filter->endDate);
        $completeData = [];

        foreach ($period as $date) {
            $dateStr = $date->toDateString();
            $completeData[$dateStr] = $groupedData[$dateStr] ?? [];
        }

        return [
            'period' => [
                'start_date' => $filter->startDate?->toDateString(),
                'end_date' => $filter->endDate?->toDateString(),
                'days' => count($completeData),
            ],
            'filters' => $filter->toArray(),
            'daily_revenue' => $completeData,
            'daily_totals' => $dailyTotals,
            'summary' => $this->calculateDateRangeSummary($results),
        ];
    }

    public function getRevenueByFeeType(AnalyticsFilter $filter): array
    {
        $query = $this->buildBaseQuery($filter);

        $results = $query
            ->select(
                'fee_type',
                DB::raw('SUM(fee_amount) as total_amount'),
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('COUNT(DISTINCT CONCAT(fee_bearer_type, "-", fee_bearer_id)) as entity_count'),
                DB::raw('AVG(fee_amount) as average_fee_amount'),
                DB::raw('MIN(fee_amount) as min_fee_amount'),
                DB::raw('MAX(fee_amount) as max_fee_amount')
            )
            ->groupBy('fee_type')
            ->orderBy('fee_type')
            ->get();

        $formatted = [];
        foreach ($results as $result) {
            $formatted[$result->fee_type->value] = [
                'total_amount' => (float) $result->total_amount,
                'transaction_count' => (int) $result->transaction_count,
                'entity_count' => (int) $result->entity_count,
                'average_fee_amount' => (float) $result->average_fee_amount,
                'min_fee_amount' => (float) $result->min_fee_amount,
                'max_fee_amount' => (float) $result->max_fee_amount,
                'average_per_entity' => (int) $result->entity_count > 0
                    ? (float) $result->total_amount / $result->entity_count
                    : 0,
                'average_per_transaction' => (int) $result->transaction_count > 0
                    ? (float) $result->total_amount / $result->transaction_count
                    : 0,
            ];
        }

        foreach (FeeType::cases() as $feeType) {
            if (! isset($formatted[$feeType->value])) {
                $formatted[$feeType->value] = [
                    'total_amount' => 0,
                    'transaction_count' => 0,
                    'entity_count' => 0,
                    'average_fee_amount' => 0,
                    'min_fee_amount' => 0,
                    'max_fee_amount' => 0,
                    'average_per_entity' => 0,
                    'average_per_transaction' => 0,
                ];
            }
        }

        return $formatted;
    }

    public function getEntityRevenue(AnalyticsFilter $filter): array
    {
        $query = $this->buildBaseQuery($filter);

        $baseQuery = $query
            ->select(
                'fee_bearer_type',
                'fee_bearer_id',
                DB::raw('SUM(fee_amount) as total_revenue'),
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('COUNT(DISTINCT fee_type) as fee_type_count'),
                DB::raw('GROUP_CONCAT(DISTINCT fee_type) as fee_types'),
                DB::raw('AVG(fee_amount) as average_fee_amount'),
                DB::raw('MIN(fee_amount) as min_fee_amount'),
                DB::raw('MAX(fee_amount) as max_fee_amount')
            )
            ->groupBy('fee_bearer_type', 'fee_bearer_id');

        $orderColumn = match ($filter->orderBy) {
            'revenue' => 'total_revenue',
            'transactions' => 'transaction_count',
            'average' => 'average_fee_amount',
            default => 'total_revenue'
        };

        $baseQuery->orderBy($orderColumn, $filter->orderDirection);

        $totalQuery = clone $baseQuery;
        $totalCount = DB::query()->fromSub($totalQuery, 'sub')->count();

        if ($filter->limit) {
            $offset = ($filter->page - 1) * $filter->limit;
            $baseQuery->offset($offset)->limit($filter->limit);
        }

        $results = $baseQuery->get();

        $entities = [];
        foreach ($results as $result) {
            $entities[] = [
                'entity_type' => $result->fee_bearer_type,
                'entity_id' => $result->fee_bearer_id,
                'total_revenue' => (float) $result->total_revenue,
                'transaction_count' => (int) $result->transaction_count,
                'fee_type_count' => (int) $result->fee_type_count,
                'fee_types' => $result->fee_types ? explode(',', $result->fee_types) : [],
                'average_fee_amount' => (float) $result->average_fee_amount,
                'min_fee_amount' => (float) $result->min_fee_amount,
                'max_fee_amount' => (float) $result->max_fee_amount,
                'revenue_per_transaction' => (int) $result->transaction_count > 0
                    ? (float) $result->total_revenue / $result->transaction_count
                    : 0,
            ];
        }

        $pagination = $filter->limit ? [
            'total' => $totalCount,
            'per_page' => $filter->limit,
            'current_page' => $filter->page,
            'total_pages' => ceil($totalCount / $filter->limit),
        ] : null;

        return [
            'filters' => $filter->toArray(),
            'entities' => $entities,
            'pagination' => $pagination,
            'summary' => $this->calculateEntitySummary($results),
        ];
    }

    public function getTopRevenueGenerators(AnalyticsFilter $filter): array
    {
        $filter->orderBy = 'revenue';
        $filter->orderDirection = 'desc';

        return $this->getEntityRevenue($filter);
    }

    public function getDailyBreakdown(AnalyticsFilter $filter): array
    {
        // Get dates from filter or use defaults
        $startDate = $filter->startDate ?? now()->startOfMonth();
        $endDate = $filter->endDate ?? now()->endOfMonth();

        // Always ensure start is beginning of day and end is end of day
        $startDate = $startDate->copy()->startOfDay();
        $endDate = $endDate->copy()->endOfDay();

        // Calculate days in period: use start of day for both to get calendar days
        // This handles cases where times might interfere with the calculation
        $daysInPeriod = $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1;

        // Ensure it's at least 1
        $daysInPeriod = max(1, $daysInPeriod);

        dump('DEBUG: Days in period calculation');
        dump('Start Date:', $startDate->toDateTimeString());
        dump('End Date:', $endDate->toDateTimeString());
        dump('Start of Day Start:', $startDate->copy()->startOfDay()->toDateTimeString());
        dump('Start of Day End:', $endDate->copy()->startOfDay()->toDateTimeString());
        dump('diffInDays result:', $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()));
        dump('Days in Period:', $daysInPeriod);

        $dailyBreakdown = [];
        $feeTypes = $filter->feeTypes ?: array_column(FeeType::cases(), 'value');

        foreach ($feeTypes as $feeType) {
            $dailyBreakdown[$feeType] = array_fill(1, $daysInPeriod, 0);
        }

        $query = $this->buildBaseQuery($filter);

        // Use database-agnostic day extraction
        if (config('database.default') === 'testing' || config('database.default') === 'sqlite') {
            // SQLite uses strftime
            $dayColumn = DB::raw("CAST(strftime('%d', applied_at) AS INTEGER) as day");
        } else {
            // MySQL uses DAY()
            $dayColumn = DB::raw('DAY(applied_at) as day');
        }

        // Debug the query
        dump('Query date range check:');
        dump('Transactions in range:', FeeTransaction::whereBetween('applied_at', [$startDate, $endDate])->count());

        $dailyData = $query
            ->select(
                $dayColumn,
                'fee_type',
                DB::raw('SUM(fee_amount) as total_amount'),
                DB::raw('COUNT(*) as transaction_count')
            )
            ->groupBy(DB::raw('day'), 'fee_type')
            ->orderBy('day')
            ->orderBy('fee_type')
            ->get();

        dump('Query results count:', $dailyData->count());
        dump('Query results:', $dailyData->toArray());

        foreach ($dailyData as $data) {
            $day = (int) $data->day;
            $feeType = $data->fee_type;

            if (isset($dailyBreakdown[$feeType->value]) && $day >= 1 && $day <= $daysInPeriod) {
                $dailyBreakdown[$feeType->value][$day] = (float) $data->total_amount;
            }
        }

        $dailyTotals = [];
        for ($day = 1; $day <= $daysInPeriod; $day++) {
            $dailyTotals[$day] = 0;
            foreach ($dailyBreakdown as $feeType => $dailyData) {
                $dailyTotals[$day] += $dailyData[$day];
            }
        }

        // Debug final result
        dump('Final daily breakdown structure:');
        dump('Number of days in arrays:', $daysInPeriod);
        foreach ($feeTypes as $feeType) {
            dump("$feeType array has ".count($dailyBreakdown[$feeType]).' elements');
        }

        return [
            'daily_breakdown' => $dailyBreakdown,
            'daily_totals' => $dailyTotals,
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'days' => $daysInPeriod,
            ],
        ];
    }

    public function getHourlyBreakdown(AnalyticsFilter $filter): array
    {
        $query = $this->buildBaseQuery($filter);

        // Use database-agnostic hour extraction
        if (config('database.default') === 'testing') {
            $hourColumn = DB::raw("CAST(strftime('%H', applied_at) AS INTEGER) as hour");
        } else {
            $hourColumn = DB::raw('HOUR(applied_at) as hour');
        }

        $results = $query
            ->select(
                $hourColumn,
                'fee_type',
                DB::raw('SUM(fee_amount) as total_amount'),
                DB::raw('COUNT(*) as transaction_count')
            )
            ->groupBy(DB::raw('hour'), 'fee_type')
            ->orderBy('hour')
            ->orderBy('fee_type')
            ->get();

        $hourlyBreakdown = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hourlyBreakdown[$hour] = [];
        }

        foreach ($results as $result) {
            $hour = (int) $result->hour;
            $hourlyBreakdown[$hour][$result->fee_type->value] = [
                'total_amount' => (float) $result->total_amount,
                'transaction_count' => (int) $result->transaction_count,
            ];
        }

        $feeTypes = $filter->feeTypes ?: array_column(FeeType::cases(), 'value');
        for ($hour = 0; $hour < 24; $hour++) {
            foreach ($feeTypes as $feeType) {
                if (! isset($hourlyBreakdown[$hour][$feeType])) {
                    $hourlyBreakdown[$hour][$feeType] = [
                        'total_amount' => 0,
                        'transaction_count' => 0,
                    ];
                }
            }
        }

        return [
            'hourly_breakdown' => $hourlyBreakdown,
            'peak_hours' => $this->identifyPeakHours($results),
        ];
    }

    public function getComparativeAnalysis(AnalyticsFilter $filter1, AnalyticsFilter $filter2): array
    {
        $data1 = $this->getRevenueByFeeType($filter1);
        $data2 = $this->getRevenueByFeeType($filter2);

        $comparison = [];
        $allFeeTypes = array_unique(array_merge(array_keys($data1), array_keys($data2)));

        foreach ($allFeeTypes as $feeType) {
            $period1 = $data1[$feeType] ?? $this->getEmptyFeeTypeData();
            $period2 = $data2[$feeType] ?? $this->getEmptyFeeTypeData();

            $changeAmount = $period2['total_amount'] - $period1['total_amount'];
            $changePercentage = $period1['total_amount'] > 0
                ? ($changeAmount / $period1['total_amount']) * 100
                : ($period2['total_amount'] > 0 ? 100 : 0);

            $comparison[$feeType] = [
                'period_1' => $period1,
                'period_2' => $period2,
                'change' => [
                    'amount' => $changeAmount,
                    'percentage' => round($changePercentage, 2),
                    'direction' => $changeAmount >= 0 ? 'increase' : 'decrease',
                ],
            ];
        }

        return [
            'period_1' => $filter1->toArray(),
            'period_2' => $filter2->toArray(),
            'comparison' => $comparison,
            'summary' => $this->calculateComparativeSummary($comparison),
        ];
    }

    public function getCustomReport(AnalyticsFilter $filter, array $metrics): array
    {
        $query = $this->buildBaseQuery($filter);

        $selectColumns = [];
        foreach ($metrics as $metric) {
            $selectColumns[] = $this->getMetricColumn($metric);
        }

        $results = $query->selectRaw(implode(', ', $selectColumns))->first();

        $report = [];
        foreach ($metrics as $metric) {
            $columnName = $this->getMetricColumnName($metric);
            $report[$metric] = $results->$columnName ?? 0;
        }

        return [
            'filters' => $filter->toArray(),
            'metrics' => $metrics,
            'data' => $report,
        ];
    }

    // Helper Methods

    protected function buildBaseQuery(AnalyticsFilter $filter)
    {
        $query = FeeTransaction::query();

        if ($filter->startDate) {
            $query->where('applied_at', '>=', $filter->startDate);
        }
        if ($filter->endDate) {
            $query->where('applied_at', '<=', $filter->endDate);
        }

        if ($filter->entityType) {
            $query->where('fee_bearer_type', $filter->entityType);
        }
        if ($filter->entityId) {
            $query->where('fee_bearer_id', $filter->entityId);
        }
        if ($filter->entityIds) {
            $query->whereIn('fee_bearer_id', $filter->entityIds);
        }

        if ($filter->feeTypes) {
            $query->whereIn('fee_type', $filter->feeTypes);
        }

        if ($filter->status) {
            $query->where('status', $filter->status);
        }

        if ($filter->itemType) {
            $query->whereHas('feeRule', function ($q) use ($filter) {
                $q->where('item_type', $filter->itemType);
            });
        }

        foreach ($filter->additionalFilters as $key => $value) {
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        return $query;
    }

    protected function calculateSummary(array $revenueByType): array
    {
        $totalAmount = 0;
        $totalTransactions = 0;
        $totalEntities = 0;

        foreach ($revenueByType as $data) {
            $totalAmount += $data['total_amount'];
            $totalTransactions += $data['transaction_count'];
            $totalEntities += $data['entity_count'];
        }

        return [
            'total_amount' => $totalAmount,
            'total_transactions' => $totalTransactions,
            'total_entities' => $totalEntities,
            'average_per_transaction' => $totalTransactions > 0 ? $totalAmount / $totalTransactions : 0,
            'average_per_entity' => $totalEntities > 0 ? $totalAmount / $totalEntities : 0,
        ];
    }

    protected function calculateDateRangeSummary(Collection $results): array
    {
        $summary = [
            'total_amount' => 0,
            'total_transactions' => 0,
            'total_entities' => 0,
            'unique_days' => 0,
        ];

        $uniqueDays = [];

        foreach ($results as $result) {
            $summary['total_amount'] += $result->total_amount;
            $summary['total_transactions'] += $result->transaction_count;
            $uniqueDays[$result->date] = true;
        }

        $summary['unique_days'] = count($uniqueDays);

        return $summary;
    }

    protected function calculateEntitySummary(Collection $results): array
    {
        if ($results->isEmpty()) {
            return [
                'total_entities' => 0,
                'total_revenue' => 0,
                'average_revenue_per_entity' => 0,
                'max_revenue' => 0,
                'min_revenue' => 0,
            ];
        }

        $totalRevenue = $results->sum('total_revenue');
        $entityCount = $results->count();
        $maxRevenue = $results->max('total_revenue');
        $minRevenue = $results->min('total_revenue');

        return [
            'total_entities' => $entityCount,
            'total_revenue' => $totalRevenue,
            'average_revenue_per_entity' => $entityCount > 0 ? $totalRevenue / $entityCount : 0,
            'max_revenue' => $maxRevenue,
            'min_revenue' => $minRevenue,
            'revenue_range' => $maxRevenue - $minRevenue,
        ];
    }

    protected function identifyPeakHours(Collection $results): array
    {
        $hourlyTotals = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hourlyTotals[$hour] = 0;
        }

        foreach ($results as $result) {
            $hourlyTotals[$result->hour] += $result->total_amount;
        }

        $hourlyTotals = array_filter($hourlyTotals);
        if (empty($hourlyTotals)) {
            return [
                'top_hours' => [],
                'busiest_hour' => null,
                'slowest_hour' => null,
            ];
        }

        arsort($hourlyTotals);

        $peakHours = array_slice($hourlyTotals, 0, 3, true);

        return [
            'top_hours' => $peakHours,
            'busiest_hour' => array_key_first($peakHours),
            'slowest_hour' => array_key_last($hourlyTotals),
        ];
    }

    protected function calculateComparativeSummary(array $comparison): array
    {
        $totalChangeAmount = 0;
        $totalPeriod1 = 0;
        $totalPeriod2 = 0;

        foreach ($comparison as $feeType => $data) {
            $totalPeriod1 += $data['period_1']['total_amount'];
            $totalPeriod2 += $data['period_2']['total_amount'];
            $totalChangeAmount += $data['change']['amount'];
        }

        $overallChangePercentage = $totalPeriod1 > 0
            ? (($totalPeriod2 - $totalPeriod1) / $totalPeriod1) * 100
            : ($totalPeriod2 > 0 ? 100 : 0);

        return [
            'period_1_total' => $totalPeriod1,
            'period_2_total' => $totalPeriod2,
            'total_change_amount' => $totalChangeAmount,
            'overall_change_percentage' => round($overallChangePercentage, 2),
            'direction' => $totalChangeAmount >= 0 ? 'increase' : 'decrease',
        ];
    }

    protected function getMetricColumn(string $metric): string
    {
        return match ($metric) {
            'total_revenue' => 'SUM(fee_amount) as total_revenue',
            'total_transactions' => 'COUNT(*) as total_transactions',
            'unique_entities' => 'COUNT(DISTINCT CONCAT(fee_bearer_type, "-", fee_bearer_id)) as unique_entities',
            'avg_fee_amount' => 'AVG(fee_amount) as avg_fee_amount',
            'max_fee_amount' => 'MAX(fee_amount) as max_fee_amount',
            'min_fee_amount' => 'MIN(fee_amount) as min_fee_amount',
            'revenue_per_transaction' => 'SUM(fee_amount) / COUNT(*) as revenue_per_transaction',
            'revenue_per_entity' => 'SUM(fee_amount) / COUNT(DISTINCT CONCAT(fee_bearer_type, "-", fee_bearer_id)) as revenue_per_entity',
            default => 'SUM(fee_amount) as total_revenue'
        };
    }

    protected function getMetricColumnName(string $metric): string
    {
        return match ($metric) {
            'total_revenue' => 'total_revenue',
            'total_transactions' => 'total_transactions',
            'unique_entities' => 'unique_entities',
            'avg_fee_amount' => 'avg_fee_amount',
            'max_fee_amount' => 'max_fee_amount',
            'min_fee_amount' => 'min_fee_amount',
            'revenue_per_transaction' => 'revenue_per_transaction',
            'revenue_per_entity' => 'revenue_per_entity',
            default => 'total_revenue'
        };
    }

    protected function getEmptyFeeTypeData(): array
    {
        return [
            'total_amount' => 0,
            'transaction_count' => 0,
            'entity_count' => 0,
            'average_fee_amount' => 0,
            'min_fee_amount' => 0,
            'max_fee_amount' => 0,
            'average_per_entity' => 0,
            'average_per_transaction' => 0,
        ];
    }
}
