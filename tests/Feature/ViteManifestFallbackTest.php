<?php

use App\Support\OptionalVite;

it('does not throw when the vite manifest is missing', function () {
    $vite = new OptionalVite;
    $vite->useHotFile(storage_path('framework/testing-missing-vite-hot'));
    $vite->useBuildDirectory('build-that-does-not-exist');

    expect((string) $vite(['resources/css/app.css']))->toBe('')
        ->and($vite->asset('resources/css/app.css'))->toBe('');
});

it('renders client login, register, and store without a vite manifest', function () {
    $this->withVite();

    $manifest = public_path('build/manifest.json');
    $backup = $manifest.'.testbak';
    $moved = is_file($manifest);
    if ($moved) {
        rename($manifest, $backup);
    }

    try {
        $this->get('/client/login')
            ->assertOk()
            ->assertSee(__('client.auth.sign_in'), false);

        $this->get('/client/register')->assertOk();
        $this->get('/client/store')->assertOk();
        $this->get('/client/home')->assertRedirect(route('client.login'));
    } finally {
        if ($moved && is_file($backup)) {
            rename($backup, $manifest);
        }
    }
});
