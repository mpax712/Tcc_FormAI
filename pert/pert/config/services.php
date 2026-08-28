<?php

return [
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_GRADING_MODEL', 'gpt-5.6-terra'),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 45),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 1200),
        'input_usd_per_mtok' => (float) env('OPENAI_INPUT_USD_PER_MTOK', 2.00),
        'output_usd_per_mtok' => (float) env('OPENAI_OUTPUT_USD_PER_MTOK', 12.00),
    ],
];
