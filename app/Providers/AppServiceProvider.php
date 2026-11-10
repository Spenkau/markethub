<?php

namespace App\Providers;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Policies\OrganizationBusinessPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('login', function (Request $request): array {
            $email = is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : '';

            return [
                Limit::perMinute(5)->by(hash('sha256', $email.'|'.$request->ip())),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });
        foreach ([Product::class, MarketplaceAccount::class, MarketplaceListing::class] as $model) {
            Gate::policy($model, OrganizationBusinessPolicy::class);
        }
    }
}
