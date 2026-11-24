<?php

namespace App\Inference;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class Ratings
{
    private const NUMBERS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];

    public static function extract(string $wording, ?string $date, ?string $experience = null): array
    {
        $number = '(?:\d+(?:\.\d+)?|'.implode('|', self::NUMBERS).')';
        $ratings = [];
        foreach (preg_split('/[;\n]|[.!?](?!\d)|,?\s+and\s+|,\s*/iu', $wording) as $clause) {
            if (preg_match('/\b(?:not|never|maybe|if|would|might|hypothetically)\b/iu', $clause)) {
                continue;
            }
            if (! preg_match("~^\s*(?:remember (?:this|that)[: ]+)?(?:I (?:rated|rate|gave) )?(.+?)\s+(?:(?:was|is|gets|got|scored)\s+)?(?:an?\s+)?($number)(?:\s*(?:/|out of)\s*($number))?(?=\s|$)~iu", trim($clause), $match)) {
                continue;
            }
            // Bare quantities need an explicit rating verb or scale.
            if (! isset($match[3]) && ! preg_match('/\b(?:rated?|gave|scored)\b|\b(?:is|was|gets|got)\s+an?\s+/i', $match[0])) {
                continue;
            }
            if (preg_match('/^\s*(?:years?|days?|dollars?|minutes?|feet|miles?)\b/i', substr(trim($clause), strlen($match[0])))) {
                continue;
            }
            $subject = preg_replace('/^(?:today|yesterday|last month)\s+/i', '', trim($match[1], " \t:-"));
            $parts = preg_split("/['’]s\s+/u", $subject, 2);
            $value = fn ($n) => is_numeric($n) ? $n : array_search(strtolower($n), self::NUMBERS, true);
            $ratings[] = ['subject' => $parts[0], 'aspect' => $parts[1] ?? '', 'score' => $value($match[2]),
                'scale' => isset($match[3]) ? $value($match[3]) : null,
                'experience' => $experience ?? self::experience($clause, $date)];
        }

        return $ratings;
    }

    public static function experience(string $text, ?string $date): ?string
    {
        if (preg_match('/\b(\d{4})(?:-(\d\d)(?:-(\d\d))?)?\b/', $text, $match)) {
            return checkdate((int) ($match[2] ?? 1), (int) ($match[3] ?? 1), (int) $match[1]) ? $match[0] : null;
        }
        if (! $date || strtotime($date) === false) {
            return null;
        }
        $anchor = new DateTimeImmutable($date);
        if (preg_match('/\blast month\b/i', $text)) {
            return $anchor->modify('first day of last month')->format('Y-m');
        }

        return preg_match('/\b(today|yesterday)\b/i', $text, $m)
            ? $anchor->modify(strtolower($m[1]) === 'today' ? '+0 days' : '-1 day')->format('Y-m-d') : null;
    }

    public static function capture(object $memory): void
    {
        DB::table('ratings')->where('memory_id', $memory->id)->delete();
        foreach (self::extract($memory->wording, (string) $memory->created_at, $memory->experience_date) as $rating) {
            DB::table('ratings')->insert($rating + ['memory_id' => $memory->id]);
        }
    }

    // Called with the work row locked. Deterministic extraction has zero marginal inference cost.
    public static function process(object $work, Budget $budget): void
    {
        if (! $work->passage_id || $work->ratings_processed || ! in_array($work->stage, ['embed', 'indexed'])) {
            return;
        }
        if (! ($call = $budget->reserve('ratings', 0))) {
            return;
        }
        $p = DB::table('mail_passage_sources')->find($work->passage_id);
        foreach (self::extract($p->wording, $p->sent_date) as $rating) {
            DB::table('ratings')->insert($rating + ['passage_id' => $p->id]);
        }
        $agreement = null;
        if (preg_match('/^\s*(?:I agree|agreed)(?:\s+(?:on|about|with)\s+(?:the\s+)?(.+?))?[.,!\s]*$/iu', $p->wording, $m)) {
            $agreement = mb_strtolower($m[1] ?? '');
        }
        DB::table('inference_work')->where('id', $work->id)->update(['ratings_processed' => true, 'agreement' => $agreement]);
        $budget->settle($call, 0, 0);
    }
}
