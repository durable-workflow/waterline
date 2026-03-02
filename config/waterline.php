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
    | Workflow Sort Column
    |--------------------------------------------------------------------------
    |
    | Waterline sorts workflow lists in descending order. By default this uses
    | the workflow ID, but you can point it at another sortable column such as
    | created_at when your workflow IDs are not naturally ordered.
    |
    */

    'workflow_sort_column' => env('WATERLINE_WORKFLOW_SORT_COLUMN', 'id'),
];
