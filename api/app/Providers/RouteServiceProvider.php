<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\FltOnboardController;
use App\Http\Controllers\TravoFileController;
use App\Http\Controllers\CustomLookupController;
use App\Http\Controllers\CustomAuthController;
use App\Http\Controllers\CustomOrderConfigController;
use App\Http\Controllers\CustomOrderController;


class RouteServiceProvider extends ServiceProvider
{
    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(
            function () {

                Route::get(
                    '/status',
                    function () {
                        return response()->json(
                            [
                                'status' => 'ok',
                                'time' => microtime(true) - LARAVEL_START
                            ]
                        );
                    }
                );

                Route::post(
                    '/int/v1/fltonboard/create-account',
                    [FltOnboardController::class, 'createAccount']
                );

               
                Route::middleware('web')->post(
                    '/int/v1/travofile/upload',
                    [TravoFileController::class, 'upload']
                );

                Route::get('/int/v1/lookup/fleetbase-blog', 
                    [CustomLookupController::class, 'fleetbaseBlog']
                );

                Route::post(
                    'int/v1/auth/get-magic-reset-link',
                    [CustomAuthController::class, 'createPasswordReset']
                );

                Route::prefix('int/v1')->middleware(['auth:sanctum'])->group(function () {
                    Route::get('order-configs', [CustomOrderConfigController::class, 'index']);
                });

                // Route::prefix('int/v1')->middleware(['auth:sanctum'])->group(function () {
                //     Route::get('orders', [CustomOrderController::class, 'query']);
                // });
                
                
            }
        );
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for(
            'api',
            function (Request $request) {
                return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
            }
        );
    }

}
