<?php

return [
    // JSON array of additional server-owned models. Never accept endpoint/price overrides from clients.
    'models' => json_decode(env('AI_MODELS_JSON', '[]'), true),
    'ollama_url' => env('OLLAMA_URL', 'http://ollama:11434'),
    'ollama_model' => env('OLLAMA_MODEL'),
    'ollama_context' => (int) env('OLLAMA_CONTEXT_WINDOW', 8192),
    'safety_tokens' => (int) env('AI_SAFETY_TOKENS', 512),
    'daily_user_budget' => (int) env('AI_DAILY_USER_BUDGET_MICRO_USD', 10000000),
    'daily_project_budget' => (int) env('AI_DAILY_PROJECT_BUDGET_MICRO_USD', 50000000),
    'daily_organization_budget' => (int) env('AI_DAILY_ORGANIZATION_BUDGET_MICRO_USD', 100000000),
    'default_input_price' => (float) env('AI_RESERVATION_INPUT_PRICE_PER_MILLION', 100),
    'default_output_price' => (float) env('AI_RESERVATION_OUTPUT_PRICE_PER_MILLION', 300),
    'history_pairs' => (int) env('AI_RECENT_HISTORY_PAIRS', 6),
];
