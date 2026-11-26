<?php

namespace App\Jobs;

use App\Imports\ImportFile;
use App\Imports\Mailbox;
use App\Inference\WorkTransitions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportMailbox implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public int $owner, public string $batch, public int $position = 0, public ?int $restoreMessage = null)
    {
        $this->onConnection('database');
    }

    private function batchQuery()
    {
        return DB::table('mailbox_imports')->where('user_id', $this->owner)->where('id', $this->batch);
    }

    public function handle(Mailbox $mailbox): void
    {
        $disk = Storage::disk('import-staging');
        DB::transaction(function () use ($mailbox, $disk): void {
            DB::table('users')->where('id', $this->owner)->lockForUpdate()->first();
            $batch = $this->batchQuery()->lockForUpdate()->first();
            if (! $batch || ! in_array($batch->status, ['Queued', 'Processing']) || $batch->position !== $this->position) {
                return;
            }

            $stream = $disk->readStream("$this->owner/$this->batch");
            try {
                [$parsed, $next, $end] = (new ImportFile)->read($mailbox, $stream, $disk->path("$this->owner/$this->batch"), $batch);
            } finally {
                fclose($stream);
            }
            $document = ImportFile::isDocument($batch->filename);
            $matchingMessage = $document && $parsed !== null
                ? $this->matchingDocument($batch->filename, $parsed[1])
                : null;
            if ($matchingMessage === false || ($matchingMessage === null && isset($this->restoreMessage))) {
                $this->batchQuery()->update([
                    'status' => isset($this->restoreMessage) ? 'Original does not match' : 'Multiple matching documents',
                    'updated_at' => now(),
                ]);
                DeleteStagedImport::dispatch($this->owner, $this->batch);

                return;
            }
            if ($parsed === null) {
                $batch->unreadable++;
            } else {
                $ordinal = $batch->imported + $batch->unreadable + $batch->excluded + 1;
                if ($this->retain($mailbox, $parsed, $ordinal, $matchingMessage)) {
                    $batch->imported++;
                } else {
                    $batch->excluded++;
                }
            }
            $this->batchQuery()->update(['position' => $next, 'imported' => $batch->imported,
                'unreadable' => $batch->unreadable, 'excluded' => $batch->excluded, 'updated_at' => now(),
                'status' => $matchingMessage !== null && $batch->imported > 0
                    ? 'Matched existing document'
                    : ($end ? $this->outcome($batch) : 'Processing')]);
            if (! $end) {
                self::dispatch($this->owner, $this->batch, $next);
            } elseif ($batch->imported === 0 || ! $document) {
                DeleteStagedImport::dispatch($this->owner, $this->batch);
            }
        });
    }

    private function matchingDocument(string $filename, string $body): int|false|null
    {
        $matches = DB::table('mail_messages as messages')
            ->join('mailbox_entries as entries', 'entries.message_id', '=', 'messages.id')
            ->join('mailbox_imports as files', 'files.id', '=', 'entries.import_id')
            ->where('messages.user_id', $this->owner)
            ->where('files.user_id', $this->owner)
            ->where('files.filename', $filename)
            ->when(isset($this->restoreMessage), fn ($query) => $query->where('messages.id', $this->restoreMessage))
            ->whereRaw('(select string_agg(wording, chr(10) order by ordinal) from mail_passages where message_id = messages.id and document_location is null) = ?', [$body])
            ->get(['messages.id as message_id', 'files.id as file_id'])
            ->groupBy('message_id');

        $disk = Storage::disk('import-staging');
        $withoutOriginal = [];
        $uploadedHash = null;
        foreach ($matches as $messageId => $files) {
            $originals = $files->filter(fn ($file) => $disk->fileExists("$this->owner/$file->file_id"));
            if ($originals->isEmpty()) {
                $withoutOriginal[] = (int) $messageId;
            } elseif (! isset($this->restoreMessage)) {
                $uploadedHash ??= hash_file('sha256', $disk->path("$this->owner/$this->batch"));
                if ($uploadedHash !== false && $originals->contains(fn ($file) => $uploadedHash === hash_file('sha256', $disk->path("$this->owner/$file->file_id")))) {
                    return (int) $messageId;
                }
            }
        }

        return count($withoutOriginal) > 1 ? false : ($withoutOriginal[0] ?? null);
    }

    private function retain(Mailbox $mailbox, array $parsed, int $ordinal, ?int $matchingMessage): bool
    {
        [$message, $body, $attachments] = $parsed;
        $message['identity'] ??= hash('sha256', "$this->batch:$ordinal");
        $message['user_id'] = $this->owner;
        $exclusions = DB::table('import_exclusions')->where('import_id', $this->batch);
        if ((clone $exclusions)->where('identity', $message['identity'])->exists()) {
            return false;
        }
        $messages = DB::table('mail_messages');
        $created = $matchingMessage === null && $messages->insertOrIgnore($message);
        $id = $matchingMessage ?? $messages->where('user_id', $this->owner)->where('identity', $message['identity'])->value('id');
        if ($created) {
            foreach ($mailbox->passages($body) as $index => $wording) {
                $quote = array_key_exists('parent_identity', $message) && preg_match('/^\s*>/', $wording) ? trim(preg_replace('/^[ \t]*> ?/m', '', $wording)) : null;
                if ($quote !== null && isset($message['parent_identity'])
                    && (clone $exclusions)->where('identity', $message['parent_identity'])->exists()) {
                    continue;
                }
                $passageId = DB::table('mail_passages')->insertGetId(['message_id' => $id, 'ordinal' => $index,
                    'wording' => $wording, 'quote_text' => $quote]);
                if ($quote === null && trim($wording) !== '') {
                    IndexPassage::enqueue(['passage_id' => $passageId], 'screen');
                }
            }
        }
        DB::table('mailbox_entries')->insert(['import_id' => $this->batch, 'message_id' => $id,
            'ordinal' => $ordinal, 'attachments' => json_encode($attachments, JSON_THROW_ON_ERROR)]);
        if (ImportFile::supportsTables($message['subject'] ?? '') && ! array_key_exists('parent_identity', $message)) {
            IndexDocumentTables::dispatch($this->owner, $this->batch);
        }
        if ($created) {
            $this->refreshReplies($message['identity']);
        }

        return true;
    }

    private function refreshReplies(string $identity): void
    {
        $replies = DB::table('mail_passage_sources')->where('user_id', $this->owner)
            ->where('parent_identity', $identity)->select('id');
        $work = DB::table('inference_work')->whereIn('passage_id', $replies)->orderBy('id')->lockForUpdate()->get();
        foreach ($work as $reply) {
            WorkTransitions::contextArrived($reply);
        }
    }

    private function outcome(object $batch): string
    {
        if ($batch->excluded && ! $batch->unreadable && ! $batch->imported) {
            return 'Processed';
        }
        if ($batch->imported === 0) {
            return 'Could not import';
        }

        return $batch->unreadable ? 'Partially imported' : 'Imported';
    }

    public function failed(?\Throwable $exception): void
    {
        DB::transaction(function (): void {
            $batch = $this->batchQuery()->lockForUpdate()->first();
            if (! $batch || ! in_array($batch->status, ['Queued', 'Processing']) || $batch->position !== $this->position) {
                return;
            }

            $this->batchQuery()->update(['status' => $batch->imported ? 'Partially imported' : 'Could not import',
                'updated_at' => now()]);
            DeleteStagedImport::dispatch($this->owner, $this->batch);
        });
    }
}
