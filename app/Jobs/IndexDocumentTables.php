<?php

namespace App\Jobs;

use App\Imports\DocumentOriginals;
use App\Imports\DocumentTablePassages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class IndexDocumentTables implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $owner, public string $file)
    {
        $this->onConnection('database')->beforeCommit();
    }

    public function handle(DocumentTablePassages $extractor): void
    {
        $entry = DocumentOriginals::files($this->owner)->where('files.id', $this->file);
        $document = (clone $entry)->first(['files.id', 'files.filename', 'entries.message_id']);
        if ($document === null || ! DocumentOriginals::available($this->owner, $document)) {
            return;
        }

        $passages = DB::table('mail_passages')->where('message_id', $document->message_id);
        if ((clone $passages)->whereNotNull('document_location')->exists()) {
            return;
        }

        $path = Storage::disk('import-staging')->path("$this->owner/$this->file");
        $derived = $extractor->extract($document->filename, $path);
        if ($derived === []) {
            return;
        }

        DB::transaction(function () use ($entry, $document, $passages, $derived): void {
            DB::table('users')->where('id', $this->owner)->lockForUpdate()->first();
            if (! $entry->where('entries.message_id', $document->message_id)->exists()
                || (clone $passages)->whereNotNull('document_location')->exists()) {
                return;
            }

            $ordinal = (int) $passages->max('ordinal');
            foreach ($derived as $passage) {
                $id = DB::table('mail_passages')->insertGetId($passage + [
                    'message_id' => $document->message_id,
                    'ordinal' => ++$ordinal,
                ]);
                IndexPassage::enqueue(['passage_id' => $id, 'ratings_processed' => true], 'embed');
            }
        });
    }
}
