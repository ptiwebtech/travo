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
use App\Http\Controllers\BusRouteController;
use App\Http\Controllers\AdditionalServiceController;
use App\Http\Controllers\QuoteShareController;


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

                Route::post(
                    'int/v1/auth/vendor-signup',
                    [CustomAuthController::class, 'vendorSignup']
                );

                // Travo: shareable quote pages (public page is proxied as https://travo.ng/quote/{code})
                Route::get('/share/quote/{code}', [QuoteShareController::class, 'show'])->where('code', '[A-Za-z0-9_\-]+')->middleware('throttle:120,1');
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/orders/{id}/share-link', [QuoteShareController::class, 'link']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:20,1'])->post('travo/orders/{id}/share-quote-email', [QuoteShareController::class, 'email']);
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/orders/{id}/flow', [\App\Http\Controllers\OrderFlowController::class, 'show']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:30,1'])->post('travo/orders/{id}/flow', [\App\Http\Controllers\OrderFlowController::class, 'advance']);
                // Travo Driver: web "Navigator" inside Travo (28 Oct 2026)
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/driver', [\App\Http\Controllers\TravoDriverController::class, 'me']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:10,1'])->post('travo/driver/register', [\App\Http\Controllers\TravoDriverController::class, 'register']);
                Route::prefix('int/v1')->middleware(['throttle:240,1'])->post('travo/bgloc/{driver}/{token}', [\App\Http\Controllers\TravoDriverController::class, 'bgloc']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:60,1'])->get('travo/orders-recent', [\App\Http\Controllers\TravoDriverController::class, 'recentOrders']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:120,1'])->get('travo/driver/admin/{id}/live', [\App\Http\Controllers\TravoDriverController::class, 'adminLive']);
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->namespace('Travo\Internal')->get('fleet-ops/live/drivers', [\App\Http\Controllers\TravoDriverController::class, 'liveDrivers']);
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->namespace('Travo\Internal')->get('fleet-ops/live/vehicles', [\App\Http\Controllers\TravoDriverController::class, 'liveVehicles']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:60,1'])->post('travo/driver/application', [\App\Http\Controllers\TravoDriverController::class, 'application']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:40,1'])->post('travo/driver/docs', [\App\Http\Controllers\TravoDriverController::class, 'doc']);
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/driver/docs/{file}', [\App\Http\Controllers\TravoDriverController::class, 'docFile']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:10,1'])->post('travo/driver/reopen', [\App\Http\Controllers\TravoDriverController::class, 'reopen']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:30,1'])->post('travo/driver/offers/{offer}', [\App\Http\Controllers\TravoDriverController::class, 'offer']);
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/driver/admin/{id}', [\App\Http\Controllers\TravoDriverController::class, 'adminShow']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:30,1'])->post('travo/orders/{id}/offers', [\App\Http\Controllers\TravoDriverController::class, 'offers']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:30,1'])->post('travo/driver/online', [\App\Http\Controllers\TravoDriverController::class, 'online']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:20,1'])->post('travo/driver/track', [\App\Http\Controllers\TravoDriverController::class, 'track']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:20,1'])->post('travo/driver/jobs/{order}', [\App\Http\Controllers\TravoDriverController::class, 'job']);
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/driver/proof/{file}', [\App\Http\Controllers\TravoDriverController::class, 'proof'])->where('file', '[0-9a-f-]{36}');
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/driver/admin', [\App\Http\Controllers\TravoDriverController::class, 'adminList']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:60,1'])->post('travo/driver/admin/{id}/review', [\App\Http\Controllers\TravoDriverController::class, 'review']);
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/orders/{id}/driver', [\App\Http\Controllers\TravoDriverController::class, 'orderDriver']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:60,1'])->post('travo/orders/{id}/driver', [\App\Http\Controllers\TravoDriverController::class, 'assign']);
                // Travo: email calendar opt-out (public page is proxied as https://travo.ng/unsubscribe/{code})
                Route::get('/share/unsubscribe/{code}', [\App\Http\Controllers\UnsubscribeController::class, 'show'])->where('code', '[A-Za-z0-9_\-]+')->middleware('throttle:60,1');
                Route::post('/share/unsubscribe/{code}', [\App\Http\Controllers\UnsubscribeController::class, 'update'])->where('code', '[A-Za-z0-9_\-]+')->middleware('throttle:60,1');
                // Travo: shareable tracking pages (public page is proxied as https://travo.ng/track/{code})
                Route::get('/share/track/{code}', [\App\Http\Controllers\OrderTrackController::class, 'show'])->where('code', '[A-Za-z0-9_\-]+')->middleware('throttle:120,1');
                Route::get('/share/track/{code}/file/{file}', [\App\Http\Controllers\OrderTrackController::class, 'file'])->where(['code' => '[A-Za-z0-9_\-]+', 'file' => '[A-Za-z0-9_\-]+'])->middleware('throttle:240,1');
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/orders/{id}/track-link', [\App\Http\Controllers\OrderTrackController::class, 'link']);
                // Travo: error notices / failed requests seen in the console (storage/logs/travo-client-errors-*.log)
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:30,1'])->post('travo/client-errors', [\App\Http\Controllers\ClientErrorController::class, 'store']);
                // Travo card payments (Quickteller); public pages proxied as https://travo.ng/pay/...
                Route::match(['get', 'post'], '/share/pay/return', [\App\Http\Controllers\TravoCardController::class, 'back'])->middleware('throttle:60,1');
                Route::get('/share/pay/go/{txn}/{sig}', [\App\Http\Controllers\TravoCardController::class, 'goSigned'])->where(['txn' => 'TRVC[A-Z0-9]+', 'sig' => '[0-9a-f]{24}'])->middleware('throttle:30,1');
                Route::match(['get', 'post'], '/share/pay/{code}', [\App\Http\Controllers\TravoCardController::class, 'order'])->where('code', '[A-Za-z0-9_]+-[A-Za-z0-9_-]{12}')->middleware('throttle:30,1');
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:120,1'])->get('travo/card/status', [\App\Http\Controllers\TravoCardController::class, 'status']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:60,1'])->get('travo/wigmore/products', [\App\Http\Controllers\TravoWigmoreController::class, 'products']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:30,1'])->get('travo/card/order/{id}', [\App\Http\Controllers\TravoCardController::class, 'orderLink'])->where('id', '[A-Za-z0-9_]+');
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:10,1'])->post('travo/wallet/card-topup', [\App\Http\Controllers\TravoCardController::class, 'topup']);
                // Travo chat (support, network, files, quotes); files at https://travo.ng/chat-file/{uuid}/{sig}
                Route::get('/share/chat-file/{uuid}/{sig}', [\App\Http\Controllers\TravoChatController::class, 'file'])->where(['uuid' => '[0-9a-f-]{36}', 'sig' => '[0-9a-f]{32}'])->middleware('throttle:240,1');
                Route::match(['get', 'post'], '/share/unsubscribe/chat/{uuid}/{sig}', [\App\Http\Controllers\TravoChatController::class, 'emailOff'])->where(['uuid' => '[0-9a-f-]{36}', 'sig' => '[0-9a-f]{32}'])->middleware('throttle:60,1');   // chat email opt-out (27 Oct 2026)
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:120,1'])->get('travo/chat', [\App\Http\Controllers\TravoChatController::class, 'index']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:240,1'])->get('travo/chat/unread', [\App\Http\Controllers\TravoChatController::class, 'unread']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:20,1'])->post('travo/chat/support', [\App\Http\Controllers\TravoChatController::class, 'support']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:20,1'])->post('travo/chat/direct', [\App\Http\Controllers\TravoChatController::class, 'direct']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:300,1'])->get('travo/chat/{id}/messages', [\App\Http\Controllers\TravoChatController::class, 'messages'])->where('id', '[A-Za-z0-9_-]+');
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:40,1'])->post('travo/chat/{id}/messages', [\App\Http\Controllers\TravoChatController::class, 'send'])->where('id', '[A-Za-z0-9_-]+');
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:15,1'])->post('travo/chat/{id}/files', [\App\Http\Controllers\TravoChatController::class, 'upload'])->where('id', '[A-Za-z0-9_-]+');
                // Travo: vendor job offers (public pages proxied as https://travo.ng/vendor-offer/{token})
                Route::match(['get', 'post'], '/share/vendor-offer/{token}', [\App\Http\Controllers\VendorOfferController::class, 'show'])->where('token', '[A-Za-z0-9]+')->middleware('throttle:60,1');
                Route::match(['get', 'post'], '/share/vendor-offer/{token}/stop', [\App\Http\Controllers\VendorOfferController::class, 'stop'])->where('token', '[A-Za-z0-9]+')->middleware('throttle:30,1');
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/orders/{id}/vendor-offers', [\App\Http\Controllers\VendorOfferController::class, 'forOrder']);
                // Travo: My Network + Wallet (referral links are proxied as https://travo.ng/r/{code})
                Route::get('/share/r/{code}', [\App\Http\Controllers\NetworkController::class, 'landing'])->where('code', '[A-Za-z0-9\-]+')->middleware('throttle:120,1');
                Route::match(['get', 'post'], '/share/r/{code}/no/{token}', [\App\Http\Controllers\NetworkController::class, 'optOut'])->where(['code' => '[A-Za-z0-9\-]+', 'token' => '[A-Za-z0-9]+'])->middleware('throttle:60,1');
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/network', [\App\Http\Controllers\NetworkController::class, 'network']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:10,1'])->post('travo/network/invites', [\App\Http\Controllers\NetworkController::class, 'invite']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:30,1'])->post('travo/network/invites/{id}/remind', [\App\Http\Controllers\NetworkController::class, 'remind'])->where('id', '[0-9]+');
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:10,1'])->post('travo/network/claim', [\App\Http\Controllers\NetworkController::class, 'claim']);
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/wallet', [\App\Http\Controllers\NetworkController::class, 'wallet']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:10,1'])->post('travo/wallet/topup', [\App\Http\Controllers\NetworkController::class, 'topup']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:10,1'])->post('travo/wallet/pay', [\App\Http\Controllers\NetworkController::class, 'pay']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:5,1'])->post('travo/wallet/payout', [\App\Http\Controllers\NetworkController::class, 'payout']);
                Route::prefix('int/v1')->middleware(['auth:sanctum'])->get('travo/admin/wallet', [\App\Http\Controllers\NetworkController::class, 'admin']);
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:60,1'])->post('travo/admin/wallet/{ref}', [\App\Http\Controllers\NetworkController::class, 'decide'])->where('ref', 'WAL-[A-Za-z0-9]+');
                Route::prefix('int/v1')->middleware(['auth:sanctum', 'throttle:30,1'])->post('travo/admin/wallet-adjust', [\App\Http\Controllers\NetworkController::class, 'adjust']);

                Route::prefix('int/v1')->middleware(['auth:sanctum'])->group(function () {
                    Route::get('order-configs', [CustomOrderConfigController::class, 'index']);
                    
                    // Bus Routes API (Standard CRUD)
                    Route::prefix('bus-routes')->group(function () {
                        Route::get('/', [BusRouteController::class, 'query']);
                        Route::post('/', [BusRouteController::class, 'create']);
                        Route::get('/{id}', [BusRouteController::class, 'find']);
                        Route::put('/{id}', [BusRouteController::class, 'update']);
                        Route::delete('/{id}', [BusRouteController::class, 'delete']);
                    });

                    Route::prefix('additional-services')->group(function () {
                        Route::get('/',        [AdditionalServiceController::class, 'query']);
                        Route::post('/',       [AdditionalServiceController::class, 'create']);
                        Route::get('/{id}',    [AdditionalServiceController::class, 'find']);
                        Route::put('/{id}',    [AdditionalServiceController::class, 'update']);
                        Route::delete('/{id}', [AdditionalServiceController::class, 'delete']);
                    });
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
