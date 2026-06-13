<?php

use Illuminate\Support\Facades\Route;
use Waterline\Http\Controllers\DashboardStatsController;
use Waterline\Http\Controllers\SavedViewsController;
use Waterline\Http\Controllers\UserPreferencesController;
use Waterline\Http\Controllers\V2HealthController;
use Waterline\Http\Controllers\V2SchedulesController;
use Waterline\Http\Controllers\V2ServicesController;
use Waterline\Http\Controllers\WorkflowsController;

Route::prefix('api')->group(function () {
    Route::get('/stats', [DashboardStatsController::class, 'index'])->name('waterline.stats.index');
    Route::get('/v2/health', [V2HealthController::class, 'show'])->name('waterline.v2.health');
    Route::get('/saved-views', [SavedViewsController::class, 'index'])->name('waterline.saved-views.index');
    Route::post('/saved-views', [SavedViewsController::class, 'store'])->name('waterline.saved-views.store');
    Route::get('/saved-views/{view}', [SavedViewsController::class, 'show'])->name('waterline.saved-views.show');
    Route::put('/saved-views/{view}', [SavedViewsController::class, 'update'])->name('waterline.saved-views.update');
    Route::delete('/saved-views/{view}', [SavedViewsController::class, 'destroy'])->name('waterline.saved-views.destroy');
    Route::get('/preferences/{surface}', [UserPreferencesController::class, 'show'])->name('waterline.preferences.show');
    Route::put('/preferences/{surface}', [UserPreferencesController::class, 'update'])->name('waterline.preferences.update');

    Route::get('/flows/completed', [WorkflowsController::class, 'completed'])->name('waterline.completed');
    Route::get('/flows/failed', [WorkflowsController::class, 'failed'])->name('waterline.failed');
    Route::get('/flows/cancelled', [WorkflowsController::class, 'cancelled'])->name('waterline.cancelled');
    Route::get('/flows/terminated', [WorkflowsController::class, 'terminated'])->name('waterline.terminated');
    Route::get('/flows/running', [WorkflowsController::class, 'running'])->name('waterline.running');
    Route::get('/instances/{instanceId}', [WorkflowsController::class, 'showSelection'])->name('waterline.instances.show');
    Route::get('/instances/{instanceId}/runs/{runId}', [WorkflowsController::class, 'showSelection'])->name('waterline.instances.runs.show');
    Route::get('/instances/{instanceId}/history-export', [WorkflowsController::class, 'historyExportInstance'])->name('waterline.instances.history-export');
    Route::get('/instances/{instanceId}/runs/{runId}/history-export', [WorkflowsController::class, 'historyExportSelection'])->name('waterline.instances.runs.history-export');
    Route::get('/instances/{instanceId}/runs/{runId}/updates/{updateId}', [WorkflowsController::class, 'showUpdateSelection'])->name('waterline.instances.runs.updates.show');
    Route::post('/instances/{instanceId}/runs/{runId}/queries/{query}', [WorkflowsController::class, 'querySelection'])->name('waterline.instances.runs.query');
    Route::post('/instances/{instanceId}/runs/{runId}/signals/{signal}', [WorkflowsController::class, 'signalSelection'])->name('waterline.instances.runs.signal');
    Route::post('/instances/{instanceId}/runs/{runId}/updates/{update}', [WorkflowsController::class, 'updateSelection'])->name('waterline.instances.runs.update');
    Route::post('/instances/{instanceId}/runs/{runId}/repair', [WorkflowsController::class, 'repairSelection'])->name('waterline.instances.runs.repair');
    Route::post('/instances/{instanceId}/runs/{runId}/cancel', [WorkflowsController::class, 'cancelSelection'])->name('waterline.instances.runs.cancel');
    Route::post('/instances/{instanceId}/runs/{runId}/terminate', [WorkflowsController::class, 'terminateSelection'])->name('waterline.instances.runs.terminate');
    Route::post('/instances/{instanceId}/runs/{runId}/archive', [WorkflowsController::class, 'archiveSelection'])->name('waterline.instances.runs.archive');
    Route::get('/instances/{instanceId}/updates/{updateId}', [WorkflowsController::class, 'showUpdateInstance'])->name('waterline.instances.updates.show');
    Route::post('/instances/{instanceId}/queries/{query}', [WorkflowsController::class, 'queryInstance'])->name('waterline.instances.query');
    Route::post('/instances/{instanceId}/signals/{signal}', [WorkflowsController::class, 'signalInstance'])->name('waterline.instances.signal');
    Route::post('/instances/{instanceId}/updates/{update}', [WorkflowsController::class, 'updateInstance'])->name('waterline.instances.update');
    Route::post('/instances/{instanceId}/repair', [WorkflowsController::class, 'repairInstance'])->name('waterline.instances.repair');
    Route::post('/instances/{instanceId}/cancel', [WorkflowsController::class, 'cancelInstance'])->name('waterline.instances.cancel');
    Route::post('/instances/{instanceId}/terminate', [WorkflowsController::class, 'terminateInstance'])->name('waterline.instances.terminate');
    Route::post('/instances/{instanceId}/archive', [WorkflowsController::class, 'archiveInstance'])->name('waterline.instances.archive');
    Route::get('/v2/services/endpoints', [V2ServicesController::class, 'endpointsIndex'])->name('waterline.v2.services.endpoints.index');
    Route::get('/v2/services/endpoints/{endpointId}', [V2ServicesController::class, 'endpointShow'])->name('waterline.v2.services.endpoints.show');
    Route::get('/v2/services/services', [V2ServicesController::class, 'servicesIndex'])->name('waterline.v2.services.services.index');
    Route::get('/v2/services/services/{serviceId}', [V2ServicesController::class, 'serviceShow'])->name('waterline.v2.services.services.show');
    Route::get('/v2/services/operations', [V2ServicesController::class, 'operationsIndex'])->name('waterline.v2.services.operations.index');
    Route::get('/v2/services/operations/{operationId}', [V2ServicesController::class, 'operationShow'])->name('waterline.v2.services.operations.show');
    Route::get('/v2/services/calls', [V2ServicesController::class, 'callsIndex'])->name('waterline.v2.services.calls.index');
    Route::get('/v2/services/calls/{callId}', [V2ServicesController::class, 'callShow'])->name('waterline.v2.services.calls.show');

    Route::get('/v2/schedules', [V2SchedulesController::class, 'index'])->name('waterline.v2.schedules.index');
    Route::get('/v2/schedules/{scheduleId}', [V2SchedulesController::class, 'show'])->name('waterline.v2.schedules.show');
    Route::get('/v2/schedules/{scheduleId}/history', [V2SchedulesController::class, 'history'])->name('waterline.v2.schedules.history');
    Route::post('/v2/schedules/{scheduleId}/pause', [V2SchedulesController::class, 'pause'])->name('waterline.v2.schedules.pause');
    Route::post('/v2/schedules/{scheduleId}/resume', [V2SchedulesController::class, 'resume'])->name('waterline.v2.schedules.resume');
    Route::post('/v2/schedules/{scheduleId}/trigger', [V2SchedulesController::class, 'trigger'])->name('waterline.v2.schedules.trigger');
    Route::post('/v2/schedules/{scheduleId}/backfill', [V2SchedulesController::class, 'backfill'])->name('waterline.v2.schedules.backfill');
    Route::delete('/v2/schedules/{scheduleId}', [V2SchedulesController::class, 'destroy'])->name('waterline.v2.schedules.destroy');

    Route::get('/flows/{id}', [WorkflowsController::class, 'show'])->name('waterline.show');
    Route::get('/flows/{id}/history-export', [WorkflowsController::class, 'historyExport'])->name('waterline.history-export');
    Route::get('/flows/{id}/updates/{updateId}', [WorkflowsController::class, 'showUpdate'])->name('waterline.updates.show');
    Route::post('/flows/{id}/queries/{query}', [WorkflowsController::class, 'query'])->name('waterline.query');
    Route::post('/flows/{id}/signals/{signal}', [WorkflowsController::class, 'signal'])->name('waterline.signal');
    Route::post('/flows/{id}/updates/{update}', [WorkflowsController::class, 'update'])->name('waterline.update');
    Route::post('/flows/{id}/repair', [WorkflowsController::class, 'repair'])->name('waterline.repair');
    Route::post('/flows/{id}/cancel', [WorkflowsController::class, 'cancel'])->name('waterline.cancel');
    Route::post('/flows/{id}/terminate', [WorkflowsController::class, 'terminate'])->name('waterline.terminate');
    Route::post('/flows/{id}/archive', [WorkflowsController::class, 'archive'])->name('waterline.archive');
});
