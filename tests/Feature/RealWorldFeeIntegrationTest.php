<?php

namespace Repay\Fee\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Repay\Fee\Contracts\FeeableInterface;
use Repay\Fee\Enums\CalculationType;
use Repay\Fee\Enums\FeeType;
use Repay\Fee\Facades\Fee;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;
use Repay\Fee\Traits\HasFee;

// Real models that would exist in a Laravel application
class Customer extends Model
{
    protected $table = 'customers';

    protected $fillable = ['name', 'email'];
}

class Merchant extends Model
{
    protected $table = 'merchants';

    protected $fillable = ['name', 'business_type'];
}

class Product extends Model
{
    protected $table = 'products';

    protected $fillable = ['name', 'price'];
}

class Order extends Model implements FeeableInterface
{
    use HasFee;

    protected $table = 'orders';

    protected $fillable = [
        'customer_id',
        'merchant_id',
        'product_id',
        'quantity',
        'unit_price',
        'total_amount',
        'status',
        'order_number',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // FeeableInterface implementation
    public function getFeeEntity()
    {
        return $this->merchant;
    }

    public function getFeeItemType(): string
    {
        return 'product';
    }

    public function getFeeBaseAmount(): float
    {
        return (float) $this->total_amount;
    }

    public function getBuyer()
    {
        return $this->customer;
    }

    public function getSeller()
    {
        return $this->merchant;
    }
}

class ServiceProvider extends Model
{
    protected $table = 'service_providers';

    protected $fillable = ['name', 'service_type'];
}

class Service extends Model
{
    protected $table = 'services';

    protected $fillable = ['name', 'hourly_rate'];
}

class ServiceBooking extends Model implements FeeableInterface
{
    use HasFee;

    protected $table = 'service_bookings';

    protected $fillable = [
        'client_id',
        'provider_id',
        'service_id',
        'hours',
        'rate',
        'total_amount',
        'booking_date',
        'reference_number',
    ];

    protected $casts = [
        'hours' => 'decimal:1',
        'rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'booking_date' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Customer::class, 'client_id');
    }

    public function provider()
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    // FeeableInterface implementation
    public function getFeeEntity()
    {
        return $this->provider;
    }

    public function getFeeItemType(): string
    {
        return 'service';
    }

    public function getFeeBaseAmount(): float
    {
        return (float) $this->total_amount;
    }

    public function getBuyer()
    {
        return $this->client;
    }

    public function getSeller()
    {
        return $this->provider;
    }
}

beforeEach(function () {
    // Create database tables
    $schema = Schema::connection('testing');

    if (! $schema->hasTable('customers')) {
        $schema->create('customers', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    if (! $schema->hasTable('merchants')) {
        $schema->create('merchants', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('business_type');
            $table->timestamps();
        });
    }

    if (! $schema->hasTable('products')) {
        $schema->create('products', function ($table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }

    if (! $schema->hasTable('service_providers')) {
        $schema->create('service_providers', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('service_type');
            $table->timestamps();
        });
    }

    if (! $schema->hasTable('services')) {
        $schema->create('services', function ($table) {
            $table->id();
            $table->string('name');
            $table->decimal('hourly_rate', 10, 2);
            $table->timestamps();
        });
    }

    if (! $schema->hasTable('service_bookings')) {
        $schema->create('service_bookings', function ($table) {
            $table->id();
            $table->foreignId('client_id')->constrained('customers');
            $table->foreignId('provider_id')->constrained('service_providers');
            $table->foreignId('service_id')->constrained('services');
            $table->decimal('hours', 4, 1);
            $table->decimal('rate', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->timestamp('booking_date');
            $table->string('reference_number')->unique();
            $table->timestamps();
        });
    }

    // Clear existing data
    FeeTransaction::query()->delete();
    FeeRule::query()->delete();
    Order::query()->delete();
    ServiceBooking::query()->delete();
    Customer::query()->delete();
    Merchant::query()->delete();
    Product::query()->delete();
    ServiceProvider::query()->delete();
    Service::query()->delete();
});

test('complete real-world e-commerce scenario', function () {
    // 1. Setup the world
    $customer = Customer::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    $merchant = Merchant::create([
        'name' => 'Tech Store Inc.',
        'business_type' => 'electronics',
    ]);

    $product = Product::create([
        'name' => 'Smartphone X',
        'price' => 500.00,
    ]);

    // 2. Merchant sets up their fee rules
    $merchantMarkup = Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 5.0, // 5% markup
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
        'effective_from' => now()->addDays(1),
    ], $merchant);

    // Also create a global fee as fallback
    $globalMarkup = Fee::createGlobalFee([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 3.0, // 3% global markup
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
        'effective_from' => now()->addDays(2),
    ]);

    // 3. Customer places an order
    $order = Order::create([
        'customer_id' => $customer->id,
        'merchant_id' => $merchant->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 500.00,
        'total_amount' => 1000.00,
        'status' => 'confirmed',
        'order_number' => 'ORD-'.time(),
    ]);

    // 4. Check fee calculation before processing
    expect($order->fee)->toBe(0.0); // No fee processed yet
    expect($order->total_with_fee)->toBe(1000.0); // Just the order amount
    expect($order->has_fee_processed)->toBeFalse();


	$merchantMarkup->effective_from = now()->subDays(5);
	$merchantMarkup->save();

    // Calculate what the fee would be
    $calculation = Fee::calculateFeeForModel($order);
    expect($calculation['fee_amount'])->toBe(50.0); // 5% of 1000

    // 5. Process the fee (this is what would happen at checkout)
    $feeResult = Fee::processFeeForModel($order);

    expect($feeResult['has_fee'])->toBeTrue()
        ->and($feeResult['fee_amount'])->toBe('50.0000')
        ->and($feeResult['transaction'])->toBeInstanceOf(FeeTransaction::class)
        ->and($feeResult['transaction']->fee_type)->toBe(FeeType::MARKUP)
        ->and($feeResult['transaction']->fee_bearer_id)->toBe($customer->id); // Customer pays markup

    // 6. Verify the order now shows the fee
    $order->refresh();
    $order->load('feeTransaction');

    expect($order->fee)->toBe(50.0)
        ->and($order->total_with_fee)->toBe(1050.0)
        ->and($order->has_fee_processed)->toBeTrue()
        ->and($order->feeTransaction->transaction_id)->toBe($feeResult['transaction']->transaction_id);

    // 7. Check the fee rule used
    expect($order->fee_rule)->not()->toBeNull()
        ->and($order->fee_rule->id)->toBe($merchantMarkup->id) // Used merchant-specific rule
        ->and($order->fee_rule->is_global)->toBeFalse();

    // 8. Merchant views their fee transactions
    $merchantFees = Fee::getFeesForBearer($merchant);
    expect($merchantFees->count())->toBe(0); // Merchant doesn't bear markup fees

    $customerFees = Fee::getFeesForBearer($customer);
    expect($customerFees->count())->toBe(1); // Customer bears the markup

    // 9. Get analytics for merchant
    $merchantRevenue = Fee::getTotalFeesForBearer($merchant);
    expect($merchantRevenue['total_fee_amount'])->toBe(0); // No fees borne by merchant

    // 10. What if merchant updates their fee?
    $newMerchantMarkup = Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 7.5, // Increased to 7.5%
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
    ], $merchant);

    // New order with updated fee
    $order2 = Order::create([
        'customer_id' => $customer->id,
        'merchant_id' => $merchant->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 500.00,
        'total_amount' => 500.00,
        'status' => 'confirmed',
        'order_number' => 'ORD-'.(time() + 1),
    ]);

    $feeResult2 = Fee::processFeeForModel($order2);
    expect($feeResult2['fee_amount'])->toBe('37.5000'); // 7.5% of 500

    // 11. Both orders show in customer's fee history
    $customerFees = Fee::getFeesForBearer($customer);
    expect($customerFees->total())->toBe(2);

    // 12. Check fee history
    $merchantFeeHistory = Fee::getHistoryForEntity($merchant);
    expect($merchantFeeHistory['data'])->toHaveCount(2); // Created 2 fee rules
});

test('complete real-world service booking scenario', function () {
    // 1. Setup service world
    $client = Customer::create([
        'name' => 'Jane Smith',
        'email' => 'jane@example.com',
    ]);

    $provider = ServiceProvider::create([
        'name' => 'Expert Consulting LLC',
        'service_type' => 'consulting',
    ]);

    $service = Service::create([
        'name' => 'Business Strategy Consultation',
        'hourly_rate' => 200.00,
    ]);

    // 2. Provider sets up commission fee
    $providerCommission = Fee::setFeeForEntity([
        'item_type' => 'service',
        'fee_type' => 'commission',
        'value' => 10.0, // 10% commission to platform
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
    ], $provider);

    // Platform also sets convenience fee for clients
    $globalConvenience = Fee::createGlobalFee([
        'item_type' => 'service',
        'fee_type' => 'convenience',
        'value' => 25.0, // $25 fixed convenience fee
        'calculation_type' => CalculationType::FLAT,
        'is_active' => true,
    ]);

    // 3. Client books a service
    $booking = ServiceBooking::create([
        'client_id' => $client->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'hours' => 3.0,
        'rate' => 200.00,
        'total_amount' => 600.00, // 3 hours × $200
        'booking_date' => now()->addDays(7),
        'reference_number' => 'BOOK-'.time(),
    ]);

    // 4. Check which fee applies (commission takes precedence over convenience)
    expect($booking->fee_rule->fee_type)->toBe('commission')
        ->and($booking->fee_rule->value)->toBe('10.0000');

    // 5. Process fee - should use commission (provider pays)
    $feeResult = Fee::processFeeForModel($booking);

    expect($feeResult['has_fee'])->toBeTrue()
        ->and($feeResult['fee_amount'])->toBe('60.0000') // 10% of 600
        ->and($feeResult['transaction']->fee_type)->toBe(FeeType::COMMISSION)
        ->and($feeResult['transaction']->fee_bearer_id)->toBe($provider->id); // Provider pays commission

    // 6. Booking shows the fee
    $booking->refresh();
    $booking->load('feeTransaction');

    expect($booking->fee)->toBe(60.0)
        ->and($booking->total_with_fee)->toBe(660.0) // Client pays 600, provider pays 60 commission
        ->and($booking->has_fee_processed)->toBeTrue();

    // 7. What if provider deactivates commission? Should fall back to convenience
    /* $providerCommission->update(['is_active' => false]); */
    $providerCommission->deactivate();

    // New booking
    $booking2 = ServiceBooking::create([
        'client_id' => $client->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'hours' => 2.0,
        'rate' => 200.00,
        'total_amount' => 400.00,
        'booking_date' => now()->addDays(14),
        'reference_number' => 'BOOK-'.(time() + 1),
    ]);

    // Should now use global convenience fee
    expect($booking2->fee_rule->fee_type)->toBe('convenience')
        ->and($booking2->fee_rule->is_global)->toBeTrue();

    $feeResult2 = Fee::processFeeForModel($booking2);
    expect($feeResult2['fee_amount'])->toBe('25.0000') // Fixed convenience fee
        ->and($feeResult2['transaction']->fee_bearer_id)->toBe($client->id); // Client pays convenience fee

    // 8. Analytics for platform
    $today = now()->format('Y-m-d');
    $revenueToday = Fee::getRevenueByDateRange([
        'start_date' => $today,
        'end_date' => $today,
    ]);

    expect($revenueToday['daily_revenue'][$today])->toHaveKeys(['commission', 'convenience']);
});

test('mixed scenario with multiple merchants and fee types', function () {
    // Setup multiple merchants
    $merchant1 = Merchant::create(['name' => 'Merchant A', 'business_type' => 'retail']);
    $merchant2 = Merchant::create(['name' => 'Merchant B', 'business_type' => 'wholesale']);

    $customer = Customer::create(['name' => 'Test Customer', 'email' => 'test@example.com']);
    $product = Product::create(['name' => 'Test Product', 'price' => 100.00]);

    // Merchant 1: High markup
    Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 15.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
    ], $merchant1);

    // Merchant 2: Lower markup
    Fee::setFeeForEntity([
        'item_type' => 'product',
        'fee_type' => 'markup',
        'value' => 8.0,
        'calculation_type' => CalculationType::PERCENTAGE,
        'is_active' => true,
    ], $merchant2);

    // Create orders from both merchants
    $orders = collect();
    for ($i = 1; $i <= 5; $i++) {
        $merchant = $i <= 3 ? $merchant1 : $merchant2;
        $order = Order::create([
            'customer_id' => $customer->id,
            'merchant_id' => $merchant->id,
            'product_id' => $product->id,
            'quantity' => $i,
            'unit_price' => 100.00,
            'total_amount' => $i * 100.00,
            'status' => 'completed',
            'order_number' => 'BATCH-'.$i,
        ]);

        Fee::processFeeForModel($order);
        $orders->push($order);
    }

    // Verify different fees were applied
    $merchant1Orders = $orders->where('merchant_id', $merchant1->id);
    $merchant2Orders = $orders->where('merchant_id', $merchant2->id);

    // Merchant 1 orders: 15% markup
    $merchant1Orders->each(function ($order) {
        expect($order->fee)->toBe($order->total_amount * 0.15);
    });

    // Merchant 2 orders: 8% markup
    $merchant2Orders->each(function ($order) {
        expect($order->fee)->toBe($order->total_amount * 0.08);
    });

    // Check analytics
    $topGenerators = Fee::getTopRevenueGenerators(['limit' => 5]);
    expect($topGenerators['entities'][0]['entity_id'])->toBe($customer->id);
});

test('error handling and edge cases', function () {
    // Order without merchant (should use global fee)
    $customer = Customer::create(['name' => 'Test', 'email' => 'test@example.com']);
    $product = Product::create(['name' => 'Test', 'price' => 100.00]);

    // No merchant relationship
    $order = new Order([
        'customer_id' => $customer->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 100.00,
        'total_amount' => 100.00,
        'status' => 'pending',
        'order_number' => 'TEST-123',
    ]);

    // This should fail gracefully
    $result = $order->processFee();
    expect($result)->toHaveKey('has_fee')
        ->and($result['has_fee'])->toBeFalse();

    // Model without FeeableInterface
    $invalidModel = new class extends Model
    {
        use HasFee;

        public $id = 1;
    };

    expect($invalidModel->fee)->toBe(0.0)
        ->and($invalidModel->total_with_fee)->toBe(0.0)
        ->and($invalidModel->fee_rule)->toBeNull();
});

afterEach(function () {
    // Clean up (tables will be dropped automatically with :memory: sqlite)
});
