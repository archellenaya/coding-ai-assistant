<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL'),
    'context_window' => (int) env('OPENAI_CONTEXT_WINDOW', 0),
    'input_price_per_million' => env('OPENAI_INPUT_PRICE_PER_MILLION'),
    'output_price_per_million' => env('OPENAI_OUTPUT_PRICE_PER_MILLION'),
    'max_output_tokens' => min(8192, max(16, (int) env('CHAT_MAX_OUTPUT_TOKENS', 1024))),
    'max_input_chars' => min(64000, max(1000, (int) env('CHAT_MAX_INPUT_CHARS', 24000))),
    'max_response_chars' => 64000,
    'max_turns' => 20,
    'max_attempts' => 3,
    'stale_seconds' => 300,
    'stream_seconds' => 20,
];
