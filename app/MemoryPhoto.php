<?php

namespace App;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MemoryPhoto
{
    public const MAX_PER_MEMORY = 5;

    public const MIME_TYPE = 'image/jpeg';

    /** @return array{contents: resource, latitude: ?float, longitude: ?float} */
    public function prepare(Request $request): array
    {
        return $this->prepareFile($request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:12288'],
        ], [
            'photo.mimes' => 'Choose a JPEG, PNG, or WebP photo.',
            'photo.max' => 'Choose a photo under 12 MB.',
        ])['photo']);
    }

    /** @return list<array{contents: resource, latitude: ?float, longitude: ?float}> */
    public function prepareSelection(Request $request): array
    {
        $files = $request->validate([
            'photos' => ['required', 'array', 'list', 'max:'.self::MAX_PER_MEMORY],
            'photos.*' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:12288'],
        ], [
            'photos.max' => 'Choose up to five photos.',
            'photos.*.mimes' => 'Choose JPEG, PNG, or WebP photos.',
            'photos.*.max' => 'Each photo must be 12 MB or smaller.',
        ])['photos'];

        return array_map($this->prepareFile(...), $files);
    }

    /** @return array{contents: resource, latitude: ?float, longitude: ?float} */
    private function prepareFile(UploadedFile $file): array
    {
        $path = $file->getPathname();

        // Reject oversized images before decoding them into pixel buffers.
        $size = @getimagesize($path);
        if ($size === false || $size[0] * $size[1] > 26000000) {
            throw ValidationException::withMessages(['photo' => 'Choose a readable photo up to 26 megapixels.']);
        }

        $image = @imagecreatefromstring(file_get_contents($path));
        if ($image === false) {
            throw ValidationException::withMessages(['photo' => 'This photo could not be opened. Choose another photo.']);
        }

        // Resize before orienting so rotations only allocate a display-sized buffer.
        $scale = min(1, 1600 / max($size[0], $size[1]));
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));
        $display = imagecreatetruecolor($width, $height);
        imagefill($display, 0, 0, imagecolorallocate($display, 255, 255, 255));
        imagecopyresampled($display, $image, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
        unset($image);

        $exif = $size[2] === IMAGETYPE_JPEG ? (@exif_read_data($path) ?: []) : [];
        if ($size[2] === IMAGETYPE_JPEG) {
            $orientation = (int) ($exif['Orientation'] ?? 1);
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($display, in_array($orientation, [2, 5, 7], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
            }
            $angle = match ($orientation) {
                3 => 180,
                5, 8 => 90,
                6, 7 => -90,
                default => 0,
            };
            if ($angle !== 0) {
                $display = imagerotate($display, $angle, 0);
            }
        }

        $contents = fopen('php://temp', 'w+b');
        if (! imagejpeg($display, $contents, 85) || ftell($contents) === 0) {
            throw new RuntimeException('Could not encode the memory photo.');
        }
        rewind($contents);

        $latitude = $this->coordinate($exif['GPSLatitude'] ?? null, $exif['GPSLatitudeRef'] ?? null, 'N', 'S', 90);
        $longitude = $this->coordinate($exif['GPSLongitude'] ?? null, $exif['GPSLongitudeRef'] ?? null, 'E', 'W', 180);

        return [
            'contents' => $contents,
            'latitude' => $longitude === null ? null : $latitude,
            'longitude' => $latitude === null ? null : $longitude,
        ];
    }

    /** @param array{contents: resource, latitude: ?float, longitude: ?float} $photo */
    public function attach(string $memory, array $photo): void
    {
        DB::table('memory_attachments')->insert([
            'memory_id' => $memory,
            'mime_type' => self::MIME_TYPE,
            ...$photo,
        ]);
    }

    private function coordinate(mixed $parts, mixed $reference, string $positive, string $negative, int $limit): ?float
    {
        if (! is_array($parts) || count($parts) !== 3 || ! in_array($reference, [$positive, $negative], true)) {
            return null;
        }

        $values = [];
        foreach ($parts as $part) {
            if (! is_string($part) || ! preg_match('/\A([0-9]+)\/([0-9]+)\z/', $part, $ratio) || (float) $ratio[2] === 0.0) {
                return null;
            }
            $values[] = (float) $ratio[1] / (float) $ratio[2];
        }

        [$degrees, $minutes, $seconds] = $values;
        $coordinate = $degrees + $minutes / 60 + $seconds / 3600;
        if (! is_finite($coordinate) || $coordinate > $limit || $minutes >= 60 || $seconds >= 60) {
            return null;
        }

        return $reference === $negative ? -$coordinate : $coordinate;
    }
}
