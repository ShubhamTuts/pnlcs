<?php

use App\Support\OneployHosts;

function enableOneployHosts(): void
{
    config([
        'oneploy.marketing_host' => 'oneploy.dev',
        'oneploy.client_host' => 'client.oneploy.dev',
        'oneploy.billing_host' => 'billing.oneploy.dev',
    ]);
}

it('does not split hosts when env is empty', function () {
    config([
        'oneploy.marketing_host' => '',
        'oneploy.client_host' => '',
        'oneploy.billing_host' => '',
    ]);

    expect(OneployHosts::splitEnabled())->toBeFalse();

    $this->get('/')->assertOk();
});

it('sends the client portal root to the logged-in home', function () {
    enableOneployHosts();

    $this->get('http://client.oneploy.dev/')
        ->assertRedirect('http://client.oneploy.dev/client/home');
});

it('sends the billing portal root to invoices', function () {
    enableOneployHosts();

    $this->get('http://billing.oneploy.dev/')
        ->assertRedirect('http://billing.oneploy.dev/client/invoices');
});

it('moves invoices onto the billing host', function () {
    enableOneployHosts();

    $this->get('http://client.oneploy.dev/client/invoices')
        ->assertRedirect('http://billing.oneploy.dev/client/invoices');
});

it('keeps the agent on the client host', function () {
    enableOneployHosts();

    $this->get('http://billing.oneploy.dev/client/ai/agent')
        ->assertRedirect('http://client.oneploy.dev/client/ai/agent');
});

it('builds portal urls from oneploy_url', function () {
    enableOneployHosts();

    $this->get('http://oneploy.dev/');

    expect(oneploy_url('client', '/client/register'))->toContain('client.oneploy.dev')
        ->and(oneploy_url('billing', '/client/invoices'))->toContain('billing.oneploy.dev');
});
