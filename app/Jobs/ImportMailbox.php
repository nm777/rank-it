<?php

namespace App\Jobs;

use App\Imports\Mailbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportMailbox implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public int $owner, public string $batch, public int $position = 0)
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
        $finished = DB::transaction(function () use ($mailbox, $disk) {
            $batch = $this->batchQuery()->lockForUpdate()->first();
            if (! $batch) {
                return false;
            }
            if (! in_array($batch->status, ['Queued', 'Processing'])) {
                return true;
            }
            if ($batch->position !== $this->position) {
                return false;
            }

            $stream = $disk->readStream("$this->owner/$this->batch");
            try {
                [$raw, $next, $end] = $mailbox->read($stream, $batch->position);
            } finally {
                fclose($stream);
            }
            $parsed = $mailbox->parse($raw);
            if ($parsed === null) {
                $batch->unreadable++;
            } else {
                [$message, $body, $attachments] = $parsed;
                $ordinal = $batch->imported + $batch->unreadable + 1;
                $message['identity'] ??= hash('sha256', "$this->batch:$ordinal");
                $message['user_id'] = $this->owner;
                $messages = DB::table('mail_messages');
                $created = $messages->insertOrIgnore($message);
                $id = $messages->where('user_id', $this->owner)->where('identity', $message['identity'])->value('id');
                if ($created) {
                    foreach ($mailbox->passages($body) as $index => $wording) {
                        $quote = preg_match('/^\s*>/', $wording) ? trim(preg_replace('/^[ \t]*> ?/m', '', $wording)) : null;
                        DB::table('mail_passages')->insert(['message_id' => $id, 'ordinal' => $index,
                            'wording' => $wording, 'quote_text' => $quote]);
                    }
                }
                DB::table('mailbox_entries')->insert(['import_id' => $this->batch, 'message_id' => $id,
                    'ordinal' => $ordinal, 'attachments' => json_encode($attachments, JSON_THROW_ON_ERROR)]);
                $batch->imported++;
            }
            $this->batchQuery()->update(['position' => $next, 'imported' => $batch->imported,
                'unreadable' => $batch->unreadable, 'updated_at' => now(),
                'status' => $end ? $this->outcome($batch) : 'Processing']);
            if (! $end) {
                self::dispatch($this->owner, $this->batch, $next);
            }

            return $end;
        });

        if ($finished) {
            $disk->delete("$this->owner/$this->batch");
        }
    }

    private function outcome(object $batch): string
    {
        if ($batch->imported === 0) {
            return 'Could not import';
        }

        return $batch->unreadable ? 'Partially imported' : 'Imported';
    }

    public function failed(?\Throwable $exception): void
    {
        $cleanup = DB::transaction(function () {
            $batch = $this->batchQuery()->lockForUpdate()->first();
            if (! $batch) {
                return false;
            }
            if (in_array($batch->status, ['Queued', 'Processing'])) {
                if ($batch->position !== $this->position) {
                    return false;
                }
                $this->batchQuery()->update(['status' => $batch->imported ? 'Partially imported' : 'Could not import',
                    'updated_at' => now()]);
            }

            return true;
        });
        if ($cleanup) {
            Storage::disk('import-staging')->delete("$this->owner/$this->batch");
        }
    }
}
