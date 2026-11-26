<?php

namespace App\Http\Controllers;

use App\Jobs\DeleteStagedImport;
use App\Jobs\ImportMailbox;
use App\Jobs\IndexPassage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function store(Request $request)
    {
        $multiple = $request->hasFile('files');
        $input = $request->validate($multiple
            ? ['files' => 'required|array|min:1|max:4', 'files.*' => 'required|file|max:16384']
            : ['mailbox' => 'required|file|max:16384']);
        $files = $multiple ? $input['files'] : [$input['mailbox']];
        $owner = $request->user()->id;
        $id = (string) Str::uuid();
        $disk = Storage::disk('import-staging');
        $staged = [];
        try {
            foreach ($files as $file) {
                $fileId = $staged ? (string) Str::uuid() : $id;
                $disk->putFileAs((string) $owner, $file, $fileId);
                $staged[$fileId] = $file->getClientOriginalName();
            }
            DB::transaction(function () use ($owner, $id, $staged) {
                foreach ($staged as $fileId => $filename) {
                    DB::table('mailbox_imports')->insert(['id' => $fileId, 'batch_id' => $id, 'user_id' => $owner,
                        'filename' => $filename, 'created_at' => now(), 'updated_at' => now()]);
                    ImportMailbox::dispatch($owner, $fileId);
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

        $skipped = DB::table('inference_work')->where('passage_id', $selected->id)->where('stage', 'skipped')->exists();

        return view('passage', compact('message', 'selected', 'passages', 'skipped'));
    }

    public function promote(Request $request, int $passage)
    {
        DB::transaction(function () use ($request, $passage) {
            DB::table('mail_passage_sources')->where('user_id', $request->user()->id)->where('id', $passage)->firstOrFail();
            $work = DB::table('inference_work')->where('passage_id', $passage)->where('stage', 'skipped')->lockForUpdate()->first();
            if ($work) {
                DB::table('inference_work')->where('id', $work->id)->update(['stage' => 'embed']);
                IndexPassage::dispatch($work->id);
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
