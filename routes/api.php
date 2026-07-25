<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — mounted under /api/v1 (see bootstrap/app.php)
|--------------------------------------------------------------------------
|
| Versioned from day one so a future Android/iOS client can pin v1 and keep
| working when v2 lands. Endpoints are added here as the mobile app needs
| them; the web app itself uses session auth via routes/web.php.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
});
