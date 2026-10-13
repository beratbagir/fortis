<?php

use App\Http\Controllers\Api\EntraController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Microsoft Entra ID ile giriş: tarayıcı yönlendirmesi, oturum (state) gerektiği için web ara katmanıyla.
Route::prefix('api/auth/entra')->name('auth.entra.')->group(function () {
    Route::get('redirect', [EntraController::class, 'redirect'])->name('redirect');
    Route::get('callback', [EntraController::class, 'callback'])->name('callback');
});
