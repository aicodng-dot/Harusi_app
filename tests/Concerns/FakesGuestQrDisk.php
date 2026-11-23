<?php

namespace Tests\Concerns;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Replaces the fixed private ordinary-QR disk with a real local disk on a fresh
 * testing root under the application's storage path. The complete production
 * configuration is applied and the disk is built by the normal local factory,
 * so App\Services\GuestQrStorage validates it exactly as in production; there
 * is no testing-only bypass.
 */
trait FakesGuestQrDisk
{
    protected function fakeGuestQrDisk(): LocalFilesystemAdapter
    {
        $root = $this->app->storagePath('framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'disks'.DIRECTORY_SEPARATOR.'guest_qr');
        $files = new Filesystem;
        $files->ensureDirectoryExists($root);
        $files->cleanDirectory($root);
        $real = realpath($root);
        if ($real === false) {
            throw new LogicException('The private QR testing root could not be prepared.');
        }

        config(['filesystems.disks.guest_qr' => ['driver' => 'local', 'root' => $real,
            'visibility' => 'private', 'throw' => true, 'report' => false]]);
        Storage::forgetDisk('guest_qr');
        $disk = Storage::disk('guest_qr');
        if (! $disk instanceof LocalFilesystemAdapter || realpath(rtrim($disk->path(''), '/\\')) !== $real) {
            throw new LogicException('The private QR testing disk must target its prepared root.');
        }

        return $disk;
    }
}
