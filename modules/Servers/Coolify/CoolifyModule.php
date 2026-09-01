<?php

namespace Modules\Servers\Coolify;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Servers\AbstractServerModule;

/**
 * Provision Git apps and one-click services on a Coolify instance.
 *
 * Coolify is the PaaS (Git, Nixpacks, Traefik, Let's Encrypt, WordPress
 * templates). PNLCS remains the billing and customer portal. A Webkahost
 * product of type coolify is sold here and created there.
 *
 * Credentials on the server record:
 *   hostname     Coolify dashboard host
 *   port         8000 by default (443 if Coolify sits behind TLS)
 *   access_hash  Coolify API token (Bearer)
 *   username     optional destination server UUID; the first Coolify server
 *                is used when this is empty
 */
class CoolifyModule extends AbstractServerModule
{
    public function getModuleName(): string
    {
        return 'coolify';
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'api_token', 'label' => 'Coolify API Token', 'type' => 'password', 'required' => true],
        ];
    }

    /**
     * Deploy kinds a product can sell. These are Coolify capabilities, not
     * remote plans, so the product form can offer them without a live call.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function listPackages(Server $server): array
    {
        return [
            ['id' => 'wordpress', 'name' => 'WordPress (one-click)'],
            ['id' => 'nodejs', 'name' => 'Node.js (Git + Nixpacks)'],
            ['id' => 'nextjs', 'name' => 'Next.js (Git)'],
            ['id' => 'static', 'name' => 'Static site (Git)'],
            ['id' => 'git', 'name' => 'Any Git repository'],
        ];
    }

    /**
     * @return list<string>
     */
    public function hostingFeatures(Service $service): array
    {
        $data = $this->getModuleData($service);

        return ($data['coolify_uuid'] ?? '') !== '' ? ['coolify'] : [];
    }

    public function create(Service $service): array
    {
        $server = $this->getServer($service);
        if (! $server) {
            return $this->buildResult(false, 'No Coolify server configured.');
        }

        $kind = $this->deployKind($service);
        $destination = $this->destinationServerUuid($server);
        if ($destination === '') {
            return $this->buildResult(false, 'Coolify has no destination server. Add one in Coolify, or paste its UUID as the server username.');
        }

        $projectUuid = $this->ensureProject($server, $service);
        if ($projectUuid === null) {
            return $this->buildResult(false, 'Could not create a Coolify project for this customer.');
        }

        $result = $kind === 'wordpress'
            ? $this->createWordpress($server, $service, $projectUuid, $destination)
            : $this->createGitApplication($server, $service, $projectUuid, $destination, $kind);

        if (! ($result['success'] ?? false)) {
            $this->logAction($service, 'create', $result);

            return $result;
        }

        $uuid = (string) ($result['data']['uuid'] ?? '');
        $this->setModuleData($service, [
            'coolify_uuid' => $uuid,
            'coolify_resource' => $kind === 'wordpress' ? 'service' : 'application',
            'coolify_kind' => $kind,
            'coolify_project_uuid' => $projectUuid,
            'coolify_server_uuid' => $destination,
            'coolify_git_repository' => $result['data']['git_repository'] ?? $this->gitRepository($service),
            'coolify_git_branch' => $result['data']['git_branch'] ?? $this->gitBranch($service),
            'coolify_fqdn' => $result['data']['fqdn'] ?? $this->publicUrl($service),
        ]);

        $service->update([
            'status' => 'active',
            'username' => $uuid !== '' ? $uuid : $service->username,
        ]);

        $out = $this->buildResult(true, $kind === 'wordpress' ? 'WordPress deployed on Coolify.' : 'Application created on Coolify.', $result['data']);
        $this->logAction($service, 'create', $out);

        return $out;
    }

    public function suspend(Service $service, string $reason = ''): array
    {
        return $this->lifecycle($service, 'stop', 'suspended', [
            'suspension_date' => now(),
            'suspension_reason' => $reason,
        ], 'Coolify resource stopped (suspended).');
    }

    public function unsuspend(Service $service): array
    {
        return $this->lifecycle($service, 'start', 'active', [
            'suspension_date' => null,
            'suspension_reason' => null,
        ], 'Coolify resource started (unsuspended).');
    }

    public function terminate(Service $service): array
    {
        $server = $this->getServer($service);
        if (! $server) {
            return $this->buildResult(false, 'No Coolify server configured.');
        }

        $uuid = $this->resourceUuid($service);
        if ($uuid === '') {
            return $this->buildResult(false, 'Coolify resource UUID not found.');
        }

        $result = $this->api($server, 'DELETE', $this->resourcePath($service, $uuid));
        if (! ($result['success'] ?? false)) {
            return $this->buildResult(false, "Destroy failed: {$result['message']}");
        }

        $service->update(['status' => 'terminated', 'termination_date' => now()]);
        $out = $this->buildResult(true, 'Coolify resource destroyed.');
        $this->logAction($service, 'terminate', $out);

        return $out;
    }

    public function changePassword(Service $service, string $newPassword): array
    {
        return $this->buildResult(false, 'Password changes for Coolify apps are done inside the application, not through the Coolify API.');
    }

    public function changePackage(Service $service, array $newPackage): array
    {
        return $this->buildResult(false, 'Coolify plans are the destination server, not a resize API. Change the product\'s Git/build settings and redeploy.');
    }

    public function usageUpdate(Server $server): array
    {
        $services = Service::where('server_id', $server->id)->where('status', 'active')->get();
        $updated = 0;
        $errors = 0;

        foreach ($services as $service) {
            $uuid = $this->resourceUuid($service);
            if ($uuid === '') {
                $errors++;

                continue;
            }

            $result = $this->api($server, 'GET', $this->resourcePath($service, $uuid));
            if (! ($result['success'] ?? false)) {
                $errors++;

                continue;
            }

            $raw = $result['raw'] ?? [];
            $this->setModuleData($service, [
                'coolify_status' => $raw['status'] ?? $raw['fqdn'] ?? null,
                'coolify_fqdn' => $raw['fqdn'] ?? ($this->getModuleData($service)['coolify_fqdn'] ?? null),
            ]);
            $updated++;
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    public function testConnection(Server $server): bool
    {
        $result = $this->api($server, 'GET', 'version');

        if ($result['success'] ?? false) {
            return true;
        }

        // Older Coolify builds answer /servers and not /version.
        return (bool) ($this->api($server, 'GET', 'servers')['success'] ?? false);
    }

    /**
     * Redeploy the Git app after a push, or start a stopped one-click service.
     */
    public function redeploy(Service $service): array
    {
        $server = $this->getServer($service);
        if (! $server) {
            return $this->buildResult(false, 'No Coolify server configured.');
        }

        $uuid = $this->resourceUuid($service);
        if ($uuid === '') {
            return $this->buildResult(false, 'Coolify resource UUID not found.');
        }

        $path = $this->resourceType($service) === 'service'
            ? "services/{$uuid}/restart"
            : "deploy?uuid={$uuid}";

        $method = $this->resourceType($service) === 'service' ? 'GET' : 'GET';
        $result = $this->api($server, $method, $path);
        if (! ($result['success'] ?? false) && $this->resourceType($service) === 'application') {
            $result = $this->api($server, 'POST', "applications/{$uuid}/restart");
        }

        if (! ($result['success'] ?? false)) {
            return $this->buildResult(false, "Redeploy failed: {$result['message']}");
        }

        $out = $this->buildResult(true, 'Redeploy requested.', $result['raw'] ?? []);
        $this->logAction($service, 'redeploy', $out);

        return $out;
    }

    /**
     * Point the application at a different Git repository and trigger a deploy.
     */
    public function updateGitSource(Service $service, string $repository, string $branch = 'main'): array
    {
        $server = $this->getServer($service);
        if (! $server) {
            return $this->buildResult(false, 'No Coolify server configured.');
        }

        $uuid = $this->resourceUuid($service);
        if ($uuid === '' || $this->resourceType($service) !== 'application') {
            return $this->buildResult(false, 'Only Git applications can change their repository.');
        }

        $repository = trim($repository);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';

        if (! $this->isPublicGitUrl($repository)) {
            return $this->buildResult(false, 'Git URL must be an https:// GitHub, GitLab, Bitbucket or Gitea repository.');
        }

        $result = $this->api($server, 'PATCH', "applications/{$uuid}", [
            'git_repository' => $repository,
            'git_branch' => $branch,
        ]);

        if (! ($result['success'] ?? false)) {
            return $this->buildResult(false, "Git source update failed: {$result['message']}");
        }

        $this->setModuleData($service, [
            'coolify_git_repository' => $repository,
            'coolify_git_branch' => $branch,
        ]);

        $this->redeploy($service);

        return $this->buildResult(true, 'Git source updated.', [
            'git_repository' => $repository,
            'git_branch' => $branch,
        ]);
    }

    /**
     * Snapshot the customer can read without another Coolify round-trip.
     *
     * @return array<string, mixed>
     */
    public function deploymentSummary(Service $service): array
    {
        $data = $this->getModuleData($service);

        return [
            'uuid' => $data['coolify_uuid'] ?? null,
            'kind' => $data['coolify_kind'] ?? $this->deployKind($service),
            'resource' => $data['coolify_resource'] ?? null,
            'git_repository' => $data['coolify_git_repository'] ?? $this->gitRepository($service),
            'git_branch' => $data['coolify_git_branch'] ?? $this->gitBranch($service),
            'fqdn' => $data['coolify_fqdn'] ?? $this->publicUrl($service),
            'status' => $data['coolify_status'] ?? $service->status,
        ];
    }

    // -------------------------------------------------------------------------
    // Coolify HTTP
    // -------------------------------------------------------------------------

    private function api(Server $server, string $method, string $endpoint, array $data = []): array
    {
        $token = (string) ($server->access_hash ?? '');
        if ($token === '') {
            return ['success' => false, 'message' => 'Coolify API token not configured.', 'raw' => []];
        }

        $url = $this->baseUrl($server).'/'.ltrim($endpoint, '/');

        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->timeout(60);

            $response = match (strtoupper($method)) {
                'POST' => $request->post($url, $data),
                'PATCH' => $request->patch($url, $data),
                'PUT' => $request->put($url, $data),
                'DELETE' => $request->delete($url, $data),
                default => $request->get($url, $data),
            };

            if (! $response->successful()) {
                $body = $response->json();
                $error = is_array($body)
                    ? ($body['message'] ?? $body['error'] ?? json_encode($body))
                    : $response->body();

                return [
                    'success' => false,
                    'message' => is_string($error) && $error !== '' ? $error : "HTTP {$response->status()}",
                    'raw' => is_array($body) ? $body : [],
                ];
            }

            $json = $response->json();

            return ['success' => true, 'message' => 'OK', 'raw' => is_array($json) ? $json : ['body' => $response->body()]];
        } catch (\Throwable $e) {
            Log::error("CoolifyModule API error: {$e->getMessage()}", [
                'server_id' => $server->id,
                'endpoint' => $endpoint,
            ]);

            return ['success' => false, 'message' => $e->getMessage(), 'raw' => []];
        }
    }

    private function baseUrl(Server $server): string
    {
        $host = $this->serverHost($server);
        $port = (int) ($server->port ?: 8000);
        $scheme = $port === 80 ? 'http' : 'https';

        if (in_array($port, [80, 443], true)) {
            return "{$scheme}://{$host}/api/v1";
        }

        return "{$scheme}://{$host}:{$port}/api/v1";
    }

    // -------------------------------------------------------------------------
    // Provisioning helpers
    // -------------------------------------------------------------------------

    private function createWordpress(Server $server, Service $service, string $projectUuid, string $destination): array
    {
        $name = $this->resourceName($service);
        $payload = [
            'type' => 'wordpress-with-mysql',
            'name' => $name,
            'description' => 'Webkahost one-click WordPress',
            'project_uuid' => $projectUuid,
            'server_uuid' => $destination,
            'environment_name' => 'production',
            'instant_deploy' => true,
        ];

        $url = $this->publicUrl($service);
        if ($url !== '') {
            $payload['urls'] = [['name' => 'wordpress', 'url' => $url]];
        }

        $result = $this->api($server, 'POST', 'services', $payload);
        if (! ($result['success'] ?? false)) {
            // Some catalogues register the template as "wordpress".
            $payload['type'] = 'wordpress';
            $result = $this->api($server, 'POST', 'services', $payload);
        }

        if (! ($result['success'] ?? false)) {
            return $this->buildResult(false, "WordPress deploy failed: {$result['message']}");
        }

        return $this->buildResult(true, 'WordPress created.', [
            'uuid' => $this->extractUuid($result['raw']),
            'fqdn' => $url,
        ]);
    }

    private function createGitApplication(Server $server, Service $service, string $projectUuid, string $destination, string $kind): array
    {
        $repository = $this->gitRepository($service);
        if ($repository === '' || ! $this->isPublicGitUrl($repository)) {
            return $this->buildResult(false, 'Set a public https Git repository on the product (or the service) before deploying Node.js / Git apps.');
        }

        $branch = $this->gitBranch($service);
        $buildPack = $kind === 'static' ? 'static' : 'nixpacks';
        $ports = $this->portsExposes($service, $kind);

        $payload = [
            'project_uuid' => $projectUuid,
            'server_uuid' => $destination,
            'environment_name' => 'production',
            'git_repository' => $repository,
            'git_branch' => $branch,
            'build_pack' => $buildPack,
            'ports_exposes' => $ports,
            'instant_deploy' => true,
            'name' => $this->resourceName($service),
        ];

        $url = $this->publicUrl($service);
        if ($url !== '') {
            $payload['domains'] = $url;
        }

        $result = $this->api($server, 'POST', 'applications/public', $payload);
        if (! ($result['success'] ?? false)) {
            return $this->buildResult(false, "Git deploy failed: {$result['message']}");
        }

        return $this->buildResult(true, 'Application created.', [
            'uuid' => $this->extractUuid($result['raw']),
            'git_repository' => $repository,
            'git_branch' => $branch,
            'fqdn' => $url,
        ]);
    }

    private function ensureProject(Server $server, Service $service): ?string
    {
        $existing = (string) ($this->getModuleData($service)['coolify_project_uuid'] ?? '');
        if ($existing !== '') {
            return $existing;
        }

        $clientId = (int) $service->client_id;
        $name = 'webkahost-client-'.$clientId;

        $list = $this->api($server, 'GET', 'projects');
        if ($list['success'] ?? false) {
            foreach ($list['raw'] as $project) {
                if (! is_array($project)) {
                    continue;
                }
                if (($project['name'] ?? '') === $name && ! empty($project['uuid'])) {
                    return (string) $project['uuid'];
                }
            }
            // Coolify sometimes wraps the list.
            foreach ($list['raw']['data'] ?? [] as $project) {
                if (is_array($project) && ($project['name'] ?? '') === $name && ! empty($project['uuid'])) {
                    return (string) $project['uuid'];
                }
            }
        }

        $created = $this->api($server, 'POST', 'projects', [
            'name' => $name,
            'description' => 'Webkahost customer '.$clientId,
        ]);

        if (! ($created['success'] ?? false)) {
            return null;
        }

        return $this->extractUuid($created['raw']);
    }

    private function destinationServerUuid(Server $server): string
    {
        $fromRecord = trim((string) ($server->username ?? ''));
        if ($fromRecord !== '' && Str::isUuid($fromRecord)) {
            return $fromRecord;
        }
        if ($fromRecord !== '' && preg_match('/^[0-9a-f-]{8,}$/i', $fromRecord)) {
            return $fromRecord;
        }

        $list = $this->api($server, 'GET', 'servers');
        if (! ($list['success'] ?? false)) {
            return '';
        }

        $rows = $list['raw'];
        if (isset($rows['data']) && is_array($rows['data'])) {
            $rows = $rows['data'];
        }

        foreach ($rows as $row) {
            if (is_array($row) && ! empty($row['uuid'])) {
                return (string) $row['uuid'];
            }
        }

        return '';
    }

    private function lifecycle(Service $service, string $action, string $status, array $extra, string $okMessage): array
    {
        $server = $this->getServer($service);
        if (! $server) {
            return $this->buildResult(false, 'No Coolify server configured.');
        }

        $uuid = $this->resourceUuid($service);
        if ($uuid === '') {
            return $this->buildResult(false, 'Coolify resource UUID not found.');
        }

        $path = $this->resourcePath($service, $uuid).'/'.$action;
        $result = $this->api($server, 'GET', $path);
        if (! ($result['success'] ?? false)) {
            $result = $this->api($server, 'POST', $path);
        }

        if (! ($result['success'] ?? false)) {
            return $this->buildResult(false, ucfirst($action)." failed: {$result['message']}");
        }

        $service->update(array_merge(['status' => $status], $extra));
        $out = $this->buildResult(true, $okMessage);
        $this->logAction($service, $action, $out);

        return $out;
    }

    private function resourcePath(Service $service, string $uuid): string
    {
        return $this->resourceType($service) === 'service'
            ? "services/{$uuid}"
            : "applications/{$uuid}";
    }

    private function resourceType(Service $service): string
    {
        return ($this->getModuleData($service)['coolify_resource'] ?? '') === 'service'
            ? 'service'
            : 'application';
    }

    private function resourceUuid(Service $service): string
    {
        return (string) ($this->getModuleData($service)['coolify_uuid'] ?? '');
    }

    private function deployKind(Service $service): string
    {
        $config = $this->productConfig($service);
        $kind = strtolower((string) ($config['package_name'] ?? $config['coolify_kind'] ?? 'git'));

        return in_array($kind, ['wordpress', 'nodejs', 'nextjs', 'static', 'git'], true)
            ? $kind
            : 'git';
    }

    /**
     * @return array<string, mixed>
     */
    private function productConfig(Service $service): array
    {
        $product = $service->product;
        if (! $product) {
            return [];
        }

        $config = $product->config_options;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        return is_array($config) ? $config : [];
    }

    private function gitRepository(Service $service): string
    {
        $data = $this->getModuleData($service);
        if (! empty($data['coolify_git_repository'])) {
            return (string) $data['coolify_git_repository'];
        }

        $config = $this->productConfig($service);

        return trim((string) ($config['coolify_git_repository'] ?? $config['git_repository'] ?? ''));
    }

    private function gitBranch(Service $service): string
    {
        $data = $this->getModuleData($service);
        if (! empty($data['coolify_git_branch'])) {
            return (string) $data['coolify_git_branch'];
        }

        $config = $this->productConfig($service);
        $branch = trim((string) ($config['coolify_git_branch'] ?? $config['git_branch'] ?? 'main'));

        return $branch !== '' ? $branch : 'main';
    }

    private function portsExposes(Service $service, string $kind): string
    {
        $config = $this->productConfig($service);
        $ports = trim((string) ($config['coolify_ports'] ?? $config['ports_exposes'] ?? ''));
        if ($ports !== '') {
            return $ports;
        }

        return match ($kind) {
            'static' => '80',
            default => '3000',
        };
    }

    private function publicUrl(Service $service): string
    {
        $domain = trim((string) $service->domain);
        if ($domain === '' || ! str_contains($domain, '.')) {
            return '';
        }

        return 'https://'.$domain;
    }

    private function resourceName(Service $service): string
    {
        $domain = trim((string) $service->domain);

        return $domain !== '' ? $domain : 'webkahost-'.$service->id;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractUuid(array $raw): string
    {
        foreach (['uuid', 'application_uuid', 'service_uuid'] as $key) {
            if (! empty($raw[$key]) && is_string($raw[$key])) {
                return $raw[$key];
            }
        }

        if (! empty($raw['application']['uuid'])) {
            return (string) $raw['application']['uuid'];
        }

        return '';
    }

    public function isPublicGitUrl(string $url): bool
    {
        if (! preg_match('#^https://[a-zA-Z0-9._-]+/[a-zA-Z0-9._/-]+(?:\.git)?$#', $url)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, [
            'github.com',
            'gitlab.com',
            'bitbucket.org',
            'codeberg.org',
            'gitea.com',
        ], true) || str_ends_with($host, '.gitea.io');
    }
}
