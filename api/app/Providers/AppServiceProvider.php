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
                'host'       => 'smtp.ionos.co.uk',
                'port'       => 587,
                'encryption' => 'tls',
                'username'   => 'hello@travoservices.com',
                'password'   => 'Lagos247@',
            ]);

            \Config::set('mail.from', [
                'address' => 'hello@travoservices.com',
                'name'    => 'Travo Services',
            ]);
        });
    }
}
