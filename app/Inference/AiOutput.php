<?php

namespace App\Inference;

use Illuminate\Http\Client\RequestException;
use Throwable;

class AiOutput
{
    public static function record(string $step, array $output): void
    {
        if (config('app.env') === 'local') {
            request()->attributes->set('ai_output', array_replace(self::current(), [$step => $output]));
        }
    }

    public static function current(): array
    {
        return request()->attributes->get('ai_output', []);
    }

    public static function failure(Throwable $error): array
    {
        return $error instanceof RequestException
            ? ['http_status' => $error->response->status()]
            : ['status' => class_basename($error)];
    }
}
