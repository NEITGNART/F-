<?php

/*
|--------------------------------------------------------------------------
| User API Routes
|--------------------------------------------------------------------------
|
| These routes are prefixed with 'api/v1'.
| These routes use the root namespace 'App\Http\Controllers\Api\V1'.
|
 */
use App\Base\Constants\Auth\Role;

/*
 * These routes are prefixed with 'api/v1/request'.
 * These routes use the root namespace 'App\Http\Controllers\Api\V1\Request'.
 * These routes use the middleware group 'auth'.
 */
Route::prefix('request')->namespace('Request')->middleware('auth')->group(function () {

    /**
     * These routes use the middleware group 'role'.
     * These routes are accessible only by a user with the 'user' role.
     */
    Route::middleware(role_middleware(Role::USER))->group(function () {
        // List Packages
        Route::post('list-packages', 'EtaController@listPackages');

        Route::get('promocode-list', 'PromoCodeController@index');
        // Create Request
        Route::post('create', 'CreateRequestController@createRequest');
        // Change Drop Location
        Route::post('change-drop-location', 'EtaController@changeDropLocation');
        // Cancel Request
        Route::post('cancel', 'UserCancelRequestController@cancelRequest');
    });

    // Eta
    Route::post('eta', 'EtaController@eta');

    /**
     * These routes use the middleware group 'role'.
     * These routes are accessible only by a driver with the 'driver' role.
     */
    Route::middleware(role_middleware(Role::DRIVER))->group(function () {
        // Create Instant Ride
        Route::post('create-instant-ride','InstantRideController@createRequest');
        // Accet/Reject Request
        Route::post('respond', 'RequestAcceptRejectController@respondRequest');
        // Arrived
        Route::post('arrived', 'DriverArrivedController@arrivedRequest');
        // Trip started
        Route::post('started', 'DriverTripStartedController@tripStart');
        // Cancel Request
        Route::post('cancel/by-driver', 'DriverCancelRequestController@cancelRequest');
        // End Request
        Route::post('end', 'DriverEndRequestController@endRequest');
    });

    // History
    Route::get('history', 'RequestHistoryController@index');
    Route::get('history/{id}', 'RequestHistoryController@getById');
    // Rate the Request
    Route::post('rating', 'RatingsController@rateRequest');
    // Chat 
    Route::get('chat-history/{request}','ChatController@history');
    //Send Sms
    Route::post('send','ChatController@send');
});
