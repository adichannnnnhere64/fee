<?php

namespace Repay\Fee\DTO;

use Carbon\Carbon;

class AnalyticsFilter
{
    public function __construct(
        public ?Carbon $startDate = null,
        public ?Carbon $endDate = null,
        public ?string $entityType = null,
        public ?int $entityId = null,
        public ?array $feeTypes = null,
        public ?string $itemType = null,
        public ?string $status = 'applied',
        public ?array $entityIds = null,
        public ?int $limit = null,
        public ?int $page = 1,
        public ?string $groupBy = null,
        public ?string $orderBy = 'revenue',
        public ?string $orderDirection = 'desc',
        public ?array $additionalFilters = []
    ) {}

    public static function create(array $params = []): self
    {
        return new self(
            startDate: isset($params['start_date']) ? Carbon::parse($params['start_date']) : null,
            endDate: isset($params['end_date']) ? Carbon::parse($params['end_date']) : null,
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

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate?->toDateTimeString(),
            'end_date' => $this->endDate?->toDateTimeString(),
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'fee_types' => $this->feeTypes,
            'item_type' => $this->itemType,
            'status' => $this->status,
            'entity_ids' => $this->entityIds,
            'limit' => $this->limit,
            'page' => $this->page,
            'group_by' => $this->groupBy,
            'order_by' => $this->orderBy,
            'order_direction' => $this->orderDirection,
            'additional_filters' => $this->additionalFilters,
        ];
    }
}
