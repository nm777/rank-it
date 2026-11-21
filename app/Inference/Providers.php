<?php

namespace App\Inference;

use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class Providers
{
    public const JEV_RATE = 42; // USD billionths per input token

    public function estimate(string $stage): ?int
    {
        if ($stage === 'screen') {
            return config('inference.jev_key') && config('inference.jev_verified') ? 65536 * self::JEV_RATE : null;
        }
        $rate = filter_var(config('inference.rate'), FILTER_VALIDATE_INT);
        $max = filter_var(config('inference.max_tokens'), FILTER_VALIDATE_INT);
        if (! config('inference.endpoint') || ! config('inference.model') || ! config('inference.provider')
            || (int) config('inference.dimensions') < 1 || $rate === false || $rate < 0 || $max < 1) {
            return null;
        }

        return $rate * $max;
    }

    public function screen(string $wording, array $context): array
    {
        $response = Http::withoutRedirecting()->timeout(25)->withToken(config('inference.jev_key'))
            ->post('https://api.typesafe.ai/v1/systemone', [
                'model' => 'jev-1.14.0', 'state' => ['new_wording' => $wording, 'context' => $context],
                'questions' => ['remember' => ['type' => 'noul', 'instructions' => 'Does the new wording express or adopt an opinion, rating, ranking, assessment, concrete fact or change worth recalling? Use context only to interpret the new wording.']],
            ])->throw()->json();
        $probability = data_get($response, 'answers.remember.noul');
        if (! is_numeric($probability) || $probability < 0 || $probability > 1) {
            throw new UnexpectedValueException('Invalid Jev decision.');
        }

        return [$probability >= 0.5, $this->tokens(data_get($response, 'usage.input_tokens'))];
    }

    public function embed(string $wording): array
    {
        $http = Http::withoutRedirecting()->timeout(25);
        if ($key = config('inference.key')) {
            $http->withToken($key);
        }
        $response = $http->post(config('inference.endpoint'), [
            'model' => config('inference.model'), 'input' => $wording,
        ])->throw()->json();
        $vector = data_get($response, 'data.0.embedding');
        if (! is_array($vector) || ! array_is_list($vector) || count($vector) !== (int) config('inference.dimensions')) {
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
