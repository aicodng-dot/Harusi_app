<?php

namespace App\Services\Invitations;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Couple photographs for the invitation editor.
 *
 * Uploads are validated by content (finfo + getimagesize), not by name or browser
 * MIME; dimensions and pixel count are checked before decoding (decompression-bomb
 * guard). The image is decoded, EXIF orientation corrected, downscaled to at most
 * 2400 px and re-encoded as JPEG, so metadata, scripts and polyglot payloads are not
 * kept. Files live on the private `invitations` disk under the owning event, named by
 * a random 128-bit id; they are only ever streamed by authorized controllers.
 */
class InvitationPhotoStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MIN_SIDE = 300;

    public const MAX_SIDE = 9000;

    public const MAX_PIXELS = 40_000_000;

    public const OUTPUT_SIDE = 2400;

    private const TYPES = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];

    /** @return string the new photo id */
    public function store(UploadedFile $file, int $eventId): string
    {
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > self::MAX_BYTES || $file->getSize() < 100) {
            throw new InvalidArgumentException('size');
        }
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);
        if (! isset(self::TYPES[$mime]) || $info === false || $info[2] !== self::TYPES[$mime]) {
            throw new InvalidArgumentException('type');
        }
        [$width, $height] = $info;
        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException('dimensions');
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };
        if ($image === false) {
            throw new InvalidArgumentException('type');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $image = $this->orient($image, (int) (@exif_read_data($path)['Orientation'] ?? 1));
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1.0, self::OUTPUT_SIDE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($tw, $th);
        // Transparent PNG areas become white rather than black.
        imagefilledrectangle($out, 0, 0, $tw, $th, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($image);

        ob_start();
        imagejpeg($out, null, 88);
        $bytes = (string) ob_get_clean();
        imagedestroy($out);

        $id = bin2hex(random_bytes(16));
        Storage::disk('invitations')->put($this->relative($eventId, $id), $bytes);

        return $id;
    }

    /** Absolute path for an event's photo id, or null when it does not exist. */
    public function path(int $eventId, ?string $id): ?string
    {
        if ($id === null || preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            return null;
        }
        $disk = Storage::disk('invitations');

        return $disk->exists($this->relative($eventId, $id)) ? $disk->path($this->relative($eventId, $id)) : null;
    }

    public function delete(int $eventId, ?string $id): void
    {
        if ($id !== null && preg_match('/\A[a-f0-9]{32}\z/', $id) === 1) {
            Storage::disk('invitations')->delete($this->relative($eventId, $id));
        }
    }

    private function relative(int $eventId, string $id): string
    {
        return 'events/'.$eventId.'/photos/'.$id.'.jpg';
    }

    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
