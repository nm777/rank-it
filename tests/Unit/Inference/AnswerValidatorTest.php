<?php

namespace Tests\Unit\Inference;

use App\Inference\AnswerValidator;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class AnswerValidatorTest extends TestCase
{
    #[TestWith([null])]
    #[TestWith([['claims' => 'invalid', 'partial' => false]])]
    #[TestWith([['claims' => [], 'partial' => 1]])]
    public function test_malformed_drafts_are_rejected(mixed $draft): void
    {
        $this->assertNull(AnswerValidator::validate($draft, 'Who was hired?', []));
    }

    public function test_only_exact_visible_quotes_are_accepted_and_unsupported_details_mark_the_answer_partial(): void
    {
        $draft = [
            'claims' => [
                ['source' => 'memory:1', 'quote' => 'Hired Lee.', 'match' => 'Lee'],
                ['source' => 'memory:1', 'quote' => 'Hired Pat.'],
                ['source' => 'memory:1', 'quote' => ' '],
                ['source' => 'memory:2', 'quote' => 'Hired Lee.'],
            ],
            'partial' => false,
            'missing' => 'Pat',
            'conflict' => true,
            'clarification' => ['memory:2', 'memory:1', 'memory:1', 1],
        ];

        $answer = AnswerValidator::validate($draft, 'Who was hired?', [
            'memory:1' => ['wording' => 'Hired Lee.', 'date' => null, 'experience' => null],
        ]);

        $this->assertSame([
            'claims' => [['key' => 'memory:1', 'quote' => 'Hired Lee.']],
            'partial' => true,
            'missing' => null,
            'conflict' => false,
            'uncertain' => [],
            'clarification' => ['memory:1'],
            'confirmedCount' => 1,
        ], $answer);
    }

    public function test_experience_dates_filter_nonmatches_and_move_imprecise_or_unknown_dates_to_uncertain(): void
    {
        $sources = [
            'memory:1' => ['wording' => 'Hired Lee.', 'date' => '2025-04-03', 'experience' => '2025-03-02'],
            'memory:2' => ['wording' => 'Hired Pat.', 'date' => '2025-03-05', 'experience' => '2025'],
            'memory:3' => ['wording' => 'Hired Rae.', 'date' => '2025-03-08', 'experience' => '2025-02'],
            'memory:4' => ['wording' => 'Hired Sam.', 'date' => '2025-03-09', 'experience' => null],
        ];
        $draft = [
            'claims' => [
                ['source' => 'memory:1', 'quote' => 'Hired Lee.', 'match' => 'Lee'],
                ['source' => 'memory:2', 'quote' => 'Hired Pat.', 'match' => 'Pat'],
                ['source' => 'memory:3', 'quote' => 'Hired Rae.', 'match' => 'Rae'],
                ['source' => 'memory:4', 'quote' => 'Hired Sam.', 'match' => 'Sam'],
            ],
            'partial' => false,
        ];

        $answer = AnswerValidator::validate($draft, 'Who was hired in 2025-03?', $sources);

        $this->assertSame([['key' => 'memory:1', 'quote' => 'Hired Lee.']], $answer['claims']);
        $this->assertSame([
            ['key' => 'memory:2', 'quote' => 'Hired Pat.'],
            ['key' => 'memory:4', 'quote' => 'Hired Sam.'],
        ], $answer['uncertain']);
        $this->assertSame(1, $answer['confirmedCount']);
    }

    #[TestWith(['LEE', 1])]
    #[TestWith(['Pat', null])]
    #[TestWith([null, null])]
    public function test_capture_date_and_quote_backed_matches_determine_the_confirmed_count(?string $match, ?int $count): void
    {
        $sources = [
            'memory:1' => ['wording' => 'Hired Lee.', 'date' => '2025-03-05', 'experience' => '2024'],
            'memory:2' => ['wording' => 'LEE was hired.', 'date' => '2025-03-07', 'experience' => null],
            'memory:3' => ['wording' => 'Possibly hired Pat.', 'date' => '2025-03-08', 'experience' => '2025-03'],
        ];
        $draft = [
            'claims' => [
                ['source' => 'memory:1', 'quote' => 'Hired Lee.', 'match' => ' Lee'],
                ['source' => 'memory:2', 'quote' => 'LEE was hired.', 'match' => $match],
            ],
            'uncertain' => [['source' => 'memory:3', 'quote' => 'Possibly hired Pat.', 'match' => 'Pat']],
            'partial' => true,
            'missing' => 'job titles',
            'conflict' => true,
        ];

        $answer = AnswerValidator::validate($draft, 'List hires recorded in 2025-03 and their job titles', $sources);

        $this->assertSame([
            'claims' => [
                ['key' => 'memory:1', 'quote' => 'Hired Lee.'],
                ['key' => 'memory:2', 'quote' => 'LEE was hired.'],
            ],
            'partial' => true,
            'missing' => 'job titles',
            'conflict' => true,
            'uncertain' => [['key' => 'memory:3', 'quote' => 'Possibly hired Pat.']],
            'clarification' => [],
            'confirmedCount' => $count,
        ], $answer);
    }
}
