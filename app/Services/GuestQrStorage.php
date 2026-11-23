<?php

namespace App\Services;

use App\Exceptions\GuestQrStorageUnavailable;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter as FlysystemLocalAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\WhitespacePathNormalizer;
use ReflectionProperty;
use Throwable;

/**
 * Narrow shared policy for the fixed private ordinary-QR disk (P4-046 design).
 *
 * Every byte reader, issuance probe/write and deletion resolves QR bytes here.
 * It validates the fixed configuration, the actual private root and exact
 * relative keys. It makes no authorization decision, never falls back to
 * public storage, and never creates, copies, migrates or repairs files.
 */
final class GuestQrStorage
{
    public const DISK = 'guest_qr';

    /** Options that would add serving, URL, prefix, link or read-only behavior. */
    private const FORBIDDEN_OPTIONS = ['url', 'serve', 'prefix', 'temporary_url', 'links', 'read-only'];

    /** Windows device names, including the console and superscript-digit (UTF-8 or single-byte) forms. */
    private const DEVICE_NAME = '/\A(?:con|prn|aux|nul|conin\$|conout\$|(?:com|lpt)(?:[0-9]|\xC2[\xB9\xB2\xB3]|[\xB9\xB2\xB3]))\z/i';

    /**
     * Exact stored keys only; never normalize to a different target. Absence
     * (null, empty, '0') is the caller's decision and is not a key.
     */
    public static function isSafeKey(mixed $key): bool
    {
        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            return false;
        }
        // Control bytes, backslashes (UNC/Windows separators) and colons (drives, URLs, streams).
        if (preg_match('/[\x00-\x1F\x7F\\\\:]/', $key) === 1) {
            return false;
        }
        foreach (explode('/', $key) as $segment) {
            // Empty segments cover absolute, doubled and trailing separators.
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/[. ]\z/', $segment) === 1
                // A device name stays a device before any extension and despite surrounding spaces.
                || preg_match(self::DEVICE_NAME, trim(explode('.', $segment, 2)[0], ' ')) === 1
                // Windows 8.3 short names (for example qr-cod~1) can alias another entry.
                || preg_match('/~[0-9]/', $segment) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate configuration and root, then resolve the fixed local adapter and
     * verify that it actually targets the validated root. Callers resolve it
     * only after their own authority/path/reference checks.
     *
     * @throws GuestQrStorageUnavailable
     */
    public function disk(): LocalFilesystemAdapter
    {
        $root = $this->validatedRoot();
        try {
            $disk = Storage::disk(self::DISK);
            if (! $disk instanceof LocalFilesystemAdapter) {
                throw new GuestQrStorageUnavailable;
            }
            $driver = $disk->getDriver();
            $adapter = $disk->getAdapter();
            $config = $disk->getConfig();
            $prefix = $disk->path('');
            // The stock operator must wrap exactly this stock local adapter, whose own private
            // prefix is the root that every exists/get/put/delete actually uses, and must pass
            // keys through the stock normalizer (a custom one could rewrite them elsewhere).
            // Flysystem internals that no longer match refuse rather than pass.
            $operatorAdapter = is_object($driver) && get_class($driver) === Flysystem::class
                ? (new ReflectionProperty(Flysystem::class, 'adapter'))->getValue($driver) : null;
            $normalizer = $operatorAdapter !== null
                ? (new ReflectionProperty(Flysystem::class, 'pathNormalizer'))->getValue($driver) : null;
            $prefixer = is_object($adapter) && get_class($adapter) === FlysystemLocalAdapter::class
                ? (new ReflectionProperty(FlysystemLocalAdapter::class, 'prefixer'))->getValue($adapter) : null;
            $ioRoot = $prefixer instanceof PathPrefixer ? rtrim($prefixer->prefixPath(''), '/\\') : null;
        } catch (Throwable) {
            throw new GuestQrStorageUnavailable;
        }
        if ($operatorAdapter === null || $operatorAdapter !== $adapter || ! is_string($ioRoot)
            || ! is_object($normalizer) || get_class($normalizer) !== WhitespacePathNormalizer::class
            || ! is_array($config) || ! is_string($prefix)) {
            throw new GuestQrStorageUnavailable;
        }
        // A cached handle built from earlier/different configuration is refused, not reused.
        $handleRoot = is_string($config['root'] ?? null) ? realpath($config['root']) : false;
        $prefixRoot = realpath(rtrim($prefix, '/\\'));
        if ($handleRoot === false || $prefixRoot === false || ! self::samePath($ioRoot, $root)
            || ! self::samePath($handleRoot, $root) || ! self::samePath($prefixRoot, $root)
            || ($config['throw'] ?? null) !== true || self::hasForbiddenOption($config)) {
            throw new GuestQrStorageUnavailable;
        }

        return $disk;
    }

    private function validatedRoot(): string
    {
        $config = config('filesystems.disks.'.self::DISK);
        if (! is_array($config) || ($config['driver'] ?? null) !== 'local'
            || ($config['throw'] ?? null) !== true || ($config['report'] ?? null) !== false
            || ($config['visibility'] ?? null) !== 'private' || self::hasForbiddenOption($config)) {
            throw new GuestQrStorageUnavailable;
        }
        $configured = $config['root'] ?? null;
        if (! is_string($configured) || ! self::isAbsolute($configured)) {
            throw new GuestQrStorageUnavailable;
        }
        // Missing roots are refused; a symlink/junction alias makes the real path differ.
        $real = realpath($configured);
        if ($real === false || ! is_dir($real) || ! self::samePath($real, $configured)) {
            throw new GuestQrStorageUnavailable;
        }
        foreach ($this->servedRoots() as $served) {
            if (self::within($real, $served) || self::within($served, $real)) {
                throw new GuestQrStorageUnavailable;
            }
        }

        return $real;
    }

    /** Web-public, storage-link and URL/signed-serving disk roots that must not overlap. */
    private function servedRoots(): array
    {
        $roots = [public_path()];
        foreach ((array) config('filesystems.links', []) as $link => $target) {
            $roots[] = $link;
            $roots[] = $target;
        }
        foreach ((array) config('filesystems.disks', []) as $name => $disk) {
            if ($name !== self::DISK && is_array($disk) && ($disk['driver'] ?? null) === 'local'
                && (! empty($disk['url']) || ! empty($disk['serve']) || ($disk['visibility'] ?? null) === 'public')) {
                $roots[] = $disk['root'] ?? null;
            }
        }

        $resolved = [];
        foreach ($roots as $path) {
            if (is_string($path) && $path !== '') {
                $resolved[] = realpath($path) ?: $path;
            }
        }

        return $resolved;
    }

    private static function hasForbiddenOption(array $config): bool
    {
        foreach (self::FORBIDDEN_OPTIONS as $option) {
            if (array_key_exists($option, $config) && $config[$option] !== null && $config[$option] !== false) {
                return true;
            }
        }

        return false;
    }

    private static function isAbsolute(string $path): bool
    {
        if (str_contains($path, "\0") || str_contains($path, '://')) {
            return false;
        }

        return PHP_OS_FAMILY === 'Windows'
            ? preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1
            : str_starts_with($path, '/');
    }

    private static function normalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }

    private static function samePath(string $left, string $right): bool
    {
        return self::normalize($left) === self::normalize($right);
    }

    private static function within(string $path, string $parent): bool
    {
        $path = self::normalize($path);
        $parent = self::normalize($parent);

        return $path === $parent || str_starts_with($path, $parent.'/');
    }
}
