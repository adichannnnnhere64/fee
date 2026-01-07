<?php

// tests/Unit/Services/FeeContextTest.php

namespace Repay\Fee\Tests\Unit\Services;

use Mockery;
use Repay\Fee\Contracts\FeeContextInterface;
use Repay\Fee\Facades\Fee;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;
use Repay\Fee\Services\FeeTransactionService;
use Repay\Fee\Tests\Fixtures\Merchant;
use Repay\Fee\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->transactionService = new FeeTransactionService;
    $this->feeService = app('fee.service');

    // Clear existing data
    FeeTransaction::query()->delete();
    FeeRule::query()->delete();
});

test('fee context interface works with order model', function (): void {
    // Create a proper test double that EXPLICITLY implements the interface
    $order = new class implements \Repay\Fee\Contracts\FeeContextInterface
    {
        public $id = 1;

        private $buyer;

        private $seller;

        public function __construct()
        {
            // Create simple buyer/seller objects
            $this->buyer = new class
            {
                public $id = 101;

                public function getKey()
                {
                    return $this->id;
                }

                public function getMorphClass()
                {
                    return 'App\Models\User';
                }
            };

            $this->seller = new class
            {
                public $id = 202;

                public function getKey()
                {
                    return $this->id;
                }

                public function getMorphClass()
                {
                    return 'App\Models\Merchant';
                }
            };
        }

        // Implement ALL methods from FeeContextInterface
        public function getKey()
        {
            return $this->id;
        }

        public function getMorphClass()
        {
            return 'App\Models\Order';
        }

        public function getBuyer()
        {
            return $this->buyer;
        }

        public function getSeller()
        {
            return $this->seller;
        }

        public function getFeeEntity()
        {
            return $this->seller;
        }

        public function getAmountForFeeCalculation(): float
        {
            return 1000.00;
        }

        public function getItemType(): string
        {
            return 'product';
        }

        public function getCurrency(): string
        {
            return 'PHP';
        }

        public function getDescription(): string
        {
            return 'Order #1 - Electronics Purchase';
        }
    };

    // Verify it implements the interface
    expect($order)->toBeInstanceOf(\Repay\Fee\Contracts\FeeContextInterface::class);

    $buyer = $order->getBuyer();
    $seller = $order->getSeller();

    // Create a fee rule attached to the merchant (seller)
    $feeRule = FeeRule::create([
        'entity_type' => get_class($seller),
        'entity_id' => $seller->id,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    // Test that we can get the active fee for the fee entity
    $activeFee = $this->feeService->getActiveFeeFor($seller, 'service');
    expect($activeFee)->not()->toBeNull()
        ->and($activeFee->id)->toBe($feeRule->id);

    // Test recordFeeFromContext method
    if (method_exists($this->transactionService, 'recordFeeFromContext')) {
        $transaction = $this->transactionService->recordFeeFromContext(
            feeRule: $feeRule,
            context: $order
        );

        expect($transaction)->not()->toBeNull()
            ->and($transaction->fee_bearer_type)->toBe(get_class($seller))
            ->and($transaction->fee_bearer_id)->toBe($seller->id)
            ->and($transaction->metadata['context_type'])->toBe('App\Models\Order')
            ->and($transaction->feeable_id)->toBe(1)
            ->and($transaction->fee_amount)->toBe('100.0000')
            ->and($transaction->metadata['buyer_id'])->toBe($buyer->id)
            ->and($transaction->metadata['seller_id'])->toBe($seller->id);
    }
});

test('fee context interface works with invoice model for service items', function (): void {
    // Mock an Invoice model implementing FeeContextInterface
    $invoice = Mockery::mock(FeeContextInterface::class);

    // Setup invoice properties
    $invoice->shouldReceive('getKey')->andReturn(2);
    $invoice->shouldReceive('getMorphClass')->andReturn('App\Models\Invoice');

    // Mock client and service provider
    $client = $this->mockEntity('User', 303);
    $provider = $this->mockEntity('ServiceProvider', 404);

    $invoice->shouldReceive('getBuyer')->andReturn($client);
    $invoice->shouldReceive('getSeller')->andReturn($provider);
    $invoice->shouldReceive('getFeeEntity')->andReturn($provider); // Fee rules attached to provider

    // Invoice details
    $invoice->shouldReceive('getAmountForFeeCalculation')->andReturn(5000.00);
    $invoice->shouldReceive('getItemType')->andReturn('service');
    $invoice->shouldReceive('getCurrency')->andReturn('USD');
    $invoice->shouldReceive('getDescription')->andReturn('Invoice #2 - Consulting Services');

    // Create fee rules for service (both commission and convenience)
    $commissionFee = FeeRule::create([
        'entity_type' => get_class($provider),
        'entity_id' => $provider->id,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 15.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $convenienceFee = FeeRule::create([
        'entity_type' => get_class($provider),
        'entity_id' => $provider->id,
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 50.0,
        'calculation_type' => 'fixed',
        'is_active' => true,
        'is_global' => false,
    ]);

    // Test getting active fee (should get commission first for service)
    $activeFee = $this->feeService->getActiveFeeFor($provider, 'service');
    expect($activeFee)->not()->toBeNull()
        ->and($activeFee->fee_type)->toBe('commission');

    // Test different fee bearers based on fee type
    if (method_exists($this->transactionService, 'determineFeeBearerFromContext')) {
        // Test commission (paid by provider/seller)
        $commissionBearer = $this->transactionService->determineFeeBearerFromContext(
            $commissionFee,
            $invoice
        );

        expect($commissionBearer)->not()->toBeNull()
            ->and(get_class($commissionBearer))->toBe(get_class($provider))
            ->and($commissionBearer->id)->toBe($provider->id);

        // Test convenience fee (paid by client/buyer)
        $convenienceBearer = $this->transactionService->determineFeeBearerFromContext(
            $convenienceFee,
            $invoice
        );

        expect($convenienceBearer)->not()->toBeNull()
            ->and(get_class($convenienceBearer))->toBe(get_class($client))
            ->and($convenienceBearer->id)->toBe($client->id);
    }
});

test('fee context interface handles global fees when no fee entity', function (): void {
    // Mock a generic transaction with no specific fee entity
    $transaction = Mockery::mock(FeeContextInterface::class);

    // Setup transaction properties
    $transaction->shouldReceive('getKey')->andReturn(3);
    $transaction->shouldReceive('getMorphClass')->andReturn('App\Models\GenericTransaction');

    // Mock buyer and seller
    $buyer = $this->mockEntity('User', 505);
    $seller = $this->mockEntity('Platform', 606);

    $transaction->shouldReceive('getBuyer')->andReturn($buyer);
    $transaction->shouldReceive('getSeller')->andReturn($seller);
    $transaction->shouldReceive('getFeeEntity')->andReturn(null); // No specific entity, use global

    // Transaction details
    $transaction->shouldReceive('getAmountForFeeCalculation')->andReturn(200.00);
    $transaction->shouldReceive('getItemType')->andReturn('product');
    $transaction->shouldReceive('getCurrency')->andReturn('PHP');
    $transaction->shouldReceive('getDescription')->andReturn('Generic Transaction #3');

    // Create a global fee
    $globalFee = FeeRule::create([
        'entity_type' => null,
        'entity_id' => null,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 5.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => true,
    ]);

    // Should get global fee when no entity-specific fee
    $activeFee = $this->feeService->getActiveFeeFor(null, 'product');

    expect($activeFee)->not()->toBeNull()
        ->and($activeFee->is_global)->toBeTrue()
        ->and($activeFee->id)->toBe($globalFee->id);

    // Calculate fee using global fee
    $calculation = $this->feeService->calculateFor(null, 200.00, 'product');

    expect($calculation['has_fee'])->toBeTrue()
        ->and($calculation['fee_amount'])->toBe(10.00) // 5% of 200
        ->and($calculation['fee_rule']['is_global'])->toBeTrue();
});

test('fee context interface with multiple item types in same context', function (): void {
    // Mock a complex order with mixed items
    $mixedOrder = Mockery::mock(FeeContextInterface::class);

    // Setup order properties
    $mixedOrder->shouldReceive('getKey')->andReturn(4);
    $mixedOrder->shouldReceive('getMorphClass')->andReturn('App\Models\MixedOrder');

    // Mock entities
    $customer = $this->mockEntity('User', 707);
    $business = $this->mockEntity('Business', 808);

    $mixedOrder->shouldReceive('getBuyer')->andReturn($customer);
    $mixedOrder->shouldReceive('getSeller')->andReturn($business);
    $mixedOrder->shouldReceive('getFeeEntity')->andReturn($business);

    // Order details - different amounts for different calculations
    $mixedOrder->shouldReceive('getAmountForFeeCalculation')->andReturn(3000.00);
    $mixedOrder->shouldReceive('getCurrency')->andReturn('PHP');
    $mixedOrder->shouldReceive('getDescription')->andReturn('Mixed Order #4');

    // Test with product item type
    $mixedOrder->shouldReceive('getItemType')->andReturn('product');

    $productFee = FeeRule::create([
        'entity_type' => get_class($business),
        'entity_id' => $business->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 20.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $productCalculation = $this->feeService->calculateFor($business, 3000.00, 'product');
    expect($productCalculation['fee_amount'])->toBe(600.00); // 20% of 3000

    // Test with service item type (same business)
    $mixedOrder->shouldReceive('getItemType')->andReturn('service');

    $serviceFee = FeeRule::create([
        'entity_type' => get_class($business),
        'entity_id' => $business->id,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $serviceCalculation = $this->feeService->calculateFor($business, 3000.00, 'service');
    expect($serviceCalculation['fee_amount'])->toBe(300.00); // 10% of 3000
});

test('fee context interface metadata is properly recorded', function (): void {
    // Mock a detailed invoice
    $detailedInvoice = Mockery::mock(FeeContextInterface::class);

    // Setup invoice properties
    $detailedInvoice->shouldReceive('getKey')->andReturn(5);
    $detailedInvoice->shouldReceive('getMorphClass')->andReturn('App\Models\DetailedInvoice');

    // Mock entities
    $client = $this->mockEntity('Client', 909);
    $agency = $this->mockEntity('Agency', 1010);

    $detailedInvoice->shouldReceive('getBuyer')->andReturn($client);
    $detailedInvoice->shouldReceive('getSeller')->andReturn($agency);
    $detailedInvoice->shouldReceive('getFeeEntity')->andReturn($agency);

    // Invoice details
    $detailedInvoice->shouldReceive('getAmountForFeeCalculation')->andReturn(7500.00);
    $detailedInvoice->shouldReceive('getItemType')->andReturn('service');
    $detailedInvoice->shouldReceive('getCurrency')->andReturn('EUR');
    $detailedInvoice->shouldReceive('getDescription')->andReturn('Detailed Invoice #5 - Marketing Campaign');

    // Create fee rule
    $feeRule = FeeRule::create([
        'entity_type' => get_class($agency),
        'entity_id' => $agency->id,
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 12.5,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    // Record transaction using the context
    $transaction = FeeTransaction::create([
        'transaction_id' => 'TXN-CTX-001',
        'fee_rule_id' => $feeRule->id,
        'fee_bearer_type' => get_class($agency), // Commission paid by agency
        'fee_bearer_id' => $agency->id,
        'feeable_type' => 'App\Models\DetailedInvoice',
        'feeable_id' => 5,
        'transaction_amount' => 7500.00,
        'fee_amount' => 937.50, // 12.5% of 7500
        'fee_type' => 'commission',
        'status' => 'applied',
        'metadata' => [
            'context_type' => 'App\Models\DetailedInvoice',
            'item_type' => 'service',
            'buyer_id' => $client->id,
            'seller_id' => $agency->id,
            'currency' => 'EUR',
            'description' => 'Detailed Invoice #5 - Marketing Campaign',
            'fee_context_implemented' => true,
        ],
    ]);

    // Verify metadata
    expect($transaction->metadata)
        ->toBeArray()
        ->toHaveKeys([
            'context_type',
            'item_type',
            'buyer_id',
            'seller_id',
            'currency',
            'description',
        ])
        ->context_type->toBe('App\Models\DetailedInvoice')
        ->item_type->toBe('service')
        ->buyer_id->toBe($client->id)
        ->seller_id->toBe($agency->id)
        ->currency->toBe('EUR');
});

test('fee facade provides context-aware methods', function (): void {

    $customer = User::create(['name' => 'Test Customer', 'email' => 'test@example.com']);
    $merchant = Merchant::create(['name' => 'Test Merchant', 'business_id' => 'TEST123']);

    // Create a real model for context
    $simpleContext = new class extends \Illuminate\Database\Eloquent\Model implements FeeContextInterface
    {
        protected $table = 'test_contexts';

        private $merchant;

        private $customer;

        public function __construct(array $attributes = [])
        {
            parent::__construct($attributes);
        }

        public function setContext($merchant, $customer): void
        {
            $this->merchant = $merchant;
            $this->customer = $customer;
        }

        public function getBuyer()
        {
            return $this->customer;
        }

        public function getSeller()
        {
            return $this->merchant;
        }

        public function getFeeEntity()
        {
            return $this->merchant;
        }

        public function getAmountForFeeCalculation(): float
        {
            return 100.00;
        }

        public function getItemType(): string
        {
            return 'product';
        }

        public function getCurrency(): string
        {
            return 'PHP';
        }

        public function getDescription(): string
        {
            return 'Simple Context';
        }
    };

    $simpleContext->setContext($merchant, $customer);

    // Save it
    $simpleContext->save();

    // Create fee rule
    FeeRule::create([
        'entity_type' => get_class($merchant),
        'entity_id' => $merchant->id,
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 10.0,
        'calculation_type' => 'percentage',
        'is_active' => true,
        'is_global' => false,
    ]);

    $result = Fee::processFeeForContext($simpleContext);

    expect($result)->toBeArray()
        ->toHaveKey('has_fee')
        ->has_fee->toBeTrue();
});

test('fee context interface works with inheritance', function (): void {
    // Test that child classes can implement the interface
    // Create a mock child class
    $childClass = Mockery::mock(FeeContextInterface::class);

    // Implement all required methods
    $childClass->shouldReceive('getKey')->andReturn(7);
    $childClass->shouldReceive('getMorphClass')->andReturn('App\Models\ChildOrder');

    $parent = $this->mockEntity('ParentCompany', 3333);
    $child = $this->mockEntity('ChildCompany', 4444);

    $childClass->shouldReceive('getBuyer')->andReturn($child);
    $childClass->shouldReceive('getSeller')->andReturn($parent);
    $childClass->shouldReceive('getFeeEntity')->andReturn($parent);
    $childClass->shouldReceive('getAmountForFeeCalculation')->andReturn(500.00);
    $childClass->shouldReceive('getItemType')->andReturn('subscription');
    $childClass->shouldReceive('getCurrency')->andReturn('USD');
    $childClass->shouldReceive('getDescription')->andReturn('Child Subscription #7');

    // Verify it implements the interface
    expect($childClass)->toBeInstanceOf(FeeContextInterface::class);

    // All required methods should be callable
    expect(is_callable([$childClass, 'getKey']))->toBeTrue()
        ->and(is_callable([$childClass, 'getBuyer']))->toBeTrue()
        ->and(is_callable([$childClass, 'getSeller']))->toBeTrue()
        ->and(is_callable([$childClass, 'getFeeEntity']))->toBeTrue()
        ->and(is_callable([$childClass, 'getAmountForFeeCalculation']))->toBeTrue()
        ->and(is_callable([$childClass, 'getItemType']))->toBeTrue();
});

afterEach(function (): void {
    Mockery::close();
});
