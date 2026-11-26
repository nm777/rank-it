<?php

namespace App\Imports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentOriginals
{
    public static function files(int $owner): Builder
    {
        return DB::table('mailbox_entries as entries')
            ->join('mailbox_imports as files', 'files.id', '=', 'entries.import_id')
            ->where('files.user_id', $owner);
    }

    public static function available(int $owner, object $file): bool
    {
        return ImportFile::isDocument($file->filename)
            && Storage::disk('import-staging')->fileExists("$owner/$file->id");
    }

    /**
     * @param  list<int|string>  $passageIds
     * @return array<int, int>
     */
    public static function forPassages(int $owner, array $passageIds): array
    {
        if ($passageIds === []) {
            return [];
        }

        return self::files($owner)
            ->join('mail_passages as passages', 'passages.message_id', '=', 'entries.message_id')
            ->whereIn('passages.id', $passageIds)
            ->get(['files.id', 'files.filename', 'passages.id as passage_id', 'entries.message_id'])
            ->filter(fn ($file) => self::available($owner, $file))
            ->pluck('message_id', 'passage_id')->all();
    }

    /**
     * @param  list<string>  $sourceKeys
     * @return array<int, int>
     */
    public static function forSources(int $owner, array $sourceKeys): array
    {
        $passageIds = [];
        foreach ($sourceKeys as $key) {
            if (str_starts_with($key, 'passage:')) {
                $passageIds[] = substr($key, 8);
            }
        }

        return self::forPassages($owner, $passageIds);
    }
}
