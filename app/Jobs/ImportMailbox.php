<?php

namespace App\Jobs;

use App\Imports\ImportFile;
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
            if ($parsed === null) {
                $batch->unreadable++;
            } else {
                $ordinal = $batch->imported + $batch->unreadable + $batch->excluded + 1;
                if ($this->retain($mailbox, $parsed, $ordinal)) {
                    $batch->imported++;
                } else {
                    $batch->excluded++;
                }
            }
            $this->batchQuery()->update(['position' => $next, 'imported' => $batch->imported,
                'unreadable' => $batch->unreadable, 'excluded' => $batch->excluded, 'updated_at' => now(),
                'status' => $end ? $this->outcome($batch) : 'Processing']);
            if ($end) {
                DeleteStagedImport::dispatch($this->owner, $this->batch);
            } else {
                self::dispatch($this->owner, $this->batch, $next);
            }
        });
    }

    private function retain(Mailbox $mailbox, array $parsed, int $ordinal): bool
    {
        [$message, $body, $attachments] = $parsed;
        $message['identity'] ??= hash('sha256', "$this->batch:$ordinal");
        $message['user_id'] = $this->owner;
        $exclusions = DB::table('import_exclusions')->where('import_id', $this->batch);
        if ((clone $exclusions)->where('identity', $message['identity'])->exists()) {
            return false;
        }
        $messages = DB::table('mail_messages');
        $created = $messages->insertOrIgnore($message);
        $id = $messages->where('user_id', $this->owner)->where('identity', $message['identity'])->value('id');
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
            if ($reply->stage === 'screen' && ! $reply->call_id) {
                continue;
            }
            $change = $reply->stage === 'skipped' ? ['stage' => 'screen'] : ['context_changed' => true];
            DB::table('inference_work')->where('id', $reply->id)->update($change);
            IndexPassage::dispatch($reply->id);
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
