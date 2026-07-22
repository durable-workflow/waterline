<?php

use Illuminate\Support\Facades\Route;
use Waterline\Http\Controllers\DashboardStatsController;
use Waterline\Http\Controllers\SavedViewsController;
use Waterline\Http\Controllers\UserPreferencesController;
use Waterline\Http\Controllers\V2HealthController;
use Waterline\Http\Controllers\V2SchedulesController;
use Waterline\Http\Controllers\V2ServicesController;
use Waterline\Http\Controllers\WorkflowsController;
use Waterline\Http\Controllers\Remote\RemoteCapabilityController;
use Waterline\Http\Controllers\Remote\RemoteHealthController;
use Waterline\Http\Controllers\Remote\RemoteSchedulesController;
use Waterline\Http\Controllers\Remote\RemoteStatsController;
use Waterline\Http\Controllers\Remote\RemoteWorkflowsController;
use Waterline\Support\BackendConfiguration;

$workflowsController = BackendConfiguration::serviceMode() ? RemoteWorkflowsController::class : WorkflowsController::class;
$statsController = BackendConfiguration::serviceMode() ? RemoteStatsController::class : DashboardStatsController::class;
$healthController = BackendConfiguration::serviceMode() ? RemoteHealthController::class : V2HealthController::class;
$schedulesController = BackendConfiguration::serviceMode() ? RemoteSchedulesController::class : V2SchedulesController::class;

Route::prefix('api')->group(function () use ($healthController, $schedulesController, $statsController, $workflowsController) {
    Route::get('/stats', [$statsController, 'index'])->name('waterline.stats.index');
    Route::get('/v2/health', [$healthController, 'show'])->name('waterline.v2.health');
    Route::get('/saved-views', [SavedViewsController::class, 'index'])->name('waterline.saved-views.index');
    Route::post('/saved-views', [SavedViewsController::class, 'store'])->name('waterline.saved-views.store');
    Route::get('/saved-views/{view}', [SavedViewsController::class, 'show'])->name('waterline.saved-views.show');
    Route::put('/saved-views/{view}', [SavedViewsController::class, 'update'])->name('waterline.saved-views.update');
    Route::delete('/saved-views/{view}', [SavedViewsController::class, 'destroy'])->name('waterline.saved-views.destroy');
    Route::get('/preferences/{surface}', [UserPreferencesController::class, 'show'])->name('waterline.preferences.show');
    Route::put('/preferences/{surface}', [UserPreferencesController::class, 'update'])->name('waterline.preferences.update');

    Route::get('/flows/completed', [$workflowsController, 'completed'])->name('waterline.completed');
    Route::get('/flows/failed', [$workflowsController, 'failed'])->name('waterline.failed');
    Route::get('/flows/cancelled', [$workflowsController, 'cancelled'])->name('waterline.cancelled');
    Route::get('/flows/terminated', [$workflowsController, 'terminated'])->name('waterline.terminated');
    Route::get('/flows/running', [$workflowsController, 'running'])->name('waterline.running');
    Route::get('/instances/{instanceId}', [$workflowsController, 'showSelection'])->name('waterline.instances.show');
    Route::get('/instances/{instanceId}/runs/{runId}', [$workflowsController, 'showSelection'])->name('waterline.instances.runs.show');
    Route::get('/instances/{instanceId}/history-export', [$workflowsController, 'historyExportInstance'])->name('waterline.instances.history-export');
    Route::get('/instances/{instanceId}/runs/{runId}/history-export', [$workflowsController, 'historyExportSelection'])->name('waterline.instances.runs.history-export');
    Route::get('/instances/{instanceId}/runs/{runId}/updates/{updateId}', [$workflowsController, 'showUpdateSelection'])->name('waterline.instances.runs.updates.show');
    Route::post('/instances/{instanceId}/runs/{runId}/queries/{query}', [$workflowsController, 'querySelection'])->name('waterline.instances.runs.query');
    Route::post('/instances/{instanceId}/runs/{runId}/signals/{signal}', [$workflowsController, 'signalSelection'])->name('waterline.instances.runs.signal');
    Route::post('/instances/{instanceId}/runs/{runId}/updates/{update}', [$workflowsController, 'updateSelection'])->name('waterline.instances.runs.update');
    Route::post('/instances/{instanceId}/runs/{runId}/repair', [$workflowsController, 'repairSelection'])->name('waterline.instances.runs.repair');
    Route::post('/instances/{instanceId}/runs/{runId}/cancel', [$workflowsController, 'cancelSelection'])->name('waterline.instances.runs.cancel');
    Route::post('/instances/{instanceId}/runs/{runId}/terminate', [$workflowsController, 'terminateSelection'])->name('waterline.instances.runs.terminate');
    Route::post('/instances/{instanceId}/runs/{runId}/archive', [$workflowsController, 'archiveSelection'])->name('waterline.instances.runs.archive');
    Route::get('/instances/{instanceId}/updates/{updateId}', [$workflowsController, 'showUpdateInstance'])->name('waterline.instances.updates.show');
    Route::post('/instances/{instanceId}/queries/{query}', [$workflowsController, 'queryInstance'])->name('waterline.instances.query');
    Route::post('/instances/{instanceId}/signals/{signal}', [$workflowsController, 'signalInstance'])->name('waterline.instances.signal');
    Route::post('/instances/{instanceId}/updates/{update}', [$workflowsController, 'updateInstance'])->name('waterline.instances.update');
    Route::post('/instances/{instanceId}/repair', [$workflowsController, 'repairInstance'])->name('waterline.instances.repair');
    Route::post('/instances/{instanceId}/cancel', [$workflowsController, 'cancelInstance'])->name('waterline.instances.cancel');
    Route::post('/instances/{instanceId}/terminate', [$workflowsController, 'terminateInstance'])->name('waterline.instances.terminate');
    Route::post('/instances/{instanceId}/archive', [$workflowsController, 'archiveInstance'])->name('waterline.instances.archive');
    if (BackendConfiguration::serviceMode()) {
        Route::any('/v2/services/{path?}', [RemoteCapabilityController::class, 'unavailable'])
            ->where('path', '.*')
            ->name('waterline.v2.services.unavailable');
    } else {
        Route::get('/v2/services/endpoints', [V2ServicesController::class, 'endpointsIndex'])->name('waterline.v2.services.endpoints.index');
        Route::get('/v2/services/endpoints/{endpointId}', [V2ServicesController::class, 'endpointShow'])->name('waterline.v2.services.endpoints.show');
        Route::get('/v2/services/services', [V2ServicesController::class, 'servicesIndex'])->name('waterline.v2.services.services.index');
        Route::get('/v2/services/services/{serviceId}', [V2ServicesController::class, 'serviceShow'])->name('waterline.v2.services.services.show');
        Route::get('/v2/services/operations', [V2ServicesController::class, 'operationsIndex'])->name('waterline.v2.services.operations.index');
        Route::get('/v2/services/operations/{operationId}', [V2ServicesController::class, 'operationShow'])->name('waterline.v2.services.operations.show');
        Route::get('/v2/services/calls', [V2ServicesController::class, 'callsIndex'])->name('waterline.v2.services.calls.index');
        Route::get('/v2/services/calls/{callId}', [V2ServicesController::class, 'callShow'])->name('waterline.v2.services.calls.show');
    }

    Route::get('/v2/schedules', [$schedulesController, 'index'])->name('waterline.v2.schedules.index');
    Route::get('/v2/schedules/{scheduleId}', [$schedulesController, 'show'])->name('waterline.v2.schedules.show');
    Route::get('/v2/schedules/{scheduleId}/history', [$schedulesController, 'history'])->name('waterline.v2.schedules.history');
    Route::post('/v2/schedules/{scheduleId}/pause', [$schedulesController, 'pause'])->name('waterline.v2.schedules.pause');
    Route::post('/v2/schedules/{scheduleId}/resume', [$schedulesController, 'resume'])->name('waterline.v2.schedules.resume');
    Route::post('/v2/schedules/{scheduleId}/trigger', [$schedulesController, 'trigger'])->name('waterline.v2.schedules.trigger');
    Route::post('/v2/schedules/{scheduleId}/backfill', [$schedulesController, 'backfill'])->name('waterline.v2.schedules.backfill');
    Route::delete('/v2/schedules/{scheduleId}', [$schedulesController, 'destroy'])->name('waterline.v2.schedules.destroy');

    Route::get('/flows/{id}', [$workflowsController, 'show'])->name('waterline.show');
    Route::get('/flows/{id}/history-export', [$workflowsController, 'historyExport'])->name('waterline.history-export');
    Route::get('/flows/{id}/updates/{updateId}', [$workflowsController, 'showUpdate'])->name('waterline.updates.show');
    Route::post('/flows/{id}/queries/{query}', [$workflowsController, 'query'])->name('waterline.query');
    Route::post('/flows/{id}/signals/{signal}', [$workflowsController, 'signal'])->name('waterline.signal');
    Route::post('/flows/{id}/updates/{update}', [$workflowsController, 'update'])->name('waterline.update');
    Route::post('/flows/{id}/repair', [$workflowsController, 'repair'])->name('waterline.repair');
    Route::post('/flows/{id}/cancel', [$workflowsController, 'cancel'])->name('waterline.cancel');
    Route::post('/flows/{id}/terminate', [$workflowsController, 'terminate'])->name('waterline.terminate');
    Route::post('/flows/{id}/archive', [$workflowsController, 'archive'])->name('waterline.archive');
});
