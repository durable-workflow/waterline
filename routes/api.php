<?php

use Illuminate\Support\Facades\Route;
use Waterline\Http\Controllers\CapacityEvidenceController;
use Waterline\Http\Controllers\DashboardStatsController;
use Waterline\Http\Controllers\SavedViewsController;
use Waterline\Http\Controllers\UserPreferencesController;
use Waterline\Http\Controllers\V2HealthController;
use Waterline\Http\Controllers\V2SchedulesController;
use Waterline\Http\Controllers\V2ServicesController;
use Waterline\Http\Controllers\WorkflowsController;
use Waterline\Http\Controllers\Remote\RemoteCapacityEvidenceController;
use Waterline\Http\Controllers\Remote\RemoteHealthController;
use Waterline\Http\Controllers\Remote\RemoteSchedulesController;
use Waterline\Http\Controllers\Remote\RemoteServicesController;
use Waterline\Http\Controllers\Remote\RemoteStatsController;
use Waterline\Http\Controllers\Remote\RemoteWorkflowsController;
use Waterline\Http\Controllers\Remote\RemoteActivityResultsController;
use Waterline\Support\BackendConfiguration;
use Waterline\Http\Middleware\AuthorizeOperatorAction;

$workflowsController = BackendConfiguration::serviceMode() ? RemoteWorkflowsController::class : WorkflowsController::class;
$statsController = BackendConfiguration::serviceMode() ? RemoteStatsController::class : DashboardStatsController::class;
$capacityEvidenceController = BackendConfiguration::serviceMode()
    ? RemoteCapacityEvidenceController::class
    : CapacityEvidenceController::class;
$healthController = BackendConfiguration::serviceMode() ? RemoteHealthController::class : V2HealthController::class;
$schedulesController = BackendConfiguration::serviceMode() ? RemoteSchedulesController::class : V2SchedulesController::class;
$servicesController = BackendConfiguration::serviceMode() ? RemoteServicesController::class : V2ServicesController::class;

Route::prefix('api')->group(function () use ($capacityEvidenceController, $healthController, $schedulesController, $servicesController, $statsController, $workflowsController) {
    Route::get('/stats', [$statsController, 'index'])->name('waterline.stats.index');
    Route::get('/v2/capacity-evidence', [$capacityEvidenceController, 'show'])->name('waterline.v2.capacity-evidence.show');
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
    if ($workflowsController === RemoteWorkflowsController::class) {
        Route::get('/instances/{instanceId}/runs/{runId}/activities/{activityId}/result', [RemoteActivityResultsController::class, 'show'])
            ->name('waterline.instances.runs.activities.result');
    }
    Route::get('/instances/{instanceId}/runs/{runId}/updates/{updateId}', [$workflowsController, 'showUpdateSelection'])->name('waterline.instances.runs.updates.show');
    Route::post('/instances/{instanceId}/runs/{runId}/queries/{query}', [$workflowsController, 'querySelection'])->name('waterline.instances.runs.query');
    Route::post('/instances/{instanceId}/runs/{runId}/signals/{signal}', [$workflowsController, 'signalSelection'])->name('waterline.instances.runs.signal')->middleware(AuthorizeOperatorAction::SIGNAL);
    Route::post('/instances/{instanceId}/runs/{runId}/updates/{update}', [$workflowsController, 'updateSelection'])->name('waterline.instances.runs.update')->middleware(AuthorizeOperatorAction::UPDATE);
    Route::post('/instances/{instanceId}/runs/{runId}/repair', [$workflowsController, 'repairSelection'])->name('waterline.instances.runs.repair')->middleware(AuthorizeOperatorAction::REPAIR);
    Route::post('/instances/{instanceId}/runs/{runId}/cancel', [$workflowsController, 'cancelSelection'])->name('waterline.instances.runs.cancel')->middleware(AuthorizeOperatorAction::CANCEL);
    Route::post('/instances/{instanceId}/runs/{runId}/terminate', [$workflowsController, 'terminateSelection'])->name('waterline.instances.runs.terminate')->middleware(AuthorizeOperatorAction::TERMINATE);
    Route::post('/instances/{instanceId}/runs/{runId}/archive', [$workflowsController, 'archiveSelection'])->name('waterline.instances.runs.archive')->middleware(AuthorizeOperatorAction::ARCHIVE);
    Route::get('/instances/{instanceId}/updates/{updateId}', [$workflowsController, 'showUpdateInstance'])->name('waterline.instances.updates.show');
    Route::post('/instances/{instanceId}/queries/{query}', [$workflowsController, 'queryInstance'])->name('waterline.instances.query');
    Route::post('/instances/{instanceId}/signals/{signal}', [$workflowsController, 'signalInstance'])->name('waterline.instances.signal')->middleware(AuthorizeOperatorAction::SIGNAL);
    Route::post('/instances/{instanceId}/updates/{update}', [$workflowsController, 'updateInstance'])->name('waterline.instances.update')->middleware(AuthorizeOperatorAction::UPDATE);
    Route::post('/instances/{instanceId}/repair', [$workflowsController, 'repairInstance'])->name('waterline.instances.repair')->middleware(AuthorizeOperatorAction::REPAIR);
    Route::post('/instances/{instanceId}/cancel', [$workflowsController, 'cancelInstance'])->name('waterline.instances.cancel')->middleware(AuthorizeOperatorAction::CANCEL);
    Route::post('/instances/{instanceId}/terminate', [$workflowsController, 'terminateInstance'])->name('waterline.instances.terminate')->middleware(AuthorizeOperatorAction::TERMINATE);
    Route::post('/instances/{instanceId}/archive', [$workflowsController, 'archiveInstance'])->name('waterline.instances.archive')->middleware(AuthorizeOperatorAction::ARCHIVE);
    Route::get('/v2/services/endpoints', [$servicesController, 'endpointsIndex'])->name('waterline.v2.services.endpoints.index');
    Route::get('/v2/services/endpoints/{endpointId}', [$servicesController, 'endpointShow'])->name('waterline.v2.services.endpoints.show');
    Route::get('/v2/services/services', [$servicesController, 'servicesIndex'])->name('waterline.v2.services.services.index');
    Route::get('/v2/services/services/{serviceId}', [$servicesController, 'serviceShow'])->name('waterline.v2.services.services.show');
    Route::get('/v2/services/operations', [$servicesController, 'operationsIndex'])->name('waterline.v2.services.operations.index');
    Route::get('/v2/services/operations/{operationId}', [$servicesController, 'operationShow'])->name('waterline.v2.services.operations.show');
    Route::get('/v2/services/calls', [$servicesController, 'callsIndex'])->name('waterline.v2.services.calls.index');
    Route::get('/v2/services/calls/{callId}', [$servicesController, 'callShow'])->name('waterline.v2.services.calls.show');

    // Schedule IDs may contain slashes, so the ID matches across segments and
    // the history route is registered before the detail route.
    Route::get('/v2/schedules', [$schedulesController, 'index'])->name('waterline.v2.schedules.index');
    Route::get('/v2/schedules/{scheduleId}/history', [$schedulesController, 'history'])->where('scheduleId', '.+')->name('waterline.v2.schedules.history');
    Route::get('/v2/schedules/{scheduleId}', [$schedulesController, 'show'])->where('scheduleId', '.+')->name('waterline.v2.schedules.show');
    Route::post('/v2/schedules/{scheduleId}/pause', [$schedulesController, 'pause'])->where('scheduleId', '.+')->name('waterline.v2.schedules.pause')->middleware(AuthorizeOperatorAction::SCHEDULES);
    Route::post('/v2/schedules/{scheduleId}/resume', [$schedulesController, 'resume'])->where('scheduleId', '.+')->name('waterline.v2.schedules.resume')->middleware(AuthorizeOperatorAction::SCHEDULES);
    Route::post('/v2/schedules/{scheduleId}/trigger', [$schedulesController, 'trigger'])->where('scheduleId', '.+')->name('waterline.v2.schedules.trigger')->middleware(AuthorizeOperatorAction::SCHEDULES);
    Route::post('/v2/schedules/{scheduleId}/backfill', [$schedulesController, 'backfill'])->where('scheduleId', '.+')->name('waterline.v2.schedules.backfill')->middleware(AuthorizeOperatorAction::SCHEDULES);
    Route::delete('/v2/schedules/{scheduleId}', [$schedulesController, 'destroy'])->where('scheduleId', '.+')->name('waterline.v2.schedules.destroy')->middleware(AuthorizeOperatorAction::SCHEDULES);

    Route::get('/flows/{id}', [$workflowsController, 'show'])->name('waterline.show');
    Route::get('/flows/{id}/history-export', [$workflowsController, 'historyExport'])->name('waterline.history-export');
    Route::get('/flows/{id}/updates/{updateId}', [$workflowsController, 'showUpdate'])->name('waterline.updates.show');
    Route::post('/flows/{id}/queries/{query}', [$workflowsController, 'query'])->name('waterline.query');
    Route::post('/flows/{id}/signals/{signal}', [$workflowsController, 'signal'])->name('waterline.signal')->middleware(AuthorizeOperatorAction::SIGNAL);
    Route::post('/flows/{id}/updates/{update}', [$workflowsController, 'update'])->name('waterline.update')->middleware(AuthorizeOperatorAction::UPDATE);
    Route::post('/flows/{id}/repair', [$workflowsController, 'repair'])->name('waterline.repair')->middleware(AuthorizeOperatorAction::REPAIR);
    Route::post('/flows/{id}/cancel', [$workflowsController, 'cancel'])->name('waterline.cancel')->middleware(AuthorizeOperatorAction::CANCEL);
    Route::post('/flows/{id}/terminate', [$workflowsController, 'terminate'])->name('waterline.terminate')->middleware(AuthorizeOperatorAction::TERMINATE);
    Route::post('/flows/{id}/archive', [$workflowsController, 'archive'])->name('waterline.archive')->middleware(AuthorizeOperatorAction::ARCHIVE);
});
