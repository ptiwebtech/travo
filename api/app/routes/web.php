<?php
use Illuminate\Support\Facades\Route;


Route::get('/hello', function () {
  return 'Hello, World!';
});

Route::get('/int/v1/fltonboard/create-account', [FltOnboardController::class, 'createAccount']);

