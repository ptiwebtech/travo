<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
// Lookup package endpoint
Route::get(config('internals.api.routing.prefix', '~registry') . '/v1/lookup', 'Fleetbase\RegistryBridge\Http\Controllers\Internal\v1\RegistryController@lookupPackage');
Route::prefix(config('internals.api.routing.prefix', '~registry'))->middleware(['fleetbase.registry'])->namespace('Fleetbase\RegistryBridge\Http\Controllers')->group(
    function ($router) {
        /*
         * Internal Routes v1
         */
        $router->group(['prefix' => config('internals.api.routing.internal_prefix', 'v1'), 'namespace' => 'Internal\v1'], function ($router) {
            $router->group(['prefix' => 'auth'], function ($router) {
                $router->group(['middleware' => ['fleetbase.protected', 'throttle:60,1']], function ($router) {
                    $router->get('registry-tokens', 'RegistryAuthController@getRegistryTokens');
                    $router->delete('registry-tokens/{id}', 'RegistryAuthController@deleteRegistryToken');
                    $router->post('registry-tokens', 'RegistryAuthController@createRegistryUser');
                });

                $router->post('composer-auth', 'RegistryAuthController@composerAuthentication')->middleware([Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class]);
                $router->post('authenticate', 'RegistryAuthController@authenticate')->middleware([Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class]);
                $router->post('add-user', 'RegistryAuthController@addUser')->middleware([Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class]);
                $router->post('check-access', 'RegistryAuthController@checkAccess')->middleware([Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class]);
                $router->post('check-publish', 'RegistryAuthController@checkPublishAllowed')->middleware([Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class]);
            });

            $router->group(['middleware' => ['fleetbase.protected', 'throttle:60,1']], function ($router) {
                $router->get('categories', 'RegistryController@categories');
                $router->get('engines', 'RegistryController@getInstalledEngines');

                $router->group(['prefix' => 'installer'], function ($router) {
                    $router->post('install', 'ExtensionInstallerController@install');
                    $router->post('uninstall', 'ExtensionInstallerController@uninstall');
                });

                $router->group(['prefix' => 'payments'], function ($router) {
                    $router->post('account', 'RegistryPaymentsController@getStripeAccount');
                    $router->post('account-session', 'RegistryPaymentsController@getStripeAccountSession');
                    $router->get('has-stripe-connect-account', 'RegistryPaymentsController@hasStripeConnectAccount');
                    $router->post('create-checkout-session', 'RegistryPaymentsController@createStripeCheckoutSession');
                    $router->post('get-checkout-session', 'RegistryPaymentsController@getStripeCheckoutSessionStatus');
                    $router->get('author-received', 'RegistryPaymentsController@getAuthorReceivedPayments');
                });

                $router->fleetbaseRoutes('registry-extensions', function ($router, $controller) {
                    $router->post('{id}/submit', $controller('submit'));
                    $router->post('approve', $controller('approve'));
                    $router->post('reject', $controller('reject'));
                    $router->get('download-bundle', $controller('downloadBundle'));
                    $router->get('analytics', $controller('analytics'));
                    $router->get('installed', $controller('installed'))->middleware([Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class]);
                    $router->get('purchased', $controller('purchased'))->middleware([Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class]);
                    $router->get('config', $controller('getConfig'))->middleware([Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class]);
                    $router->post('config', $controller('saveConfig'))->middleware([Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class]);
                });

                $router->fleetbaseRoutes('registry-extension-bundles', function ($router, $controller) {
                    $router->get('download', $controller('download'));
                });
            });
        });
    }
);
