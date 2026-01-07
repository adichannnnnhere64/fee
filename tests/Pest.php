<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Repay\Fee\FeeServiceProvider;
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
        'database.default' => 'testing',
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
