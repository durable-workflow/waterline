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

Route::prefix('api')->group(function () {
    Route::get('/stats', 'DashboardStatsController@index')->name('waterline.stats.index');

    Route::get('/flows/completed', 'WorkflowsController@completed')->name('waterline.completed');
    Route::get('/flows/failed', 'WorkflowsController@failed')->name('waterline.failed');
    Route::get('/flows/running', 'WorkflowsController@running')->name('waterline.running');
    Route::get('/flows/{id}', 'WorkflowsController@show')->name('waterline.show');
    Route::post('/flows/{id}/cancel', 'WorkflowsController@cancel')->name('waterline.cancel');
    Route::post('/flows/{id}/terminate', 'WorkflowsController@terminate')->name('waterline.terminate');
});

Route::get('/{view?}', 'DashboardController@index')->where('view', '(.*)')->name('waterline.index');
