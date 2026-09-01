<?php

use App\Support\OneployHosts;

if (! function_exists('oneploy_url')) {
    /**
     * Link to the marketing site, client portal or billing portal.
     *
     * @param  'marketing'|'client'|'billing'  $portal
     */
    function oneploy_url(string $portal, string $path = '/'): string
    {
        return OneployHosts::url($portal, $path);
    }
}
