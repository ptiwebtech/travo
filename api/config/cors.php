<?php

use Fleetbase\Support\Utils;

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        'http://localhost:4200',                  // Development environment
        'https://travo.ng',                       // Main production domain (with HTTPS)
        'https://app.travo.ng',                   // API subdomain (with HTTPS)
        env('CONSOLE_HOST'),                      // Console host from environment
        Utils::addWwwToUrl(env('CONSOLE_HOST')),   // Adding 'www' to the console host dynamically
        'https://206.189.123.95',                 // IP (ensure it’s needed and correct)
    ]),

    'allowed_origins_patterns' => ['/^https?:\/\/(.*\.)?travo\.ng$/'],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['x-compressed-json', 'access-console-sandbox', 'access-console-sandbox-key'],

    'max_age' => 0,

    'supports_credentials' => false,

];
