<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Waterline Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Waterline will be accessible from. If this
    | setting is null, Waterline will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('WATERLINE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Waterline Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Waterline will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('WATERLINE_PATH', 'waterline'),

    /*
    |--------------------------------------------------------------------------
    | Waterline Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Waterline route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Waterline API Route Middleware
    |--------------------------------------------------------------------------
    |
    | Leave this null to use the route middleware above for the JSON operator
    | API. Waterline inserts a package middleware before that stack so
    | database-backed Laravel sessions fall back to an ephemeral request session
    | when an observer host intentionally has no sessions table. Set an explicit
    | list when the API must run through a different host-specific guard.
    |
    */

    'api_middleware' => null,

    /*
    |--------------------------------------------------------------------------
    | Unauthenticated Access
    |--------------------------------------------------------------------------
    |
    | Waterline normally requires the host application's Waterline::auth gate.
    | Ephemeral observer stacks can opt in to unauthenticated dashboard/API
    | access by setting WATERLINE_ALLOW_UNAUTHENTICATED=true.
    |
    */

    'allow_unauthenticated' => env('WATERLINE_ALLOW_UNAUTHENTICATED', false),

    /*
    |--------------------------------------------------------------------------
    | Backend Mode
    |--------------------------------------------------------------------------
    |
    | Embedded mode uses the optional durable-workflow/workflow integration in
    | the host Laravel application. Service mode uses only the published PHP SDK
    | and standalone server HTTP contracts; it never opens the server database.
    |
    */

    'backend' => env('WATERLINE_BACKEND', 'embedded'),

    'service' => [
        'endpoint' => env('WATERLINE_SERVER_ENDPOINT'),
        'token' => env('WATERLINE_SERVER_TOKEN'),
        'namespace' => env('WATERLINE_NAMESPACE', 'default'),
        'access_mode' => env('WATERLINE_ACCESS_MODE', 'read_only'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow Engine Source
    |--------------------------------------------------------------------------
    |
    | Waterline can read the legacy v1 workflow tables or the v2 operator
    | bridge. The default "auto" mode prefers v2 once the workflow package's
    | full v2 operator surface is installed; otherwise it falls back to v1.
    | Set this to "v1" or "v2" to pin the behavior explicitly.
    |
    */

    'engine_source' => env('WATERLINE_ENGINE_SOURCE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Finish-on-v1 Migration View
    |--------------------------------------------------------------------------
    |
    | While a v2 deployment drains workflows that remain on the v1
    | compatibility engine, Waterline can merge the preserved v1 operator
    | rows into its normal workflow lists. Legacy IDs are qualified as
    | "v1:<id>" so they cannot collide with v2 run IDs. Namespace-scoped v2
    | views do not merge v1 rows because the v1 schema has no namespace key.
    |
    */

    'hybrid_migration_view' => env('WATERLINE_HYBRID_MIGRATION_VIEW', true),

    /*
    |--------------------------------------------------------------------------
    | Workflow Namespace
    |--------------------------------------------------------------------------
    |
    | When set, Waterline restricts all v2 workflow visibility and operations
    | to the specified namespace. Service mode sends this scope through the PHP
    | SDK on every server request; embedded mode applies it to local storage.
    | When null, embedded Waterline runs in cluster-wide operator scope and can observe
    | every namespace in the shared store; expose that mode only behind an
    | authorization boundary intended for fleet administrators.
    |
    */

    'namespace' => env('WATERLINE_NAMESPACE'),

    /*
    |--------------------------------------------------------------------------
    | Worker Registration Freshness
    |--------------------------------------------------------------------------
    |
    | Keep the embedded Workers operator surface on the same stale-registration
    | window as its runtime. Service mode reads server-classified worker status
    | through the PHP SDK and ignores this local projection setting.
    |
    */

    'worker_stale_after_seconds' => env('WATERLINE_WORKER_STALE_AFTER_SECONDS'),

    /*
    |--------------------------------------------------------------------------
    | Health Snapshot Task Dispatch Mode
    |--------------------------------------------------------------------------
    |
    | An embedded Waterline host can be deployed as a read-only observer over
    | application-owned workflow storage. In that topology the Waterline host
    | process does not dispatch workflow tasks itself, so its local Laravel
    | queue driver should not make the observer health endpoint fail. The
    | default poll mode keeps backend readiness focused on database visibility.
    | Set this to "queue" when using Waterline health as an embedded execution
    | node readiness check.
    |
    */

    'health' => [
        'task_dispatch_mode' => env('WATERLINE_HEALTH_TASK_DISPATCH_MODE', 'poll'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Banner
    |--------------------------------------------------------------------------
    |
    | Waterline can show a thin environment strip above the dashboard chrome so
    | operators can distinguish local, staging, and production tabs before
    | issuing manual commands. Recognized palette colors are blue (#2563eb or
    | #0d6efd), green (#198754 or #28a745), orange (#fd7e14), purple (#6f42c1
    | or #7746ec), red (#dc3545), and yellow (#ffc107). Other values use the
    | neutral banner so package markup never introduces a static inline style.
    | Runtime charting and positioning still require the host's route-scoped
    | CSP allowances for generated style elements and attributes.
    |
    */

    'env_name' => env('WATERLINE_ENV_NAME'),
    'env_color' => env('WATERLINE_ENV_COLOR', '#6c757d'),

    /*
    |--------------------------------------------------------------------------
    | Saved Workflow Views
    |--------------------------------------------------------------------------
    |
    | Waterline v2 saved views persist repeatable operator filters over the
    | workflow run-summary visibility contract. The default scope follows
    | WATERLINE_NAMESPACE when configured so tenant-specific filters and search
    | attribute values are not shared across namespaces. Set an explicit scope
    | only for an intentionally shared operator authority.
    |
    */

    'saved_views' => [
        'enabled' => env('WATERLINE_SAVED_VIEWS_ENABLED', true),
        'scope' => env('WATERLINE_SAVED_VIEW_SCOPE', 'default'),
        'model' => \Waterline\Models\SavedWorkflowView::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator Preferences
    |--------------------------------------------------------------------------
    |
    | Waterline persists small operator view preferences server-side so the
    | workflow list, run detail, schedules, and workers views can follow an
    | authenticated operator across workstations. The default scope follows the
    | saved-view scope, including namespace partitioning. URL parameters remain
    | the final override when a link needs deterministic shared state.
    |
    */

    'preferences' => [
        'scope' => env('WATERLINE_PREFERENCES_SCOPE', env('WATERLINE_SAVED_VIEW_SCOPE', 'default')),
        'model' => \Waterline\Models\UserPreference::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Run Diagnostics
    |--------------------------------------------------------------------------
    |
    | The v2 run detail screen derives a compact operator diagnostic banner from
    | the selected run's failure rows, task rows, wait projections, and history
    | budget. The thresholds below keep those rules tunable without changing the
    | workflow package's operator-observability payload contract.
    |
    */

    'run_diagnostics' => [
        'activity_failure_repeat_threshold' => env('WATERLINE_RUN_DIAGNOSTICS_ACTIVITY_FAILURE_REPEAT_THRESHOLD', 3),
        'workflow_task_failure_attempt_threshold' => env('WATERLINE_RUN_DIAGNOSTICS_WORKFLOW_TASK_FAILURE_ATTEMPT_THRESHOLD', 3),
        'history_budget_warning_ratio' => env('WATERLINE_RUN_DIAGNOSTICS_HISTORY_BUDGET_WARNING_RATIO', 0.8),
        'condition_wait_sla_seconds' => env('WATERLINE_RUN_DIAGNOSTICS_CONDITION_WAIT_SLA_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Namespace Capacity Evidence
    |--------------------------------------------------------------------------
    |
    | This diagnostic surface exposes bounded, namespace-scoped runtime
    | evidence for an external capacity adviser. It is deliberately separate
    | from billing and infrastructure telemetry. Applications may add their
    | current plan identity and measured envelope through runtime config; no
    | default throughput or latency limit is invented by Waterline. Latency
    | samples above the configured limit use deterministic midpoint ranks over
    | the complete timestamp-and-primary-key ordered window population.
    |
    */

    'capacity_evidence' => [
        'default_window_seconds' => 3600,
        'allowed_window_seconds' => [60, 300, 900, 3600, 21600, 86400],
        'latency_sample_limit' => 10000,
        'percentile_min_samples' => [
            'p50' => 1,
            'p95' => 20,
            'p99' => 100,
        ],
        'tenant' => env('WATERLINE_CAPACITY_EVIDENCE_TENANT'),
        'plan' => [
            'version' => env('WATERLINE_CAPACITY_PLAN_VERSION'),
            'limits' => [],
        ],
        'recommendation_policy' => [
            'sustained_windows' => 3,
            'upgrade_utilization_ratio' => 0.8,
            'downgrade_utilization_ratio' => 0.5,
            'cooldown_seconds' => 86400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow Sort Column
    |--------------------------------------------------------------------------
    |
    | Waterline sorts legacy v1 workflow lists in descending order. The v2
    | bridge ignores this setting and uses the durable run-summary sort
    | contract (`sort_timestamp` + `sort_key`) instead of raw column guesses.
    |
    */

    'workflow_sort_column' => env('WATERLINE_WORKFLOW_SORT_COLUMN', 'id'),
];
