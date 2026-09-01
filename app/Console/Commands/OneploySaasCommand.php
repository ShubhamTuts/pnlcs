<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Oneploy.dev control-plane bootstrap. Reuses the Coolify catalog and
 * connector; brands as Oneploy instead of Webkahost.
 */
class OneploySaasCommand extends Command
{
    protected $signature = 'oneploy:saas
        {--brand : Apply Oneploy white-label}
        {--catalog : Seed Apps / Databases / One-click products}
        {--force : Recreate catalog products that already exist}
        {--connect : Register a Coolify server from --url/--token}
        {--url= : Coolify dashboard URL}
        {--token= : Coolify API token}
        {--server-uuid= : Destination server UUID (optional)}
        {--port=8000 : Coolify port when --url has no scheme}
        {--dry-run : Print the plan and do not write}';

    protected $description = 'Brand Oneploy, connect Coolify, and seed the SaaS catalog';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'Oneploy SaaS (dry-run)' : 'Oneploy SaaS');

        if ($this->option('brand')) {
            if ($dry) {
                $this->line('Would run: php artisan oneploy:brand');
            } else {
                $this->call('oneploy:brand');
            }
        }

        $passthrough = [
            '--catalog' => $this->option('catalog'),
            '--force' => $this->option('force'),
            '--connect' => $this->option('connect'),
            '--url' => $this->option('url'),
            '--token' => $this->option('token'),
            '--server-uuid' => $this->option('server-uuid'),
            '--port' => $this->option('port'),
            '--dry-run' => $dry,
        ];

        $code = $this->call('webkahost:saas', $passthrough);

        $this->newLine();
        $this->line('Oneploy hosts:');
        $this->line('  oneploy.dev              marketing');
        $this->line('  client.oneploy.dev       portal (services, domains, Coolify, Agent)');
        $this->line('  billing.oneploy.dev      invoices, AI credits, payments');
        $this->line('Set ONEPLOY_MARKETING_HOST / ONEPLOY_CLIENT_HOST / ONEPLOY_BILLING_HOST.');

        return $code;
    }
}
