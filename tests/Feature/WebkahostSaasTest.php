<?php

use App\Models\Product;
use App\Models\Server;
use App\Services\Webkahost\SaasCatalog;

it('prints a saas plan without writing when dry-run', function () {
    $this->artisan('webkahost:saas', ['--dry-run' => true, '--catalog' => true, '--brand' => true])
        ->assertSuccessful();

    expect(Product::where('slug', 'webkahost-wordpress')->exists())->toBeFalse();
});

it('seeds the coolify saas catalog', function () {
    $result = app(SaasCatalog::class)->seed();

    expect($result['products'])->toBeGreaterThan(8)
        ->and(Product::where('server_type', 'coolify')->where('slug', 'webkahost-postgresql')->exists())->toBeTrue()
        ->and(Product::where('slug', 'webkahost-n8n')->value('auto_setup'))->toBe('payment')
        ->and(Product::where('slug', 'webkahost-wordpress')->value('auto_setup'))->toBe('payment');
});

it('registers a coolify server on connect in dry-run', function () {
    $this->artisan('webkahost:saas', [
        '--dry-run' => true,
        '--connect' => true,
        '--url' => 'https://coolify.example.com',
        '--token' => 'tok',
    ])->assertSuccessful();

    expect(Server::where('type', 'coolify')->exists())->toBeFalse();
});

it('writes a coolify server when connect succeeds', function () {
    Http::fake(['*/api/v1/version' => Http::response(['version' => '4.0.0'], 200)]);

    $this->artisan('webkahost:saas', [
        '--connect' => true,
        '--url' => 'https://coolify.example.com',
        '--token' => 'tok-live',
    ])->assertSuccessful();

    $server = Server::where('type', 'coolify')->where('hostname', 'coolify.example.com')->first();

    expect($server)->not->toBeNull()
        ->and($server->port)->toBe(443)
        ->and($server->access_hash)->toBe('tok-live');
});
