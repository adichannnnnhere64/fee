<?php

namespace Repay\Fee\DTO;

use Carbon\Carbon;

class MonthlyAnalyticsFilter extends AnalyticsFilter
{
    public function __construct(
        public int $year,
        public int $month,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $feeTypes = null,
        ?string $itemType = null,
        ?string $status = 'applied',
        ?array $entityIds = null,
        ?int $limit = null,
        ?int $page = 1,
        ?string $groupBy = null,
        ?string $orderBy = 'revenue',
        ?string $orderDirection = 'desc',
        ?array $additionalFilters = []
    ) {
        parent::__construct(
            startDate: $startDate ?? Carbon::create($year, $month, 1)->startOfMonth()->startOfDay(),
            endDate: $endDate ?? Carbon::create($year, $month, 1)->endOfMonth()->endOfDay(),
            entityType: $entityType,
            entityId: $entityId,
            feeTypes: $feeTypes,
            itemType: $itemType,
            status: $status,
            entityIds: $entityIds,
            limit: $limit,
            page: $page,
            groupBy: $groupBy,
            orderBy: $orderBy,
            orderDirection: $orderDirection,
            additionalFilters: $additionalFilters
        );
    }

    public static function createForMonth(int $year, int $month, array $params = []): self
    {
        return new self(
            year: $year,
            month: $month,
            startDate: isset($params['start_date']) ? Carbon::parse($params['start_date'])->startOfDay() : null,
            endDate: isset($params['end_date']) ? Carbon::parse($params['end_date'])->endOfDay() : null,
            entityType: $params['entity_type'] ?? null,
            entityId: $params['entity_id'] ?? null,
            feeTypes: $params['fee_types'] ?? null,
            itemType: $params['item_type'] ?? null,
            status: $params['status'] ?? 'applied',
            entityIds: $params['entity_ids'] ?? null,
            limit: $params['limit'] ?? null,
            page: $params['page'] ?? 1,
            groupBy: $params['group_by'] ?? null,
            orderBy: $params['order_by'] ?? 'revenue',
            orderDirection: $params['order_direction'] ?? 'desc',
            additionalFilters: $params['additional_filters'] ?? []
        );
    }
}
