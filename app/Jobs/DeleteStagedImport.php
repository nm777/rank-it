<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;

class DeleteStagedImport implements ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public $backoff = [10, 30, 60, 120];

    public function __construct(public int $owner, public string $file)
    {
        $this->onConnection('database')->beforeCommit();
    }

    public function handle(): void
    {
        $path = "$this->owner/$this->file";
        if (! Storage::disk('import-staging')->delete($path)) {
            throw UnableToDeleteFile::atLocation($path);
        }
    }
}
