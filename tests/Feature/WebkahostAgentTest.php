<?php

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Webkahost\AiCreditService;
use App\Services\Webkahost\WebkahostAgent;
use Illuminate\Support\Facades\Http;

function agentUser(): array
{
    $client = Client::factory()->create();
    $user = User::factory()->create();
    $user->clients()->attach($client->id);

    return [$user, $client];
}

it('maps a wordpress sentence onto the wordpress tool', function () {
    $plan = app(WebkahostAgent::class)->plan('Please deploy wordpress on blog.example.com');

    expect($plan[0]['name'])->toBe('deploy_wordpress')
        ->and($plan[0]['arguments']['domain'] ?? null)->toBe('blog.example.com');
});

it('maps a github url onto the git deploy tool', function () {
    $plan = app(WebkahostAgent::class)->plan('deploy https://github.com/me/node-app on api.example.com');

    expect($plan[0]['name'])->toBe('deploy_git_app')
        ->and($plan[0]['arguments']['git_repository'] ?? null)->toBe('https://github.com/me/node-app');
});

it('maps a postgres sentence onto the database tool', function () {
    $plan = app(WebkahostAgent::class)->plan('please deploy postgresql for me');

    expect($plan[0]['name'])->toBe('deploy_database')
        ->and($plan[0]['arguments']['engine'] ?? null)->toBe('postgresql');
});

it('maps an ssl sentence onto attach_domain', function () {
    $plan = app(WebkahostAgent::class)->plan('ssl on shop.example.com');

    expect($plan[0]['name'])->toBe('attach_domain')
        ->and($plan[0]['arguments']['domain'] ?? null)->toBe('shop.example.com');
});

it('maps an n8n sentence onto the one-click tool', function () {
    $plan = app(WebkahostAgent::class)->plan('deploy n8n for me');

    expect($plan[0]['name'])->toBe('deploy_oneclick')
        ->and($plan[0]['arguments']['kind'] ?? null)->toBe('n8n');
});

it('lists only this customer\'s services', function () {
    [$user, $client] = agentUser();
    $other = Client::factory()->create();

    $group = ProductGroup::factory()->create();
    $product = Product::factory()->create(['group_id' => $group->id, 'name' => 'Mine App', 'server_type' => 'coolify']);
    Service::factory()->create([
        'client_id' => $client->id,
        'product_id' => $product->id,
        'order_id' => Order::factory()->create(['client_id' => $client->id])->id,
        'domain' => 'mine.example.com',
        'status' => 'active',
    ]);
    Service::factory()->create([
        'client_id' => $other->id,
        'product_id' => $product->id,
        'order_id' => Order::factory()->create(['client_id' => $other->id])->id,
        'domain' => 'secret.example.com',
        'status' => 'active',
    ]);

    $result = app(WebkahostAgent::class)->runTool($client, 'list_services', []);

    expect($result['ok'])->toBeTrue()
        ->and($result['summary'])->toContain('mine.example.com')
        ->and($result['summary'])->not->toContain('secret.example.com');
});

it('refuses to deploy when the customer has no coolify plan', function () {
    $client = Client::factory()->create();

    $result = app(WebkahostAgent::class)->runTool($client, 'deploy_wordpress', ['domain' => 'x.example.com']);

    expect($result['ok'])->toBeFalse()
        ->and($result['summary'])->toContain('No Coolify');
});

it('deploys wordpress through coolify when the customer has a plan', function () {
    Http::fake([
        '*/api/v1/projects' => Http::response(['uuid' => 'proj-a'], 201),
        '*/api/v1/services' => Http::response(['uuid' => 'svc-a'], 201),
    ]);

    $client = Client::factory()->create();
    $server = Server::factory()->create([
        'type' => 'coolify',
        'hostname' => 'coolify.test',
        'ip_address' => '203.0.113.9',
        'port' => 8000,
        'username' => '22222222-2222-2222-2222-222222222222',
        'access_hash' => 'token',
    ]);
    $product = Product::factory()->create([
        'group_id' => ProductGroup::factory()->create()->id,
        'server_type' => 'coolify',
        'config_options' => ['package_name' => 'wordpress'],
    ]);
    $service = Service::factory()->create([
        'client_id' => $client->id,
        'product_id' => $product->id,
        'server_id' => $server->id,
        'order_id' => Order::factory()->create(['client_id' => $client->id])->id,
        'domain' => 'old.example.com',
        'status' => 'active',
    ]);

    $result = app(WebkahostAgent::class)->runTool($client, 'deploy_wordpress', ['domain' => 'blog.example.com']);

    expect($result['ok'])->toBeTrue()
        ->and($service->fresh()->domain)->toBe('blog.example.com')
        ->and($service->fresh()->module_data['coolify_uuid'] ?? null)->toBe('svc-a');
});

it('maps a redeploy sentence onto the redeploy tool', function () {
    $plan = app(WebkahostAgent::class)->plan('please redeploy my site');

    expect($plan[0]['name'])->toBe('redeploy');
});

it('maps a set-env sentence onto the set_env tool', function () {
    $plan = app(WebkahostAgent::class)->plan('set DATABASE_URL=postgres://db/app');

    expect($plan[0]['name'])->toBe('set_env')
        ->and($plan[0]['arguments']['key'] ?? null)->toBe('DATABASE_URL')
        ->and($plan[0]['arguments']['value'] ?? null)->toBe('postgres://db/app');
});

it('redeploys through coolify when the customer has a live app', function () {
    Http::fake([
        '*/api/v1/deploy*' => Http::response(['uuid' => 'dep-1'], 200),
        '*/api/v1/applications/*/restart' => Http::response(['ok' => true], 200),
    ]);

    $client = Client::factory()->create();
    $server = Server::factory()->create([
        'type' => 'coolify',
        'hostname' => 'coolify.test',
        'ip_address' => '203.0.113.9',
        'port' => 8000,
        'username' => '22222222-2222-2222-2222-222222222222',
        'access_hash' => 'token',
    ]);
    $product = Product::factory()->create([
        'group_id' => ProductGroup::factory()->create()->id,
        'server_type' => 'coolify',
        'config_options' => ['package_name' => 'nodejs'],
    ]);
    $service = Service::factory()->create([
        'client_id' => $client->id,
        'product_id' => $product->id,
        'server_id' => $server->id,
        'order_id' => Order::factory()->create(['client_id' => $client->id])->id,
        'domain' => 'app.example.com',
        'status' => 'active',
        'module_data' => [
            'coolify_uuid' => 'app-a',
            'coolify_resource' => 'application',
        ],
    ]);

    $result = app(WebkahostAgent::class)->runTool($client, 'redeploy', []);

    expect($result['ok'])->toBeTrue()
        ->and($service->fresh()->module_data['coolify_uuid'] ?? null)->toBe('app-a');
});

it('answers an agent chat from the portal', function () {
    [$user, $client] = agentUser();
    app(AiCreditService::class)->credit($client, 50, 'grant', 'agent tests');

    $this->actingAs($user)
        ->get(route('client.ai.agent'))
        ->assertOk()
        ->assertSee('Oneploy Agent', false);

    $this->actingAs($user)
        ->post(route('client.ai.agent.message'), ['message' => 'what is my credit balance?'])
        ->assertRedirect()
        ->assertSessionHas('agent_reply');
});
