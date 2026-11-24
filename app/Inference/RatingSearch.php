<?php

namespace App\Inference;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class RatingSearch
{
    public static function address(?string $sender): ?string
    {
        return preg_match('/<([^<>]+)>/', $sender ?? '', $m) ? self::address($m[1])
            : (filter_var($sender, FILTER_VALIDATE_EMAIL) ? strtolower($sender) : null);
    }

    public function ask(int $owner, string $question): ?array
    {
        if (! preg_match('/\b(?:ratings?|rate[ds]?|scores?|shortlist)\b|\d\s*\/\s*\d/i', $question)) {
            return null;
        }
        $memoryVersion = Conversation::fingerprint('memory', 'm.');
        $originalVersion = Conversation::fingerprint('passage', 'original.');
        $replyVersion = Conversation::fingerprint('passage', 'p.');
        $rows = DB::select(<<<SQL
            with originals as (
                select r.*, p.message_id as original_message from ratings r
                left join mail_passage_sources p on p.id=r.passage_id
                left join memories m on m.id=r.memory_id where coalesce(p.user_id,m.user_id)=?
            ), evidence as (
                select r.*, null::bigint as reply from originals r
                union all
                select r.*, p.id from originals r join mail_messages parent on parent.id=r.original_message
                join mail_passage_sources p on p.user_id=parent.user_id and p.parent_identity=parent.identity
                join inference_work w on w.passage_id=p.id
                where not w.context_changed and (w.agreement='' or w.agreement=lower(r.subject))
            )
            select r.*, case when r.memory_id is not null then $memoryVersion else $originalVersion end as source_version,
                $replyVersion as reply_version, p.sender, p.sent_date, p.message_id, msg.authored_by_me, m.created_at, case when lower(r.subject)=lower(m.identified_subject) then m.identified_person end as identified_person
            from evidence r left join mail_passage_sources original on original.id=r.passage_id
            left join mail_passage_sources p on p.id=coalesce(r.reply,r.passage_id)
            left join mail_messages msg on msg.id=p.message_id left join memories m on m.id=r.memory_id
            order by r.id,r.reply
            SQL, [$owner]);
        $addresses = DB::table('personal_addresses')->where('user_id', $owner)->pluck('address')->all();
        $personal = preg_match('/\b(?:my|I|me)\b/i', $question);
        $historical = preg_match('/\b(?:ever|history|historical|when|gave|given|saved)\b/i', $question);
        $period = preg_match('/\b(\d{4}(?:-\d\d(?:-\d\d)?)?)\b/', $question, $m) ? $m[1] : null;
        if (preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/i', $question, $m)) {
            $period = (new DateTimeImmutable("1 $m[1] $m[2]"))->format('Y-m');
        }
        $threshold = preg_match('/(?:(at least|above|over|more than|under|below)\s+)?(\d+(?:\.\d+)?)(?:\s*(?:\/|out of)\s*(\d+))?/i', preg_replace('/\b\d{4}(?:-\d\d)*\b/', '', $question), $m);
        [$operator, $score, $scale] = [$m[1] ?? '', $m[2] ?? null, $m[3] ?? null];
        $named = [];
        $linked = [];
        $aspects = [];
        $authors = [];
        foreach ($rows as $row) {
            if ($row->aspect && mb_stripos($question, $row->aspect) !== false) {
                $aspects[] = $row->aspect;
            }
            $sender = trim(explode('<', $row->sender ?? '')[0], ' "');
            if ($sender && mb_stripos($question, $sender) !== false) {
                $authors[] = $row->sender;
            }
            foreach (array_filter([$row->identified_person, $row->subject]) as $name) {
                if (mb_stripos($question, $name) !== false) {
                    $named[] = $name;
                    if ($name === $row->identified_person) {
                        $linked[] = $name;
                    }
                }
            }
        }
        $groups = $confirmed = $uncertain = $identities = [];
        foreach ($rows as $row) {
            if (($aspects && ! in_array($row->aspect, $aspects, true)) || ($authors && ! in_array($row->sender, $authors, true))) {
                continue;
            }
            if ($linked && ! in_array($row->identified_person, $linked, true)) {
                continue;
            }
            if ($named && ! in_array($row->identified_person, $named, true) && ! in_array($row->subject, $named, true)) {
                continue;
            }
            $row->address = self::address($row->sender);
            $row->mine = $row->memory_id || ($row->authored_by_me ?? in_array($row->address, $addresses, true));
            if ($personal && ! $row->mine && $row->authored_by_me === false) {
                continue;
            }
            $row->date = $row->experience;
            if (preg_match('/\b(?:gave|give|given|expressed|saved)\b/i', $question) || preg_match('/\brate(?:d)?\b.*\bin\b/i', $question)) {
                $stamp = $row->created_at ?? $row->sent_date;
                $row->date = $stamp && strtotime($stamp) !== false ? (new DateTimeImmutable($stamp))->format('Y-m-d') : null;
            }
            if (! $row->memory_id && preg_match('/\bsaved\b/i', $question)) {
                continue;
            }
            $group = json_encode([mb_strtolower($row->identified_person ?? $row->subject), mb_strtolower($row->aspect),
                $row->mine ? 'self' : ($personal ? $row->message_id : ($row->address ?? $row->message_id))]);
            $groups[$group][] = $row;
        }
        foreach ($groups as $group) {
            foreach ($group as $row) {
                $ambiguous = false;
                if (! $historical && ! $period) {
                    foreach ($group as $other) {
                        if ($row === $other) {
                            continue;
                        }
                        $precision = min(strlen($row->experience ?? ''), strlen($other->experience ?? ''));
                        $order = strncmp($other->experience ?? '', $row->experience ?? '', $precision);
                        if ($order > 0) {
                            continue 2;
                        }
                        $ambiguous = $ambiguous || $order === 0;
                    }
                }
                if ($threshold && (($scale !== null && $row->scale != $scale) || ! match (strtolower($operator)) {
                    'above', 'over', 'more than' => $row->score > $score,
                    'under', 'below' => $row->score < $score,
                    'at least' => $row->score >= $score,
                    default => preg_match('/or (?:higher|better|above|more)|and (?:above|up)|\+/i', $question) ? $row->score >= $score : $row->score == $score,
                })) {
                    continue;
                }
                if ($period && strncmp($period, $row->date ?? '', min(strlen($period), strlen($row->date ?? ''))) !== 0) {
                    continue;
                }
                if ($personal && ! $row->mine) {
                    $identities[$row->message_id] = $row;
                } elseif ($ambiguous || ($period && strlen($row->date ?? '') < strlen($period))) {
                    $uncertain[] = $row;
                } else {
                    $confirmed[] = $row;
                }
            }
        }
        $partial = DB::table('inference_work as w')->join('mail_passage_sources as p', 'p.id', '=', 'w.passage_id')
            ->where('p.user_id', $owner)->where('w.stage', '!=', 'skipped')->where(fn ($q) => $q->where('w.ratings_processed', false)->orWhere('w.context_changed', true))->exists()
            || DB::table('mailbox_imports')->where('user_id', $owner)->whereIn('status', ['Queued', 'Processing', 'Partially imported', 'Could not import'])->exists();

        return compact('confirmed', 'uncertain', 'identities', 'partial', 'question');
    }
}
