<?php

namespace App\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * PHP-only deploys (and `artisan serve` without `npm run build`) have no
 * public/build/manifest.json. Laravel's @vite then throws
 * ViteManifestNotFoundException and the client portal 500s. Skip tags until
 * a manifest or Vite hot file exists; layouts already carry inline CSS.
 */
class OptionalVite extends Vite
{
    public function __invoke($entrypoints, $buildDirectory = null)
    {
        if (! $this->assetsAreBuilt($buildDirectory)) {
            return new HtmlString('');
        }

        return parent::__invoke($entrypoints, $buildDirectory);
    }

    public function reactRefresh()
    {
        if (! $this->assetsAreBuilt()) {
            return new HtmlString('');
        }

        return parent::reactRefresh();
    }

    public function asset($asset, $buildDirectory = null)
    {
        if (! $this->assetsAreBuilt($buildDirectory)) {
            return '';
        }

        return parent::asset($asset, $buildDirectory);
    }

    protected function assetsAreBuilt(?string $buildDirectory = null): bool
    {
        if ($this->isRunningHot()) {
            return true;
        }

        $buildDirectory ??= $this->buildDirectory;

        return is_file($this->manifestPath($buildDirectory));
    }
}
