<?php

namespace Repay\Fee\DTO;

use Carbon\Carbon;
use Repay\Fee\Enums\CalculationType;
use Repay\Fee\Enums\FeeType;

class CreateFee
{
    public function __construct(
        public string $itemType,
        public FeeType $feeType,
        public float $value,
        public CalculationType $calculationType,
        public bool $isActive = true,
        public ?Carbon $effectiveFrom = null,
        public ?string $reason = null,
        public bool $applyToExistingEntity = false,
    ) {
        $this->validate();
    }

    public static function fromArray(array $data): self
    {
        return new self(
            itemType: $data['item_type'],
            feeType: FeeType::from($data['fee_type']),
            value: (float) $data['value'],
            calculationType: $data['calculation_type'] ?? CalculationType::PERCENTAGE,
            isActive: $data['is_active'] ?? true,
            effectiveFrom: isset($data['effective_from'])
                ? Carbon::parse($data['effective_from'])
                : null,
            reason: $data['reason'] ?? null
        );
    }

    public function toDatabaseArray(): array
    {
        return [
            'item_type' => $this->itemType,
            'fee_type' => $this->feeType->value,
            'value' => $this->value,
            'calculation_type' => $this->calculationType,
            'is_active' => $this->isActive,
            'effective_from' => $this->effectiveFrom?->toDateTimeString(),
            'apply_to_existing_entity' => $this->applyToExistingEntity,
        ];
    }

    public function getReason(): string
    {
        return $this->reason ?? 'Created new fee';
    }

    protected function validate(): void
    {
        if (! in_array($this->calculationType, [CalculationType::PERCENTAGE, CalculationType::FLAT])) {
            throw new \InvalidArgumentException('Calculation type must be CalculationType::PERCENTAGE or CalculationType::FLAT');
        }

        if ($this->value < 0) {
            throw new \InvalidArgumentException('Fee value cannot be negative');
        }

    }
}
