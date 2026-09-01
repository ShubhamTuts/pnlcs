<?php

use App\Models\Setting;
use App\Services\ThemeManager;

afterEach(function () {
    // artisan() writes settings outside the test transaction, so restore
    // the default theme or later GET / tests would keep the Oneploy welcome.
    if (Setting::get('active_theme_slug') === 'oneploy') {
        Setting::set('active_theme_slug', 'panelica', 'appearance');
        app(ThemeManager::class)->applyViewLocations();
    }
});

it('applies the Oneploy white-label name and theme', function () {
    $this->artisan('oneploy:brand')
        ->assertSuccessful();

    expect(Setting::get('whitelabel_company_name'))->toBe('Oneploy')
        ->and(Setting::get('whitelabel_remove_branding'))->toBe('1')
        ->and(Setting::get('active_theme_slug'))->toBe('oneploy');
});

it('installs the oneploy theme on disk', function () {
    $themes = app(ThemeManager::class)->getInstalled();

    expect($themes)->toHaveKey('oneploy')
        ->and($themes['oneploy']->name)->toBe('Oneploy')
        ->and($themes['oneploy']->isBuiltin)->toBeTrue();
});

it('renders the Oneploy marketing homepage after branding', function () {
    $this->artisan('oneploy:brand')->assertSuccessful();

    $this->get('/')
        ->assertOk()
        ->assertSee('Oneploy', false)
        ->assertSee('Get Started', false)
        ->assertSee('Search Domain', false)
        ->assertSee('Log In', false)
        ->assertSee('WordPress Hosting', false)
        ->assertDontSee('PNLCS', false);
});
