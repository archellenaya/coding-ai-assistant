<?php

return [
    'intelligence_url' => env('INTELLIGENCE_URL', 'http://intelligence:8001'),
    'token' => env('INTELLIGENCE_TOKEN'),
    'model' => env('EMBEDDING_MODEL', 'BAAI/bge-small-en-v1.5'),
    'version' => env('EMBEDDING_VERSION', 'bge-small-en-v1.5-local-v1'),
    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 384),
    'pooling' => 'utf8-384-mean-v1',
    'min_similarity' => (float) env('MEMORY_MIN_SIMILARITY', 0.65),
    'context_chars' => 6000,
    'summary_input_chars' => 64000,
    'summary_output_chars' => 4000,
    'summary_output_tokens' => 1024,
];
