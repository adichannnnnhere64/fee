<?php

// tests/TestCase.php

namespace Repay\Fee\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config(['fee.cache.enabled' => false]); // Disable cache for testing
        $this->createTestTables();
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Repay\Fee\FeeServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../src/database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Create a mock entity for testing
     */
    protected function mockEntity(string $modelName = 'User', int $id = 1)
    {
        return new class($modelName, $id)
        {
            public $id;

            public $name;

            public function __construct($modelName, $id)
            {
                $this->id = $id;
                $this->name = "Test {$modelName} {$id}";
            }

            public function getKey()
            {
                return $this->id;
            }

            // This simulates Laravel's morph class resolution
            public function getMorphClass()
            {
                return "App\\Models\\{$this->name}";
            }
        };
    }

    protected function createActiveFeeRule(array $data = []): \Repay\Fee\Models\FeeRule
    {
        return \Repay\Fee\Models\FeeRule::create(array_merge([
            'entity_type' => null,
            'entity_id' => null,
            'item_type' => 'product',
            'fee_type' => 'markup',
            'value' => 10.0,
            'calculation_type' => 'percentage',
            'is_active' => true,
            'is_global' => false,
            'effective_from' => now(),
        ], $data));
    }

    protected function createFeeHistory(array $data = []): \Repay\Fee\Models\FeeHistory
    {
        $feeRule = $this->createActiveFeeRule();

        return \Repay\Fee\Models\FeeHistory::create(array_merge([
            'fee_rule_id' => $feeRule->id,
            'entity_type' => $feeRule->entity_type,
            'entity_id' => $feeRule->entity_id,
            'action' => 'created',
            'old_data' => null,
            'new_data' => $feeRule->toArray(),
            'reason' => 'Test reason',
        ], $data));
    }

    protected function createTestTables(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        if (! $schema->hasTable('users')) {
            $schema->create('users', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('merchants')) {
            $schema->create('merchants', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('business_type')->nullable();
                $table->string('business_id')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('test_contexts')) {
            $schema->create('test_contexts', function ($table): void {
                $table->id();
                $table->timestamps();
            });

        }

        if (! $schema->hasTable('orders')) {
            $schema->create('orders', function ($table) {
                $table->id();
                $table->string('name')->nullable();
                $table->foreignId('customer_id')->nullable();
                $table->foreignId('merchant_id')->constrained('merchants');
                $table->foreignId('product_id')->nullable();
                $table->integer('quantity')->nullable();
                $table->decimal('unit_price', 10, 2)->nullable();
                $table->decimal('total_amount', 10, 2)->nullable();
                $table->string('status')->default('pending');
                $table->string('order_number')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('test_orders')) {
            $schema->create('test_orders', function ($table): void {
                $table->id();
                $table->integer('merchant_id');
                $table->integer('customer_id');
                $table->decimal('amount', 10, 2);
                $table->string('item_type')->default('product');
            });
        }

        if (! $schema->hasTable('test_services')) {
            $schema->create('test_services', function ($table): void {
                $table->id();
                $table->integer('provider_id');
                $table->integer('client_id');
                $table->decimal('total_amount', 10, 2);
            });
        }
    }
}
