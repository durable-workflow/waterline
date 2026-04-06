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
    | Workflow Engine Source
    |--------------------------------------------------------------------------
    |
    | Waterline can read the legacy v1 workflow tables or the v2 run-summary
    | bridge. The default keeps today's v1 behavior until an application opts
    | into the v2 compatibility layer explicitly.
    |
    */

    'engine_source' => env('WATERLINE_ENGINE_SOURCE', 'v1'),

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
