<?php

namespace App;

use Illuminate\Support\Facades\File;

class AppVersion
{
    public static function current(): string
    {
        $hash = hash_init('sha256');
        hash_update_file($hash, base_path('composer.lock'));
        hash_update_file($hash, base_path('bootstrap/app.php'));

        foreach (['app', 'config', 'database/migrations', 'public', 'resources', 'routes'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                hash_update($hash, $directory.'/'.$file->getRelativePathname()."\0");
                hash_update($hash, hash_file('sha256', $file->getPathname()));
            }
        }

        return hash_final($hash);
    }
}
