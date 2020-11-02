<?php

use Illuminate\Support\Facades\Route;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::prefix('plugins/oauth2')->middleware(['auth'])->group(function() {
    Route::get('/', 'Oauth2Controller@dashboard');
    Route::get('/providers/install', 'Oauth2Controller@showInstallProviderForm');
    Route::put('/providers', 'Oauth2Controller@installProvider');

    Route::get('/provider_clients/add', 'Oauth2Controller@showAddProviderClientForm');
    Route::post('/provider_clients', 'Oauth2Controller@addProviderClient');

    Route::get('/provider_clients/edit/{provider_client_id}', 'Oauth2Controller@showEditProviderClientForm');
    Route::put('/provider_clients/{provider_client_id}', 'Oauth2Controller@editProviderClient');
    Route::delete('/provider_clients/{provider_client_id}', 'Oauth2Controller@deleteProviderClient');
});

Route::group(['middleware' => 'web'], function () {
    Route::get('/login/{provider}/callback', 'LoginController@handleProviderCallback');
    Route::get('/login/{provider_client_id}', 'LoginController@redirectToProvider');
});
