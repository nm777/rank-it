<?php

namespace App\Inference;

class AnswerValidator
{
    /**
     * @param  array<string, array{wording: string, date: ?string, experience: ?string}>  $passages
     * @return array{summary: ?string, claims: list<array{key: string, quote: string}>, partial: bool, missing: ?string, conflict: bool, uncertain: list<array{key: string, quote: string}>, clarification: list<string>, confirmedCount: ?int}|null
     */
    public static function validate(mixed $draft, string $question, array $passages): ?array
    {
        if (! is_array($draft['claims'] ?? null) || ! is_bool($draft['partial'] ?? null)) {
            return null;
        }
        $claims = [];
        $partial = $draft['partial'];
        $uncertain = $matchesFound = [];
        $period = preg_match('/\b(?:in|during|on)\s+(\d{4}(?:-\d{2}(?:-\d{2})?)?)\b/i', $question, $matches) ? $matches[1] : null;
        $captureDate = preg_match('/\b(?:saved|captured|recorded)\b/i', $question);
        foreach (['claims', 'uncertain'] as $group) {
            foreach ((array) ($draft[$group] ?? []) as $claim) {
                $key = $claim['source'] ?? null;
                $quote = $claim['quote'] ?? null;
                if (! is_string($key) || ! isset($passages[$key]) || ! is_string($quote) || trim($quote) === ''
                    || ! str_contains($passages[$key]['wording'], $quote)) {
                    $partial = true;

                    continue;
                }
                $date = $captureDate ? $passages[$key]['date'] : $passages[$key]['experience'];
                $dateUncertain = false;
                if ($period) {
                    if ($date && strncmp($period, $date, min(strlen($period), strlen($date))) !== 0) {
                        continue;
                    }
                    $dateUncertain = strlen($date ?? '') < strlen($period);
                }
                if ($group === 'claims' && ! $dateUncertain) {
                    $claims[] = compact('key', 'quote');
                    $match = $claim['match'] ?? null;
                    $matchesFound[] = is_string($match) && trim($match) !== '' && str_contains($quote, $match)
                        ? mb_strtolower(trim($match)) : null;
                } else {
                    $uncertain[] = compact('key', 'quote');
                }
            }
        }
        $clarification = array_values(array_intersect(array_keys($passages), array_filter((array) ($draft['clarification'] ?? []), 'is_string')));
        $conflict = ($draft['conflict'] ?? false) === true && count(array_unique(array_column($claims, 'key'))) >= 2;
        $missing = $draft['missing'] ?? null;
        if (! is_string($missing) || ! str_contains($question, $missing)) {
            $missing = null;
        }

        $confirmedCount = in_array(null, $matchesFound, true) ? null : count(array_unique($matchesFound));

        $summary = is_string($draft['summary'] ?? null) ? trim($draft['summary']) : '';
        if ($summary === '' || ! $claims || count($claims) !== count($draft['claims']) || count($clarification) > 1) {
            $summary = null;
        }

        return compact('summary', 'claims', 'partial', 'missing', 'conflict', 'uncertain', 'clarification', 'confirmedCount');
    }
}
