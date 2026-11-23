<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(fn () => Sleep::fake());

function claudeCodeOutput(string $text, array $result = []): string
{
    return implode("\n", array_map(json_encode(...), [
        ['type' => 'system', 'subtype' => 'init'],
        ['type' => 'stream_event', 'event' => ['type' => 'message_start', 'message' => ['model' => 'claude-haiku-5-5']]],
        ['type' => 'stream_event', 'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => substr($text, 0, 2)]]],
        ['type' => 'stream_event', 'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => substr($text, 2)]]],
        $result + [
            'type' => 'result',
            'is_error' => false,
            'result' => $text,
            'usage' => ['input_tokens' => 2, 'output_tokens' => 5, 'cache_read_input_tokens' => 10, 'cache_creation_input_tokens' => 20],
        ],
    ]));
}

function codexOutput(string $text): string
{
    return implode("\n", array_map(json_encode(...), [
        ['type' => 'thread.started', 'thread_id' => 'thread'],
        ['type' => 'turn.started'],
        ['type' => 'item.completed', 'item' => ['id' => 'item_0', 'type' => 'agent_message', 'text' => $text]],
        ['type' => 'turn.completed', 'usage' => ['input_tokens' => 100, 'cached_input_tokens' => 40, 'output_tokens' => 7, 'reasoning_output_tokens' => 3]],
    ]));
}

test('claude code generates text through the cli', function (): void {
    Process::fake(['*' => Process::result(claudeCodeOutput('Hello there'))]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'claude-code', model: 'haiku');

    expect($response->text)->toBe('Hello there')
        ->and($response->meta->model)->toBe('claude-haiku-5-5')
        ->and($response->usage->inputTokens)->toBe(32)
        ->and($response->usage->outputTokens)->toBe(5)
        ->and($response->usage->cacheReadInputTokens)->toBe(10);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = $process->command;

        return $command[0] === 'claude'
            && $process->input === 'Hi'
            && $command[array_search('--model', $command) + 1] === 'haiku'
            && $command[array_search('--tools', $command) + 1] === ''
            && $command[array_search('--setting-sources', $command) + 1] === ''
            && str_contains($command[array_search('--system-prompt', $command) + 1], 'helpful assistant')
            && in_array('--no-session-persistence', $command, true)
            && ! str_contains((string) $process->path, 'laravel-ai/tests');
    });
});

test('claude code never inherits an api key and passes the configured subscription token', function (): void {
    config()->set('ai.providers.claude-code.oauth_token', 'subscription-token');
    config()->set('ai.providers.claude-code.binary', '/opt/bin/claude');

    Process::fake(['*' => Process::result(claudeCodeOutput('Hello'))]);

    (new AssistantAgent)->prompt('Hi', provider: 'claude-code');

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === '/opt/bin/claude'
        && $process->environment['ANTHROPIC_API_KEY'] === false
        && $process->environment['ANTHROPIC_AUTH_TOKEN'] === false
        && $process->environment['CLAUDE_CODE_OAUTH_TOKEN'] === 'subscription-token');
});

test('claude code streams text deltas', function (): void {
    Process::fake(['*' => Process::result(claudeCodeOutput('Hello there'))]);

    $deltas = [];

    foreach ((new AssistantAgent)->stream('Hi', provider: 'claude-code') as $event) {
        if ($event instanceof TextDelta) {
            $deltas[] = $event->delta;
        }
    }

    expect($deltas)->toBe(['He', 'llo there']);
});

test('claude code returns structured output', function (): void {
    Process::fake(['*' => Process::result(claudeCodeOutput('{"city":"Paris"}', ['structured_output' => ['city' => 'Paris']]))]);

    $response = agent(schema: fn ($schema) => ['city' => $schema->string()->required()])
        ->prompt('Capital of France?', provider: 'claude-code');

    expect($response['city'])->toBe('Paris');

    Process::assertRan(function (PendingProcess $process): bool {
        $schema = json_decode($process->command[array_search('--json-schema', $process->command) + 1], true);

        return $schema['properties']['city']['type'] === 'string';
    });
});

test('claude code reports cli errors', function (): void {
    Process::fake(['*' => Process::result(json_encode(['type' => 'result', 'is_error' => true, 'result' => 'Invalid API key · Please run /login']), exitCode: 1)]);

    (new AssistantAgent)->prompt('Hi', provider: 'claude-code');
})->throws(AiException::class, 'Claude Code error: Invalid API key');

test('a failing process reports its error output', function (): void {
    Process::fake(['*' => Process::result(errorOutput: 'sh: claude: command not found', exitCode: 127)]);

    (new AssistantAgent)->prompt('Hi', provider: 'claude-code');
})->throws(AiException::class, 'command not found');

test('codex generates text through the cli', function (): void {
    Process::fake(['*' => Process::result(codexOutput('pong'))]);

    $response = (new AssistantAgent)->prompt('ping', provider: 'codex');

    expect($response->text)->toBe('pong')
        ->and($response->usage->inputTokens)->toBe(100)
        ->and($response->usage->cacheReadInputTokens)->toBe(40)
        ->and($response->usage->reasoningTokens)->toBe(3);

    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, 0, 2) === ['codex', 'exec']
        && $process->command[array_search('--sandbox', $process->command) + 1] === 'read-only'
        && in_array('--ephemeral', $process->command, true)
        && ! in_array('--model', $process->command, true)
        && str_starts_with($process->input, "<instructions>\nYou are a helpful assistant")
        && str_ends_with($process->input, 'ping'));
});

test('codex passes an explicit model and its home directory', function (): void {
    config()->set('ai.providers.codex.home', '/srv/codex');

    Process::fake(['*' => Process::result(codexOutput('pong'))]);

    (new AssistantAgent)->prompt('ping', provider: 'codex', model: 'gpt-6-luna');

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[array_search('--model', $process->command) + 1] === 'gpt-6-luna'
        && $process->environment === ['CODEX_HOME' => '/srv/codex']);
});

test('codex returns structured output from a schema file', function (): void {
    Process::fake(['*' => Process::result(codexOutput('{"city":"Paris"}'))]);

    $response = agent(schema: fn ($schema) => ['city' => $schema->string()->required()])
        ->prompt('Capital of France?', provider: 'codex');

    expect($response['city'])->toBe('Paris');

    Process::assertRan(fn (PendingProcess $process): bool => str_ends_with(
        $process->command[array_search('--output-schema', $process->command) + 1], '/schema.json',
    ));
});

test('codex reports a failed turn', function (): void {
    Process::fake(['*' => Process::result(json_encode(['type' => 'turn.failed', 'error' => ['message' => 'usage limit reached']]), exitCode: 1)]);

    (new AssistantAgent)->prompt('Hi', provider: 'codex');
})->throws(AiException::class, 'Codex error: usage limit reached');

test('earlier messages are rendered as a transcript', function (): void {
    Process::fake(['*' => Process::result(codexOutput('Bob'))]);

    agent(messages: [new Message('user', 'My name is Bob.'), new Message('assistant', 'Nice to meet you.')])
        ->prompt('What is my name?', provider: 'codex');

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->input, "<user>\nMy name is Bob.\n</user>")
        && str_contains($process->input, "<assistant>\nNice to meet you.\n</assistant>")
        && str_contains($process->input, "<user>\nWhat is my name?\n</user>"));
});

test('tools are rejected rather than silently dropped', function (): void {
    Process::fake();

    agent(tools: [new RandomNumberGenerator])->prompt('Hi', provider: 'claude-code');
})->throws(AiException::class, 'does not support tools');
