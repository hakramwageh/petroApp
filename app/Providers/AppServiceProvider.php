<?php

namespace App\Providers;

use App\Contracts\TransferStoreInterface;
use App\Repositories\PostgresTransferRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TransferStoreInterface::class, PostgresTransferRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
