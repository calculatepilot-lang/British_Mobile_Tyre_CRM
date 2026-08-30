<?php

return [
    'enabled' => filter_var(getenv('CLAUDE_OPTIMISATION_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
    'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
    // Haiku by default — this runs once a day on a small, structured dataset,
    // which doesn't need a larger model's cost. Set CLAUDE_MODEL=claude-sonnet-5
    // in .env if the reasoning quality needs to go up later.
    'model' => getenv('CLAUDE_MODEL') ?: 'claude-haiku-4-5-20251001',
    'max_tokens' => 2000,
    'timeout_seconds' => 30,
];
