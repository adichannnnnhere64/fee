<?php

namespace Repay\Fee;

use Illuminate\Support\ServiceProvider;

class FeeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/config.php' => config_path('fee.php'),
        ], 'fee-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'fee-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'fee');

        // Register individual services
        $this->app->singleton('fee.service', function ($app) {
            return new Services\FeeService;
        });

        $this->app->singleton('fee.history', function ($app) {
            return new Services\FeeHistoryService;
        });

        $this->app->singleton('fee.upcoming', function ($app) {
            return new Services\UpcomingFeeService;
        });

        $this->app->singleton('fee.transactions', function ($app) {
            return new Services\FeeTransactionService;
        });

        $this->app->singleton('fee.analytics', function ($app) {
            return new Services\AnalyticsService;

        });
        // IMPORTANT: Register the main Fee class as 'fee'
        $this->app->singleton('fee', function ($app) {
            return new Fee(
                $app['fee.service'],
                $app['fee.history'],
                $app['fee.upcoming'],
                $app['fee.transactions'],
                $app['fee.analytics']
            );
        });

        // Remove any alias that points FeeService
        // Only alias if you want \Repay\Fee\Fee to be available as a class
        $this->app->alias(Fee::class, 'fee.main');
    }
}
