<?php

namespace App\Http\Controllers;

use App\Imports\DocumentOriginals;
use App\Imports\ImportFile;
use App\Inference\WorkTransitions;
use App\Jobs\DeleteStagedImport;
use App\Jobs\ImportMailbox;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class MailboxController extends Controller
{
    public function index(Request $request)
    {
        return view('imports', ['imports' => DB::table('mailbox_imports')->where('user_id', $request->user()->id)
            ->whereColumn('id', 'batch_id')
            ->select('mailbox_imports.*')->selectSub(DB::table('mailbox_imports as files')
            ->whereColumn('files.batch_id', 'mailbox_imports.id')->selectRaw('count(*)'), 'file_count')
            ->latest()->paginate(20)]);
    }

    public function queue(Request $request): Response
    {
        $imports = DB::table('mailbox_imports')->where('user_id', $request->user()->id);
        $activeStatuses = ['Queued', 'Processing'];
        $activeBatches = (clone $imports)->select('batch_id')->whereIn('status', $activeStatuses);
        $latestBatch = (clone $imports)->whereColumn('id', 'batch_id')->latest()->orderByDesc('id')->value('id');
        $queue = $imports->where(fn (Builder $query) => $query->whereIn('batch_id', $activeBatches)
            ->orWhere('batch_id', '=', $latestBatch));

        $stages = DB::table('mail_messages')
            ->join('mail_passages', 'mail_passages.message_id', '=', 'mail_messages.id')
            ->join('inference_work', 'inference_work.passage_id', '=', 'mail_passages.id')
            ->leftJoin('inference_calls', 'inference_calls.id', '=', 'inference_work.call_id')
            ->where('mail_messages.user_id', $request->user()->id)
            ->groupBy('inference_work.stage')
            ->selectRaw('inference_work.stage, count(*) as total, max(inference_work.id) as latest_work')
            ->selectRaw('count(*) filter (where inference_calls.actual is null and inference_calls.created_at < ?) as interrupted',
                [now()->subMinutes(2)])
            ->get()->keyBy('stage');
        $interruptedScreening = $stages->get('screen')->interrupted ?? 0;
        $interruptedEmbedding = $stages->get('embed')->interrupted ?? 0;
        $indexing = [
            'screening' => ($stages->get('screen')->total ?? 0) - $interruptedScreening,
            'embedding' => ($stages->get('embed')->total ?? 0) - $interruptedEmbedding,
            'indexed' => $stages->get('indexed')->total ?? 0,
            'skipped' => $stages->get('skipped')->total ?? 0,
            'blocked' => $interruptedScreening + $interruptedEmbedding,
        ];
        $indexingRevision = $stages->max('latest_work');

        $headers = ['Cache-Control' => 'private, no-store'];
        if ($request->expectsJson() && ! $request->boolean('details')) {
            $counts = $queue->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
            $total = $counts->sum();
            $finished = $total - $counts->only($activeStatuses)->sum();

            return new JsonResponse(compact('total', 'finished', 'indexing', 'latestBatch', 'indexingRevision'), headers: $headers);
        }

        $files = $queue->latest()->orderBy('filename')->orderBy('id')
            ->get(['id', 'filename', 'status', 'imported', 'unreadable', 'excluded']);
        $total = $files->count();
        $finished = $total - $files->whereIn('status', $activeStatuses)->count();

        if ($request->expectsJson()) {
            return new JsonResponse([
                'total' => $total,
                'finished' => $finished,
                'indexing' => $indexing,
                'latestBatch' => $latestBatch,
                'indexingRevision' => $indexingRevision,
                'details' => view('import-queue-files', compact('files'))->render(),
            ], headers: $headers);
        }

        return response()->view('import-queue', compact('files', 'total', 'finished', 'indexing'), headers: $headers);
    }

    public function store(Request $request)
    {
        $restoreMessage = $request->validate(['restore_message' => ['nullable', 'integer', 'min:1']])['restore_message'] ?? null;
        $multiple = $request->hasFile('files');
        $input = $request->validate($multiple
            ? ['files' => 'required|array|min:1|max:100', 'files.*' => 'required|file|max:16384']
            : ['mailbox' => 'required|file|max:16384']);
        $files = $multiple ? $input['files'] : [$input['mailbox']];
        $owner = $request->user()->id;
        if ($restoreMessage !== null) {
            abort_if($multiple || ! $this->filesForMessage($owner, $restoreMessage)->exists(), 404);
        }
        $id = (string) Str::uuid();
        $disk = Storage::disk('import-staging');
        $staged = [];
        try {
            foreach ($files as $file) {
                $fileId = $staged ? (string) Str::uuid() : $id;
                $disk->putFileAs((string) $owner, $file, $fileId);
                $staged[$fileId] = $file->getClientOriginalName();
            }
            DB::transaction(function () use ($owner, $id, $staged, $restoreMessage) {
                foreach ($staged as $fileId => $filename) {
                    DB::table('mailbox_imports')->insert(['id' => $fileId, 'batch_id' => $id, 'user_id' => $owner,
                        'filename' => $filename, 'created_at' => now(), 'updated_at' => now()]);
                    ImportMailbox::dispatch($owner, $fileId, restoreMessage: $restoreMessage);
                }
            });
        } catch (\Throwable $e) {
            foreach (array_keys($staged) as $fileId) {
                $disk->delete("$owner/$fileId");
            }
            throw $e;
        }

        return redirect()->route('imports.show', $id)->with('status', 'Upload complete. You can close this tab.');
    }

    public function show(Request $request, string $batch)
    {
        $import = DB::table('mailbox_imports')->where('user_id', $request->user()->id)->where('id', $batch)->firstOrFail();
        $attachments = DB::table('mailbox_entries')->where('import_id', $batch)->orderBy('ordinal')
            ->pluck('attachments')->flatMap(fn ($names) => json_decode($names, true));

        $pending = DB::table('mailbox_entries')->join('mail_passages', 'mail_passages.message_id', '=', 'mailbox_entries.message_id')
            ->join('inference_work', 'inference_work.passage_id', '=', 'mail_passages.id')
            ->where('import_id', $batch)->whereIn('stage', ['screen', 'embed'])->exists();

        $files = DB::table('mailbox_imports')->where('user_id', $request->user()->id)->where('batch_id', $import->batch_id)->get();
        $qualified = DB::table('passage_rechecks')->join('inference_work', 'work_id', '=', 'inference_work.id')
            ->where('batch_id', $import->batch_id)->where('qualified', true)->pluck('passage_id');

        return view('import', compact('import', 'attachments', 'pending', 'files', 'qualified'));
    }

    public function passage(Request $request, int $passage)
    {
        $sources = DB::table('mail_passage_sources')->where('user_id', $request->user()->id);
        $selected = (clone $sources)->where('id', $passage)->firstOrFail();
        $message = DB::table('mail_messages')->where('user_id', $request->user()->id)->where('id', $selected->message_id)->firstOrFail();
        $passages = $sources->where('message_id', $message->id)->orderBy('ordinal')->get();
        $owner = $request->user()->id;
        $file = $this->originalForMessage($owner, $message->id);
        $extension = strtolower(pathinfo($file->filename ?? '', PATHINFO_EXTENSION));
        $isDocument = $file !== null && ImportFile::isDocument($file->filename);
        $isSpreadsheet = in_array($extension, ['xlsx', 'xls'], true);
        $hasOriginal = $file !== null && DocumentOriginals::available($owner, $file);

        $skipped = DB::table('inference_work')->where('passage_id', $selected->id)->where('stage', 'skipped')->exists();

        return view('passage', compact('message', 'selected', 'passages', 'skipped', 'isDocument', 'isSpreadsheet', 'hasOriginal'));
    }

    public function downloadOriginal(Request $request, int $message): BinaryFileResponse
    {
        $owner = $request->user()->id;
        $file = $this->originalForMessage($owner, $message);
        abort_unless($file !== null && DocumentOriginals::available($owner, $file), 404);

        $disk = Storage::disk('import-staging');
        $path = "$owner/$file->id";

        return response()->download($disk->path($path), $file->filename, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate();
    }

    private function originalForMessage(int $owner, int $message): ?object
    {
        $files = $this->filesForMessage($owner, $message)
            ->latest('files.created_at')->orderByDesc('files.id')->get(['files.id', 'files.filename']);

        return $files->first(fn ($file) => DocumentOriginals::available($owner, $file))
            ?? $files->first();
    }

    private function filesForMessage(int $owner, int $message): Builder
    {
        return DocumentOriginals::files($owner)->where('entries.message_id', $message);
    }

    public function promote(Request $request, int $passage)
    {
        DB::transaction(function () use ($request, $passage) {
            DB::table('mail_passage_sources')->where('user_id', $request->user()->id)->where('id', $passage)->firstOrFail();
            $work = DB::table('inference_work')->where('passage_id', $passage)->where('stage', 'skipped')->lockForUpdate()->first();
            if ($work) {
                WorkTransitions::promote($work->id);
            }
        });

        return redirect()->route('passages.show', $passage);
    }

    public function deleteMessage(Request $request, int $message)
    {
        $owner = $request->user()->id;
        DB::transaction(function () use ($owner, $message) {
            DB::table('users')->where('id', $owner)->lockForUpdate()->first();
            $original = DB::table('mail_messages')->where('user_id', $owner)->where('id', $message)->firstOrFail();
            $files = $this->filesForMessage($owner, $message)->get(['files.id', 'files.filename']);
            foreach ($files as $file) {
                if (ImportFile::isDocument($file->filename)) {
                    DeleteStagedImport::dispatch($owner, $file->id);
                }
            }
            $pending = DB::table('mailbox_imports')->where('user_id', $owner)->whereIn('status', ['Queued', 'Processing']);
            foreach ($pending->pluck('id') as $id) {
                DB::table('import_exclusions')->insertOrIgnore(['import_id' => $id, 'identity' => $original->identity]);
            }
            DB::table('mail_passages')->whereIn('id', DB::table('mail_passage_sources')->where('user_id', $owner)
                ->where('parent_identity', $original->identity)->whereNotNull('source_id')->select('id'))->delete();
            DB::table('mail_messages')->where('id', $message)->delete();
        });

        return redirect()->route('imports.index')->with('evidence_changed', true);
    }

    public function remove(Request $request, string $batch)
    {
        $owner = $request->user()->id;
        DB::transaction(function () use ($owner, $batch): void {
            DB::table('users')->where('id', $owner)->lockForUpdate()->first();
            $files = DB::table('mailbox_imports')->where('user_id', $owner)->where('batch_id', $batch);
            abort_unless($files->exists(), 404);
            $ids = $files->pluck('id');
            DB::table('passage_rechecks')->where('batch_id', $batch)->delete();
            $files->delete();
            DB::table('mail_messages')->where('user_id', $owner)->whereNotIn('id', DB::table('mailbox_entries')->select('message_id'))->delete();

            foreach ($ids as $id) {
                DeleteStagedImport::dispatch($owner, $id);
            }
        });

        return redirect()->route('imports.index')->with('evidence_changed', true);
    }
}
