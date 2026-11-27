<?php

namespace App\Jobs;

use App\Imports\DocumentMemories;
use App\Imports\DocumentOriginals;
use App\Imports\DocumentTablePassages;
use App\Models\User;
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

    public function handle(DocumentTablePassages $extractor, DocumentMemories $memories): void
    {
        $entry = DocumentOriginals::files($this->owner)->where('files.id', $this->file);
        $document = (clone $entry)->first(['files.id', 'files.filename', 'entries.message_id']);
        if ($document === null || ! DocumentOriginals::available($this->owner, $document)) {
            return;
        }

        $passages = DB::table('mail_passages')->where('message_id', $document->message_id);
        $alreadyExtracted = (clone $passages)->whereNotNull('document_location')->exists();
        if ($alreadyExtracted && ! (clone $passages)->whereNotNull('document_location')->where('memory_generation_checked', false)->exists()) {
            return;
        }
        $path = Storage::disk('import-staging')->path("$this->owner/$this->file");
        $derived = $alreadyExtracted ? [] : $extractor->extract($document->filename, $path);

        DB::transaction(function () use ($entry, $document, $passages, $derived, $memories): void {
            $owner = User::query()->lockForUpdate()->find($this->owner);
            if ($owner === null || ! $entry->where('entries.message_id', $document->message_id)->exists()) {
                return;
            }

            if (! (clone $passages)->whereNotNull('document_location')->exists()) {
                $ordinal = (int) $passages->max('ordinal');
                foreach ($derived as $passage) {
                    $id = DB::table('mail_passages')->insertGetId($passage + [
                        'message_id' => $document->message_id,
                        'ordinal' => ++$ordinal,
                    ]);
                    IndexPassage::enqueue(['passage_id' => $id, 'ratings_processed' => true], 'embed');
                }
            }
            $memories->generate($owner, $document->message_id);
        });
    }
}
