<?php

namespace App\Http\Controllers;

use App\MemoryPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemoryAttachmentController extends Controller
{
    public function store(Request $request, string $memory, MemoryPhoto $photo): RedirectResponse
    {
        $ownerMemories = $request->user()->memories();
        $ownerMemories->findOrFail($memory);
        $preparedPhoto = $photo->prepare($request);

        DB::transaction(function () use ($ownerMemories, $memory, $photo, $preparedPhoto) {
            $ownerMemories->lockForUpdate()->findOrFail($memory);

            if (DB::table('memory_attachments')->where('memory_id', $memory)->count() >= MemoryPhoto::MAX_PER_MEMORY) {
                throw ValidationException::withMessages(['photo' => 'A memory can have up to five photos. Remove one to add another.']);
            }

            $photo->attach($memory, $preparedPhoto);
        });

        return redirect()->route('memories.show', $memory)->with('status', 'Photo added');
    }

    public function show(Request $request, string $memory, string $attachment): StreamedResponse
    {
        $request->user()->memories()->findOrFail($memory);
        $photo = DB::table('memory_attachments')->where('memory_id', $memory)->where('id', $attachment)->firstOrFail(['mime_type', 'contents']);

        return response()->stream(function () use ($photo) {
            fpassthru($photo->contents);
        }, 200, [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function delete(Request $request, string $memory, string $attachment): RedirectResponse
    {
        $request->user()->memories()->findOrFail($memory);
        abort_unless(DB::table('memory_attachments')->where('memory_id', $memory)->where('id', $attachment)->delete(), 404);

        return redirect()->route('memories.show', $memory)->with('status', 'Photo removed');
    }
}
