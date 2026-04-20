<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allow Remote Access
    |--------------------------------------------------------------------------
    |
    | Gail is designed to run on the operator's own machine. By default
    | every HTTP request is restricted to loopback addresses so that the
    | chat UI and its web tools cannot be reached from the network. Set
    | GAIL_ALLOW_REMOTE=true only if you understand the risk and have
    | placed the application behind your own authentication and transport
    | security.
    |
    */

    'allow_remote' => env('GAIL_ALLOW_REMOTE', false),

    /*
    |--------------------------------------------------------------------------
    | Tool Configuration
    |--------------------------------------------------------------------------
    |
    | Security and output limits for Gail's tool layer. Each tool resolves
    | its guard + byte limits from the container, which reads these values
    | once at boot. Operators can tighten the blocklists or lower the byte
    | caps without touching tool source files. Tests can also override any
    | of these by passing a custom guard to the tool constructor directly.
    |
    */

    'tools' => [

        'max_output_bytes' => [
            'web_fetch' => 30_000,
            'web_search' => 10_000,
            'wikipedia' => 15_000,
            'attachment_text' => 50_000,
        ],

        /*
         * Cloud metadata endpoints and other universally-blocked hosts. Every
         * tool merges this list into its own guard; extras go below.
         */
        'denied_hosts' => [
            '169.254.169.254',
            'metadata.google.internal',
        ],

        /*
         * WebFetch accepts arbitrary user-supplied URLs, so it additionally
         * blocks loopback addresses. Other tools only hit fixed APIs and do
         * not need this layer.
         */
        'web_fetch' => [
            'extra_denied_hosts' => [
                'localhost',
                '127.0.0.1',
            ],
        ],

    ],

];
