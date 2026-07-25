<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When false the SMS channel is skipped entirely — mail notifications still
    | go out. Leave this off until live gateway credentials exist.
    |
    */

    'enabled' => filter_var(env('SMS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Gateway
    |--------------------------------------------------------------------------
    |
    | Semaphore is the default Philippine provider. `endpoint` and `sender_name`
    | are provider specific; the sender name must be pre-registered with them.
    |
    */

    'driver' => env('SMS_DRIVER', 'semaphore'),

    'endpoint' => env('SMS_ENDPOINT', 'https://api.semaphore.co/api/v4/messages'),

    'api_key' => env('SMS_API_KEY'),

    'sender_name' => env('SMS_SENDER_NAME', 'AfterHours'),

];
