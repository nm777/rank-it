<?php

return [
    'answer' => [
        'endpoint' => env('ANSWER_ENDPOINT'),
        'model' => env('ANSWER_MODEL'),
        'key' => env('ANSWER_API_KEY'),
        'input_rate' => env('ANSWER_NANOUSD_PER_INPUT_TOKEN'),
        'output_rate' => env('ANSWER_NANOUSD_PER_OUTPUT_TOKEN'),
        'max_input' => env('ANSWER_MAX_INPUT_TOKENS'),
        'max_output' => env('ANSWER_MAX_OUTPUT_TOKENS'),
    ],
    'cap' => env('INFERENCE_CAP_NANOUSD', 0),
    'reserve' => env('INFERENCE_ANSWER_RESERVE_NANOUSD', 0),
    'jev_key' => env('TYPESAFE_API_KEY'),
    'jev_verified' => env('JEV_SAMPLE_VERIFIED', false),
    // Both self-hosted and external endpoints use the embeddings API wire format.
    'endpoint' => env('EMBEDDING_ENDPOINT'),
    'provider' => env('EMBEDDING_PROVIDER'),
    'model' => env('EMBEDDING_MODEL'),
    'dimensions' => env('EMBEDDING_DIMENSIONS'),
    'key' => env('EMBEDDING_API_KEY'),
    'rate' => env('EMBEDDING_NANOUSD_PER_INPUT_TOKEN'),
    'max_tokens' => env('EMBEDDING_MAX_INPUT_TOKENS'),
];
