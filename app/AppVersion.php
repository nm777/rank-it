<?php

namespace App;

use Illuminate\Filesystem\Filesystem;

class AppVersion
{
    public static function current(): string
    {
        $releasePath = app()->bootstrapPath('release-id');

        if (is_file($releasePath) && is_readable($releasePath)) {
            $releaseId = trim((string) file_get_contents($releasePath));

            if (preg_match('/\A[a-f0-9]{64}\z/', $releaseId)) {
                return $releaseId;
            }
        }

        return self::sourceHash(base_path());
    }

    public static function sourceHash(string $basePath): string
    {
        $files = new Filesystem;
        $hash = hash_init('sha256');
        hash_update_file($hash, $basePath.'/composer.lock');
        hash_update_file($hash, $basePath.'/bootstrap/app.php');

        foreach (['app', 'config', 'database/migrations', 'public', 'resources', 'routes'] as $directory) {
            foreach ($files->allFiles($basePath.'/'.$directory) as $file) {
                hash_update($hash, $directory.'/'.$file->getRelativePathname()."\0");
                hash_update($hash, hash_file('sha256', $file->getPathname()));
            }
        }

        return hash_final($hash);
    }
}
