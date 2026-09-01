<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Module\ModuleRegistry;
use Illuminate\Http\Request;
use Modules\Servers\Coolify\CoolifyModule;

class CoolifyDeployController extends Controller
{
    use ResolvesClient;

    public function show(Service $service)
    {
        $this->guard($service);
        $module = $this->module();
        abort_unless($module, 404);

        return view('client.services.coolify', [
            'service' => $service->load('product', 'server'),
            'deployment' => $module->deploymentSummary($service),
            'connection' => $module->connectionInfo($service),
        ]);
    }

    public function redeploy(Service $service)
    {
        $this->guard($service);
        $module = $this->module();
        abort_unless($module, 404);

        $result = $module->redeploy($service);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function updateGit(Request $request, Service $service)
    {
        $this->guard($service);
        $module = $this->module();
        abort_unless($module, 404);

        $validated = $request->validate([
            'git_repository' => 'required|url|max:500',
            'git_branch' => 'nullable|string|max:120',
        ]);

        $result = $module->updateGitSource(
            $service,
            $validated['git_repository'],
            $validated['git_branch'] ?? 'main'
        );

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function updateDomain(Request $request, Service $service)
    {
        $this->guard($service);
        $module = $this->module();
        abort_unless($module, 404);

        $validated = $request->validate([
            'domain' => 'required|string|max:253',
        ]);

        $result = $module->attachDomain($service, $validated['domain'], true);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function updateEnv(Request $request, Service $service)
    {
        $this->guard($service);
        $module = $this->module();
        abort_unless($module, 404);

        $validated = $request->validate([
            'key' => 'required|string|max:80',
            'value' => 'nullable|string|max:4000',
        ]);

        $result = $module->setEnvironmentVariable($service, $validated['key'], (string) ($validated['value'] ?? ''));

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    private function guard(Service $service): void
    {
        abort_if($service->client_id !== $this->getClientId(), 403);
        $type = strtolower((string) ($service->server?->type ?? $service->product?->server_type ?? ''));
        abort_unless($type === 'coolify', 404);
    }

    private function module(): ?CoolifyModule
    {
        $module = app(ModuleRegistry::class)->getServerModule('coolify');

        return $module instanceof CoolifyModule ? $module : null;
    }
}
