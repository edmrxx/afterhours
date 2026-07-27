<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            // Overridable for shared hosting where the app root and the web
            // root are separate sibling folders (no `storage:link` symlink
            // support) — point this straight at the live public/storage
            // folder so uploads land somewhere Apache actually serves.
            'root' => env('PUBLIC_STORAGE_PATH', storage_path('app/public')),
            // Root-relative on purpose. This used to be env('APP_URL').'/storage',
            // which silently breaks every uploaded image the moment APP_URL and
            // the address actually being browsed disagree — running
            // `artisan serve` on a different port than APP_URL names is enough,
            // and the only symptom is a broken image with no error anywhere.
            //
            // Every consumer of this URL is a web page rendering an <img> on the
            // same host (payment QRs, court photos, avatars, the company logo),
            // so a relative path resolves correctly whatever host, port or
            // scheme the page was served from. Set PUBLIC_STORAGE_URL to an
            // absolute base only if these ever move to a separate CDN host.
            'url' => env('PUBLIC_STORAGE_URL', '/storage'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
