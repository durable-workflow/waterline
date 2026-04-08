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
    Route::get('/instances/{instanceId}', 'WorkflowsController@showSelection')->name('waterline.instances.show');
    Route::get('/instances/{instanceId}/runs/{runId}', 'WorkflowsController@showSelection')->name('waterline.instances.runs.show');
    Route::post('/instances/{instanceId}/runs/{runId}/queries/{query}', 'WorkflowsController@querySelection')->name('waterline.instances.runs.query');
    Route::post('/instances/{instanceId}/runs/{runId}/signals/{signal}', 'WorkflowsController@signalSelection')->name('waterline.instances.runs.signal');
    Route::post('/instances/{instanceId}/runs/{runId}/updates/{update}', 'WorkflowsController@updateSelection')->name('waterline.instances.runs.update');
    Route::post('/instances/{instanceId}/runs/{runId}/repair', 'WorkflowsController@repairSelection')->name('waterline.instances.runs.repair');
    Route::post('/instances/{instanceId}/runs/{runId}/cancel', 'WorkflowsController@cancelSelection')->name('waterline.instances.runs.cancel');
    Route::post('/instances/{instanceId}/runs/{runId}/terminate', 'WorkflowsController@terminateSelection')->name('waterline.instances.runs.terminate');
    Route::post('/instances/{instanceId}/queries/{query}', 'WorkflowsController@queryInstance')->name('waterline.instances.query');
    Route::post('/instances/{instanceId}/signals/{signal}', 'WorkflowsController@signalInstance')->name('waterline.instances.signal');
    Route::post('/instances/{instanceId}/updates/{update}', 'WorkflowsController@updateInstance')->name('waterline.instances.update');
    Route::post('/instances/{instanceId}/repair', 'WorkflowsController@repairInstance')->name('waterline.instances.repair');
    Route::post('/instances/{instanceId}/cancel', 'WorkflowsController@cancelInstance')->name('waterline.instances.cancel');
    Route::post('/instances/{instanceId}/terminate', 'WorkflowsController@terminateInstance')->name('waterline.instances.terminate');
    Route::get('/flows/{id}', 'WorkflowsController@show')->name('waterline.show');
    Route::post('/flows/{id}/queries/{query}', 'WorkflowsController@query')->name('waterline.query');
    Route::post('/flows/{id}/signals/{signal}', 'WorkflowsController@signal')->name('waterline.signal');
    Route::post('/flows/{id}/updates/{update}', 'WorkflowsController@update')->name('waterline.update');
    Route::post('/flows/{id}/repair', 'WorkflowsController@repair')->name('waterline.repair');
    Route::post('/flows/{id}/cancel', 'WorkflowsController@cancel')->name('waterline.cancel');
    Route::post('/flows/{id}/terminate', 'WorkflowsController@terminate')->name('waterline.terminate');
});

Route::get('/{view?}', 'DashboardController@index')->where('view', '(.*)')->name('waterline.index');
