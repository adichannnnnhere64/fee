<?php

namespace Repay\Fee\Contracts;

use Repay\Fee\DTO\AnalyticsFilter;
use Repay\Fee\DTO\MonthlyAnalyticsFilter;

interface AnalyticsInterface
{
    public function getMonthlyRevenueAnalytics(MonthlyAnalyticsFilter $filter): array;

    public function getRevenueByDateRange(AnalyticsFilter $filter): array;

    public function getRevenueByFeeType(AnalyticsFilter $filter): array;

    public function getEntityRevenue(AnalyticsFilter $filter): array;

    public function getTopRevenueGenerators(AnalyticsFilter $filter): array;

    public function getDailyBreakdown(AnalyticsFilter $filter): array;

    public function getHourlyBreakdown(AnalyticsFilter $filter): array;

    public function getComparativeAnalysis(AnalyticsFilter $filter1, AnalyticsFilter $filter2): array;

    public function getCustomReport(AnalyticsFilter $filter, array $metrics): array;
}
