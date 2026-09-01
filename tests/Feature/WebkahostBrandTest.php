<?php

use App\Models\Setting;
use App\Services\ThemeManager;

it('applies the Webkahost white-label name', function () {
    $this->artisan('webkahost:brand')
        ->assertSuccessful();

    expect(Setting::get('whitelabel_company_name'))->toBe('Webkahost')
        ->and(Setting::get('whitelabel_remove_branding'))->toBe('1');
});

it('installs the webkahost theme on disk', function () {
    $themes = app(ThemeManager::class)->getInstalled();

    expect($themes)->toHaveKey('webkahost')
        ->and($themes['webkahost']->name)->toBe('Webkahost');
});
