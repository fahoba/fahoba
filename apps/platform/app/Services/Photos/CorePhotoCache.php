<?php

declare(strict_types=1);

namespace App\Services\Photos;

use App\Services\PhotoImage;
use Closure;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Photos\PhotoCache;
use Fahoba\PluginApi\Photos\PhotoFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Remote pictures kept on the private disk: `photo-cache/<household>/<account>/`.
 *
 * Redrawn like an upload, so the frame serves a picture of a known size and
 * format without the original's metadata. The file's modification time is
 * the last time it was served; `fahoba:purge-photo-cache` removes what has
 * not been shown for KEEP_DAYS and what belongs to a removed connection.
 */
final class CorePhotoCache implements PhotoCache
{
    public const DIRECTORY = 'photo-cache';

    /** Unshown for this long, a kept picture goes; the next show fetches it again. */
    public const KEEP_DAYS = 30;

    public function __construct(private readonly PhotoImage $image) {}

    public function remember(ConnectedAccount $account, string $id, Closure $fetch): ?PhotoFile
    {
        $disk = Storage::disk('local');
        $path = self::folder($account->householdId(), $account->accountId()).'/'.sha1($id).'.webp';

        if ($disk->exists($path)) {
            touch($disk->path($path));

            return new PhotoFile($disk->path($path), 'image/webp');
        }

        try {
            $content = $fetch();

            if (! is_string($content) || $content === '') {
                return null;
            }

            $disk->put($path, $this->image->normalize($content));
        } catch (Throwable) {
            // Unreachable service or no picture: this one is skipped, the frame shows the next.
            return null;
        }

        return new PhotoFile($disk->path($path), 'image/webp');
    }

    public static function folder(int $household, ?int $account = null): string
    {
        return self::DIRECTORY.'/'.$household.($account === null ? '' : '/'.$account);
    }

    /** Everything a household kept — when it is deleted. */
    public static function forget(int $household): void
    {
        Storage::disk('local')->deleteDirectory(self::folder($household));
    }
}
