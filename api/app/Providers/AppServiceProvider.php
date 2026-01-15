<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
        config(['app.name' => 'Travo']);
        $this->app->booted(function () {
            \Config::set('mail.mailers.smtp', [
                'transport'  => 'smtp',
                'host'       => env('MAIL_HOST', 'smtp.ionos.co.uk'),
                'port'       => env('MAIL_PORT', 587),
                'encryption' => env('MAIL_ENCRYPTION', 'tls'),
                'username'   => env('MAIL_USERNAME'),
                'password'   => env('MAIL_PASSWORD'), // Fetches from .env
            ]);
    
            \Config::set('mail.from', [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@travoservices.com'),
                'name'    => env('MAIL_FROM_NAME', 'Travo Services'),
            ]);
        });
    }
}
