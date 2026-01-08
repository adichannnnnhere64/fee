<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Repay\Fee\Enums\CalculationType;
use Repay\Fee\FeeServiceProvider;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;
use Repay\Fee\Tests\Fixtures\Merchant;
use Repay\Fee\Tests\TestCase;

/* use Orchestra\Testbench\TestCase; */

pest()->extend(TestCase::class)
    /* ->use(RefreshDatabase::class) */
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function something(): void {}

function withPackageProviders(): void
{
    config([
        'database.default' => 'sqlite',
        'database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);

    (new FeeServiceProvider(app()))->boot();
}

function migratePackage(): void
{
    /* Artisan::call('migrate'); */
}

function mockMerchant(int $id = 1)
{
    return new class($id)
    {
        public $id;

        public $name;

        public function __construct($id)
        {
            $this->id = $id;
            $this->name = 'Test Merchant '.$id;
        }

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

function mockUser(int $id = 1)
{
    return new class($id)
    {
        public $id;

        public $name;

        public function __construct($id)
        {
            $this->id = $id;

            $this->name = 'Test User '.$id;
        }

        public function getKey()
        {

            return $this->id;
        }

        public function getMorphClass()
        {
            return 'App\Models\User';

        }
    };
}

function createMerchant($name = 'rpy'): Merchant
{

    return Merchant::create([
        'name' => $name,
        'business_id' => 'repay',
    ]);

}

function createTransaction(
    string $feeType,
    float $feeAmount,

    ?Carbon $date = null,
    $feeBearer = null
): FeeTransaction {
    if (! $date) {
        $date = now();
    }

    if (! $feeBearer) {
        $feeBearer = mockEntity('User', 1);
    }

    $feeRule = FeeRule::create([
        'entity_type' => get_class($feeBearer),

        'entity_id' => $feeBearer->id,
        'item_type' => $feeType === 'markup' ? 'product' : 'service',
        'fee_type' => $feeType,
        'value' => 10.0,
        'calculation_type' => CalculationType::PERCENTAGE,
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

function createTransactionWithRule(
    string $feeType,
    float $feeAmount,
    ?Carbon $date,
    $feeBearer,
    FeeRule $feeRule
): FeeTransaction {
    if (! $date) {
        $date = now();
    }

    if (! $feeBearer) {
        $feeBearer = $this->mockEntity('User', 1);
    }

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

function mockEntity(string $modelName = 'User', int $id = 1)
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
