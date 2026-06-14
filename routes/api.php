<?php

use App\Http\Controllers\Api\OnuLookupController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| External API (v1)
|--------------------------------------------------------------------------
|
| Authenticated with an API key + secret (see AuthenticateApiClient). The
| ability after `api.client:` scopes each endpoint, and `throttle:api-client`
| applies the per-key rate limit configured on the ApiClient record.
|
*/

Route::prefix('v1')->group(function () {
    Route::post('onu/lookup', OnuLookupController::class)
        ->middleware(['api.client:onu.lookup', 'throttle:api-client']);

    Route::post('sync/olt', [SyncController::class, 'olt'])
        ->middleware(['api.client:sync.request', 'throttle:api-client']);

    Route::post('sync/onu', [SyncController::class, 'onu'])
        ->middleware(['api.client:sync.request', 'throttle:api-client']);
});
