<?php

return [

    /*
    | Split the public site, client portal and billing portal across hosts.
    | Leave empty for a single-host install (local, tests, one-box demos).
    |
    | Production defaults used by the installer:
    |   ONEPLOY_MARKETING_HOST=oneploy.dev
    |   ONEPLOY_CLIENT_HOST=client.oneploy.dev
    |   ONEPLOY_BILLING_HOST=billing.oneploy.dev
    */

    'marketing_host' => env('ONEPLOY_MARKETING_HOST', ''),
    'client_host' => env('ONEPLOY_CLIENT_HOST', ''),
    'billing_host' => env('ONEPLOY_BILLING_HOST', ''),

];
