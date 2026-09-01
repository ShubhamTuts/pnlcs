<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Webkahost\SaasCatalog;
use Illuminate\Console\Command;
use Modules\Servers\Coolify\CoolifyModule;

/**
 * Turn this PNLCS install into a Webkahost SaaS control plane.
 *
 * Does not install Docker or Coolify — the VPS script does that. This command
 * brands the portal, registers the Coolify API, seeds the product catalog,
 * and prints the SSL / DNS checklist.
 */
class WebkahostSaasCommand extends Command
{
    protected $signature = 'webkahost:saas
        {--brand : Apply Webkahost white-label}
        {--catalog : Seed Apps / Databases / One-click products}
        {--force : Recreate catalog products that already exist}
        {--connect : Register a Coolify server from --url/--token}
        {--url= : Coolify dashboard URL (https://coolify.example.com or host:8000)}
        {--token= : Coolify API token}
        {--server-uuid= : Destination server UUID (optional)}
        {--port=8000 : Coolify port when --url has no scheme}
        {--dry-run : Print the plan and do not write}';

    protected $description = 'Brand, connect Coolify, and seed the Webkahost SaaS catalog';

    public function handle(SaasCatalog $catalog): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'Webkahost SaaS (dry-run)' : 'Webkahost SaaS');

        if ($this->option('brand')) {
            if ($dry) {
                $this->line('Would run: php artisan webkahost:brand');
            } else {
                $this->call('webkahost:brand');
            }
        }

        if ($this->option('connect')) {
            $ok = $this->connectCoolify($dry);
            if (! $ok) {
                return self::FAILURE;
            }
        }

        if ($this->option('catalog')) {
            if ($dry) {
                $count = 0;
                foreach ($catalog->blueprint() as $group) {
                    $count += count($group['products']);
                }
                $this->line("Would seed {$count} Coolify products in 3 groups.");
            } else {
                $result = $catalog->seed((bool) $this->option('force'));
                $this->info("Catalog: {$result['groups']} groups, {$result['products']} products written.");
            }
        }

        $this->newLine();
        $this->line('Next (SSL and DNS):');
        $this->line('  1. Point billing.YOURDOMAIN A/AAAA at this VPS');
        $this->line('  2. Point *.YOURDOMAIN.app at the Coolify destination (Traefik)');
        $this->line('  3. APP_URL=https://billing.YOURDOMAIN  (Caddy/nginx already has TLS)');
        $this->line('  4. Test Connection on the Coolify server in Configuration → Servers');
        $this->line('  5. Optional: WEBKAHOST_AI_UPSTREAM_URL + _KEY, or let customers use BYOK');
        $this->line('Installer: sudo bash scripts/install-webkahost-saas.sh');

        return self::SUCCESS;
    }

    private function connectCoolify(bool $dry): bool
    {
        $url = trim((string) ($this->option('url') ?: env('COOLIFY_URL', '')));
        $token = trim((string) ($this->option('token') ?: env('COOLIFY_TOKEN', '')));
        $uuid = trim((string) ($this->option('server-uuid') ?: env('COOLIFY_SERVER_UUID', '')));
        $port = (int) $this->option('port');

        if ($url === '' || $token === '') {
            $this->error('Coolify --url and --token are required (or COOLIFY_URL / COOLIFY_TOKEN).');

            return false;
        }

        [$host, $detectedPort] = $this->parseCoolifyUrl($url, $port);
        $port = $detectedPort;

        if ($dry) {
            $this->line("Would register Coolify server {$host}:{$port}".($uuid !== '' ? " uuid={$uuid}" : ''));

            return true;
        }

        $server = Server::query()->where('type', 'coolify')->where('hostname', $host)->first()
            ?? new Server;
        $server->fill([
            'name' => 'Webkahost Coolify',
            'hostname' => $host,
            'ip_address' => filter_var($host, FILTER_VALIDATE_IP) ? $host : ($server->ip_address ?: '127.0.0.1'),
            'type' => 'coolify',
            'port' => $port,
            'access_hash' => $token,
            'username' => $uuid,
            'active' => true,
            'disabled' => false,
            'max_accounts' => $server->max_accounts ?: 200,
        ]);
        $server->save();

        $ok = (new CoolifyModule)->testConnection($server);
        if (! $ok) {
            $this->warn("Coolify server #{$server->id} saved, but /api/v1/version did not answer. Check token, TLS and firewall.");

            return true;
        }

        $this->info("Coolify connected as server #{$server->id} ({$host}:{$port}).");

        return true;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseCoolifyUrl(string $url, int $fallbackPort): array
    {
        if (! str_contains($url, '://')) {
            if (str_contains($url, ':')) {
                [$host, $port] = explode(':', $url, 2);

                return [$host, (int) $port ?: $fallbackPort];
            }

            return [$url, $fallbackPort];
        }

        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 0);
        if ($port === 0) {
            $port = (($parts['scheme'] ?? 'https') === 'http') ? 80 : 443;
        }

        return [$host, $port];
    }
}
