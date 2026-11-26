<?php

namespace App\Inference;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PassageSources
{
    public static function forOwner(int $owner): Builder
    {
        $quoteGroup = "case when parent_identity is not null and quote_text is not null
            then parent_identity || ':' || quote_text else id::text end";

        return DB::table('mail_passage_sources')
            ->where('user_id', $owner)
            ->whereNull('source_id')
            ->selectRaw("distinct on ($quoteGroup) *")
            ->addSelect(['document_location' => DB::table('mail_passages')
                ->select('document_location')->whereColumn('id', 'mail_passage_sources.id')])
            ->orderByRaw($quoteGroup)
            ->orderBy('id');
    }
}
