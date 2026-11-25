<?php

namespace App\Inference;

use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class Providers
{
    public const JEV_RATE = 42; // USD billionths per input token

    private array $embedding;

    public function select(string $profile): void
    {
        $this->embedding = $profile === 'default' ? config('inference') : config('inference.alternate');
    }

    public function configuration(): string
    {
        return json_encode(['provider' => $this->embedding['provider'], 'model' => $this->embedding['model'],
            'dimensions' => (int) $this->embedding['dimensions']], JSON_THROW_ON_ERROR);
    }

    public function rate(): int
    {
        return (int) $this->embedding['rate'];
    }

    public function estimate(string $stage): ?int
    {
        if (in_array($stage, ['screen', 'intent'], true)) {
            $enabled = config('inference.jev_key') && ($stage === 'intent' || config('inference.jev_verified'));

            return $enabled ? 65536 * self::JEV_RATE : null;
        }
        $rate = filter_var($this->embedding['rate'], FILTER_VALIDATE_INT);
        $max = filter_var($this->embedding['max_tokens'], FILTER_VALIDATE_INT);
        if (! $this->embedding['endpoint'] || ! $this->embedding['model'] || ! $this->embedding['provider']
            || (int) $this->embedding['dimensions'] < 1 || $rate === false || $rate < 0 || $max < 1) {
            return null;
        }

        return $rate * $max;
    }

    public function screen(string $wording, array $context): array
    {
        [$scores, $tokens] = $this->jev(
            ['new_wording' => $wording, 'context' => $context],
            ['remember' => 'Does the new wording express or adopt an opinion, rating, ranking, assessment, concrete fact or change worth recalling? Use context only to interpret the new wording.'],
        );

        return [$scores['remember'] >= 0.5, $tokens];
    }

    public function classifyIntent(string $wording): array
    {
        [$scores, $tokens] = $this->jev(['wording' => $wording], [
            'save' => 'Is the user clearly recording a real memory, observation, fact, or opinion, or explicitly asking to save one? Exclude questions, hypotheticals, and unclear fragments.',
            'ask' => 'Is the user clearly asking Rank It to retrieve, compare, or answer something from their memories? Include questions and search requests without a question mark. Exclude statements offered as memories and unclear fragments.',
        ]);

        $choice = null;
        foreach (['save' => 'ask', 'ask' => 'save'] as $intent => $other) {
            if ($scores[$intent] >= 0.8 && $scores[$other] <= 0.2) {
                $choice = $intent;
            }
        }

        return [$choice, $tokens, $scores];
    }

    private function jev(array $state, array $instructions): array
    {
        $questions = [];
        foreach ($instructions as $name => $instruction) {
            $questions[$name] = ['type' => 'noul', 'instructions' => $instruction];
        }

        $response = Http::withoutRedirecting()->timeout(25)->withToken(config('inference.jev_key'))
            ->post('https://api.typesafe.ai/v1/systemone', [
                'model' => 'jev-1.14.0', 'state' => $state, 'questions' => $questions,
            ])->throw()->json();
        $scores = [];
        foreach ($questions as $name => $question) {
            $probability = data_get($response, "answers.$name.noul");
            if (! is_numeric($probability) || $probability < 0 || $probability > 1) {
                throw new UnexpectedValueException('Invalid Jev decision.');
            }
            $scores[$name] = $probability;
        }

        return [$scores, $this->tokens(data_get($response, 'usage.input_tokens'))];
    }

    public function embed(string $wording): array
    {
        $http = Http::withoutRedirecting()->timeout(25);
        if ($key = $this->embedding['key']) {
            $http->withToken($key);
        }
        $response = $http->post($this->embedding['endpoint'], [
            'model' => $this->embedding['model'], 'input' => $wording,
        ])->throw()->json();
        $vector = data_get($response, 'data.0.embedding');
        if (! is_array($vector) || ! array_is_list($vector) || count($vector) !== (int) $this->embedding['dimensions']) {
            throw new UnexpectedValueException('Invalid embedding dimensions.');
        }
        foreach ($vector as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite($value)) {
                throw new UnexpectedValueException('Invalid embedding coordinate.');
            }
        }

        return [$vector, $this->tokens(data_get($response, 'usage.prompt_tokens'))];
    }

    private function tokens(mixed $tokens): int
    {
        if (! is_int($tokens) || $tokens < 0) {
            throw new UnexpectedValueException('Invalid provider usage.');
        }

        return $tokens;
    }
}
