<?php

declare(strict_types=1);

namespace Fahoba\CloudPhotos;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

/**
 * OneDrive and Dropbox in one package: the same consent, the same app
 * folder. Each is offered only once the operator registered the server with
 * that service — a choice in the wizard that ends in an error would be worse
 * than none.
 */
final class CloudPhotosPlugin extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cloud-photos.php', 'cloud-photos');

        $catalog = $this->app->make(Catalog::class);

        if (OneDrivePhotoSource::client()->isRegistered()) {
            $catalog->offer('photos', OneDrivePhotoSource::KEY, OneDrivePhotoSource::class);
        }

        if (DropboxPhotoSource::client()->isRegistered()) {
            $catalog->offer('photos', DropboxPhotoSource::KEY, DropboxPhotoSource::class);
        }

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }
}
