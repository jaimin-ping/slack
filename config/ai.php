<?php

/*
|--------------------------------------------------------------------------
| Personal AI agents
|--------------------------------------------------------------------------
|
| Provider credentials stay on the server: they are read here and never
| shared with the browser or the mobile app.
|
*/

return [

    // Which provider runs the agents: "anthropic", or "fake" (scripted replies, used by the tests).
    'provider' => env('AI_PROVIDER', 'anthropic'),

    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('AI_ANTHROPIC_MODEL', 'claude-opus-5-5'),
            'timeout' => (float) env('AI_TIMEOUT_SECONDS', 90),
            'max_retries' => (int) env('AI_MAX_RETRIES', 2),
            // On a policy decline the API retries on the server-chosen fallback model in the same call.
            'fallbacks' => env('AI_ANTHROPIC_FALLBACKS', 'default'),
        ],
    ],

    // How much the model thinks. Extraction is a narrow job; agent conversations need more judgement.
    'effort' => [
        'extraction' => env('AI_EFFORT_EXTRACTION', 'low'),
        'agent' => env('AI_EFFORT_AGENT', 'medium'),
    ],

    // US dollars per million tokens, used for cost tracking. Unknown models are tracked at zero cost.
    'pricing' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
    ],

    // Hard limits that keep one member from flooding a workspace with agents.
    'limits' => [
        'agents_per_member' => (int) env('AI_AGENTS_PER_MEMBER', 10),
        'sources_per_agent' => (int) env('AI_SOURCES_PER_AGENT', 50),
        'memories_per_agent' => (int) env('AI_MEMORIES_PER_AGENT', 100),
        'max_steps' => (int) env('AI_MAX_STEPS', 8),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 16000),
        'run_seconds' => (int) env('AI_RUN_SECONDS', 180),
        'messages_per_batch' => 20,
        'message_chars' => 2000,
        'history_days' => 30,
        'history_messages' => 500,
        'chat_messages_per_minute' => 10,
        // Messages an agent may post to other people in any hour, approved ones included.
        'outbound_messages_per_hour' => (int) env('AI_OUTBOUND_MESSAGES_PER_HOUR', 20),
        'outbound_message_chars' => 4000,
        'follow_up_recipients' => 5,
    ],

    // Daily token budgets. A run that would go past one is skipped and shown in the agent's activity.
    'budgets' => [
        'agent_daily_tokens' => (int) env('AI_AGENT_DAILY_TOKENS', 2_000_000),
        'member_daily_tokens' => (int) env('AI_MEMBER_DAILY_TOKENS', 5_000_000),
        'workspace_daily_tokens' => (int) env('AI_WORKSPACE_DAILY_TOKENS', 20_000_000),
    ],

    'realtime' => [
        // New messages wait this long so a burst is read together in one call.
        'debounce_seconds' => (int) env('AI_DEBOUNCE_SECONDS', 20),
        // Only messages this recent are read in real time; older ones need a history analysis.
        'window_hours' => 24,
    ],

    'queue' => env('AI_QUEUE', 'ai'),

];
