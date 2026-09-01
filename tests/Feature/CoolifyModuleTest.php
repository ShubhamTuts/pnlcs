<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use App\Models\Service;
use App\Services\Module\ModuleRegistry;
use Illuminate\Support\Facades\Http;
use Modules\Servers\Coolify\CoolifyModule;

function coolifyServer(array $attrs = []): Server
{
    return Server::factory()->create(array_merge([
        'type' => 'coolify',
        'hostname' => 'coolify.webkahost.test',
        'ip_address' => '203.0.113.50',
        'port' => 8000,
        'username' => '11111111-1111-1111-1111-111111111111',
        'access_hash' => 'coolify-token',
    ], $attrs));
}

function coolifyService(Server $server, array $config = ['package_name' => 'wordpress'], array $moduleData = []): Service
{
    $product = Product::factory()->create([
        'group_id' => ProductGroup::factory()->create()->id,
        'server_type' => 'coolify',
        'config_options' => $config,
    ]);

    $client = Client::factory()->create();
    $service = Service::factory()->create([
        'client_id' => $client->id,
        'product_id' => $product->id,
        'server_id' => $server->id,
        'order_id' => Order::factory()->create(['client_id' => $client->id])->id,
        'domain' => 'app.webkahost.test',
        'status' => 'pending',
    ]);

    if ($moduleData !== []) {
        $service->forceFill(['module_data' => $moduleData])->save();
    }

    return $service->fresh();
}

test('coolify is a registered server module named after its key', function () {
    $module = app(ModuleRegistry::class)->getServerModule('coolify');

    expect($module)->toBeInstanceOf(CoolifyModule::class)
        ->and($module->getModuleName())->toBe('coolify')
        ->and(app(ModuleRegistry::class)->serverCredentialRequirement('coolify'))->toBe('token');
});

test('coolify offers wordpress and git packages without calling the API', function () {
    $ids = collect((new CoolifyModule)->listPackages(coolifyServer()))->pluck('id');

    expect($ids)->toContain('wordpress')->toContain('nodejs')->toContain('git');
});

it('treats a version response as a working connection', function () {
    Http::fake(['*/api/v1/version' => Http::response(['version' => '4.0.0'], 200)]);

    expect((new CoolifyModule)->testConnection(coolifyServer()))->toBeTrue();
});

it('deploys wordpress as a coolify service and records the uuid', function () {
    Http::fake([
        '*/api/v1/projects' => Http::response(['uuid' => 'proj-1'], 201),
        '*/api/v1/services' => Http::response(['uuid' => 'svc-wp-1'], 201),
    ]);

    $server = coolifyServer();
    $service = coolifyService($server);
    $result = (new CoolifyModule)->create($service);

    expect($result['success'])->toBeTrue()
        ->and($service->fresh()->status)->toBe('active')
        ->and($service->fresh()->module_data['coolify_uuid'])->toBe('svc-wp-1')
        ->and($service->fresh()->module_data['coolify_resource'])->toBe('service')
        ->and($service->fresh()->module_data['coolify_kind'])->toBe('wordpress');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/services')
        && $request['type'] === 'wordpress-with-mysql'
        && $request['instant_deploy'] === true);
});

it('will not deploy a git app without a public https repository', function () {
    Http::fake(['*' => Http::response(['uuid' => 'proj-1'], 200)]);

    $server = coolifyServer();
    $service = coolifyService($server, ['package_name' => 'nodejs']);
    $result = (new CoolifyModule)->create($service);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('public https Git repository');
});

it('creates a public git application when the product has a github url', function () {
    Http::fake([
        '*/api/v1/projects' => Http::response(['uuid' => 'proj-2'], 201),
        '*/api/v1/applications/public' => Http::response(['uuid' => 'app-1'], 201),
    ]);

    $server = coolifyServer();
    $service = coolifyService($server, [
        'package_name' => 'nodejs',
        'coolify_git_repository' => 'https://github.com/webkahost/hello-node',
        'coolify_git_branch' => 'main',
    ]);

    $result = (new CoolifyModule)->create($service);

    expect($result['success'])->toBeTrue()
        ->and($service->fresh()->module_data['coolify_uuid'])->toBe('app-1')
        ->and($service->fresh()->module_data['coolify_resource'])->toBe('application')
        ->and($service->fresh()->module_data['coolify_git_repository'])->toBe('https://github.com/webkahost/hello-node');
});

it('stops a coolify resource when the service is suspended', function () {
    Http::fake(['*/api/v1/applications/app-1/stop' => Http::response(['message' => 'ok'], 200)]);

    $server = coolifyServer();
    $service = coolifyService($server, ['package_name' => 'git'], [
        'coolify_uuid' => 'app-1',
        'coolify_resource' => 'application',
    ]);
    $service->update(['status' => 'active']);

    $result = (new CoolifyModule)->suspend($service, 'unpaid');

    expect($result['success'])->toBeTrue()
        ->and($service->fresh()->status)->toBe('suspended');
});

it('destroys the coolify resource on terminate', function () {
    Http::fake(['*/api/v1/applications/app-1' => Http::response(null, 200)]);

    $server = coolifyServer();
    $service = coolifyService($server, ['package_name' => 'git'], [
        'coolify_uuid' => 'app-1',
        'coolify_resource' => 'application',
    ]);
    $service->update(['status' => 'active']);

    $result = (new CoolifyModule)->terminate($service);

    expect($result['success'])->toBeTrue()
        ->and($service->fresh()->status)->toBe('terminated');
});

it('refuses a git url that is not a public https host', function () {
    $module = new CoolifyModule;

    expect($module->isPublicGitUrl('https://github.com/org/app'))->toBeTrue()
        ->and($module->isPublicGitUrl('git@github.com:org/app.git'))->toBeFalse()
        ->and($module->isPublicGitUrl('https://evil.example/org/app'))->toBeFalse();
});

test('the product create form offers coolify', function () {
    $html = $this->actingAs(Admin::factory()->create([
        'role_id' => AdminRole::factory()->fullAdmin()->create()->id,
    ]), 'admin')
        ->get(route('admin.products.create'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('value="coolify"');
});
