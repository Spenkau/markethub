<?php

namespace App\Providers;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Policies\OrganizationBusinessPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Product::class, MarketplaceAccount::class, MarketplaceListing::class] as $model) {
            Gate::policy($model, OrganizationBusinessPolicy::class);
        }
    }
}
