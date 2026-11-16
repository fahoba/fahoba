<?php

declare(strict_types=1);

namespace Fahoba\Immich;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

final class ImmichPlugin extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('photos', ImmichPhotoSource::KEY, ImmichPhotoSource::class);

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }
}
