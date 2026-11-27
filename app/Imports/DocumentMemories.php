<?php

namespace App\Imports;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class DocumentMemories
{
    public function generate(User $owner, int $message): void
    {
        $passages = DB::table('mail_passages')->where('message_id', $message)->whereNotNull('document_location')->get();
        $assignments = [];
        $knownWorkers = [];

        foreach ($passages as $passage) {
            $assignment = $this->assignment($passage->wording);
            if ($assignment === null) {
                continue;
            }
            $assignments[$passage->id] = $assignment;
            foreach ($assignment['workers'] as $worker) {
                if (count($assignment['workers']) > 1 || str_contains($worker, ' ')) {
                    $knownWorkers[$worker] = true;
                }
            }
        }

        foreach ($passages->where('memory_generation_checked', false) as $passage) {
            $assignment = $assignments[$passage->id] ?? null;
            if ($assignment !== null && array_diff($assignment['workers'], array_keys($knownWorkers)) === []) {
                $workers = $assignment['workers'];
                $verb = count($workers) === 1 ? 'was' : 'were';
                $memory = $owner->memories()->make([
                    'wording' => implode(', ', $workers)." $verb scheduled at ".$assignment['site'].' on '.$assignment['date'].'.',
                    'experience_date' => $assignment['date'],
                ]);
                $memory->document_passage_id = $passage->id;
                $memory->save();
            }
        }

        DB::table('mail_passages')->where('message_id', $message)->whereNotNull('document_location')
            ->where('memory_generation_checked', false)->update(['memory_generation_checked' => true]);
    }

    /** @return array{site: string, date: string, workers: list<string>}|null */
    private function assignment(string $wording): ?array
    {
        if (! preg_match('/\A[^|]+\| Row: ([^|]+) \| Column: ([^|]+) \| Value: ([^|]+)\z/u', $wording, $parts)) {
            return null;
        }

        $header = preg_replace('/\A(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s+/i', '', trim($parts[2]));
        $format = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $header) ? 'Y-m-d' : 'F j, Y';
        $date = DateTimeImmutable::createFromFormat('!'.$format, $header);
        if (! $date || strcasecmp($date->format($format), $header) !== 0) {
            return null;
        }

        $site = trim(preg_replace('/\b(?:gar(?:age)?\s+)?code\s*[:#].*\z/iu', '', $parts[1]));
        $workers = preg_split('/\s*,\s*|\s+and\s+/u', trim($parts[3]));
        if ($site === '' || preg_match('/\A(?:off|misc\.?|notes?|goals?)\z/i', $site)
            || count($workers) > 8) {
            return null;
        }
        foreach ($workers as $worker) {
            if (! preg_match('/\A\p{Lu}\p{Ll}+(?:[-’\x27 ]\p{Lu}\p{Ll}+){0,2}\z/u', $worker)) {
                return null;
            }
        }

        return ['site' => $site, 'date' => $date->format('Y-m-d'), 'workers' => array_values(array_unique($workers))];
    }
}
