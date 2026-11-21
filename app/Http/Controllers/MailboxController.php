<?php

namespace App\Http\Controllers;

use App\Jobs\ImportMailbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MailboxController extends Controller
{
    public function index(Request $request)
    {
        return view('imports', ['imports' => DB::table('mailbox_imports')->where('user_id', $request->user()->id)
            ->latest()->paginate(20)]);
    }

    public function store(Request $request)
    {
        $file = $request->validate(['mailbox' => ['required', 'file', 'max:16384']])['mailbox'];
        $owner = $request->user()->id;
        $id = (string) Str::uuid();
        $disk = Storage::disk('import-staging');
        try {
            $disk->putFileAs((string) $owner, $file, $id);
            DB::transaction(function () use ($owner, $id, $file) {
                DB::table('mailbox_imports')->insert(['id' => $id, 'user_id' => $owner,
                    'filename' => $file->getClientOriginalName(), 'created_at' => now(), 'updated_at' => now()]);
                ImportMailbox::dispatch($owner, $id);
            });
        } catch (\Throwable $e) {
            $disk->delete("$owner/$id");
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

        return view('import', compact('import', 'attachments', 'pending'));
    }

    public function passage(Request $request, int $passage)
    {
        $sources = DB::table('mail_passage_sources')->where('user_id', $request->user()->id);
        $selected = (clone $sources)->where('id', $passage)->firstOrFail();
        $message = DB::table('mail_messages')->where('user_id', $request->user()->id)->where('id', $selected->message_id)->firstOrFail();
        $passages = $sources->where('message_id', $message->id)->orderBy('ordinal')->get();

        return view('passage', compact('message', 'selected', 'passages'));
    }
}
