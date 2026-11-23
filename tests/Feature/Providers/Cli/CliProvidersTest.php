<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\Cli\CodexGateway;
use Laravel\Ai\Gateway\Cli\ToolBridge;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;
use Tests\Fixtures\Tools\FixedNumberGenerator;

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
            && json_decode($process->input, true)['message']['content'] === [['type' => 'text', 'text' => 'Hi']]
            && $command[array_search('--input-format', $command) + 1] === 'stream-json'
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

test('claude code is capped at the max tokens and reports the truncated answer', function (): void {
    Process::fake(['*' => Process::result(implode("\n", array_map(json_encode(...), [
        ['type' => 'stream_event', 'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Once upon']]],
        ['type' => 'stream_event', 'event' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'max_tokens'], 'usage' => ['input_tokens' => 3, 'output_tokens' => 50]]],
        ['type' => 'stream_event', 'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => ' a continuation the cli started by itself']]],
        ['type' => 'result', 'is_error' => false, 'result' => 'a continuation'],
    ])))]);

    $response = (new AttributeAgent)->prompt('Tell a story', provider: 'claude-code');

    expect($response->text)->toBe('Once upon')
        ->and($response->steps->last()->finishReason)->toBe(FinishReason::Length)
        ->and($response->usage->outputTokens)->toBe(50);

    Process::assertRan(fn (PendingProcess $process): bool => $process->environment['CLAUDE_CODE_MAX_OUTPUT_TOKENS'] === '4096');
});

test('claude code receives attachments as content blocks', function (): void {
    Process::fake(['*' => Process::result(claudeCodeOutput('Red'))]);

    agent()->prompt('What color?', attachments: [Image::fromBase64('aW1hZ2U=', 'image/png')], provider: 'claude-code');

    Process::assertRan(fn (PendingProcess $process): bool => json_decode($process->input, true)['message']['content'][1] === [
        'type' => 'image',
        'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'aW1hZ2U='],
    ]);
});

test('codex receives image attachments as files', function (): void {
    $contents = null;

    Process::fake(['*' => function (PendingProcess $process) use (&$contents) {
        $contents = file_get_contents($process->command[array_search('--image', $process->command) + 1]);

        return Process::result(codexOutput('Red'));
    }]);

    agent()->prompt('What color?', attachments: [Image::fromBase64(base64_encode('image-bytes'), 'image/jpeg')], provider: 'codex');

    expect($contents)->toBe('image-bytes');

    Process::assertRan(fn (PendingProcess $process): bool => str_ends_with($process->command[array_search('--image', $process->command) + 1], '/image-0.jpg')
        && ! is_dir(dirname($process->command[array_search('--image', $process->command) + 1])));
});

test('codex rejects attachments it cannot be given', function (): void {
    Process::fake();

    agent()->prompt('Summarize', attachments: [Document::fromBase64('cGRm', 'application/pdf')], provider: 'codex');
})->throws(AiException::class, 'only supports local, stored, uploaded, or base64 image attachments');

test('tools are offered to each cli through the mcp bridge', function (string $provider, Closure $offersTools): void {
    Process::fake(['*' => Process::result($provider === 'codex' ? codexOutput('ok') : claudeCodeOutput('ok'))]);

    agent(tools: [new FixedNumberGenerator])->prompt('Hi', provider: $provider);

    Process::assertRan(fn (PendingProcess $process): bool => $offersTools($process->command));
})->with([
    'claude code' => ['claude-code', function (array $command): bool {
        $server = json_decode($command[array_search('--mcp-config', $command) + 1], true)['mcpServers']['laravel'];

        return str_ends_with($server['args'][0], 'mcp-bridge.php')
            && $command[array_search('--allowedTools', $command) + 1] === 'mcp__laravel';
    }],
    'codex' => ['codex', fn (array $command): bool => collect($command)->contains(fn ($part) => str_starts_with($part, 'mcp_servers.laravel.command="'))
        && collect($command)->contains(fn ($part) => str_starts_with($part, 'mcp_servers.laravel.args=["') && str_contains($part, 'mcp-bridge.php'))
        && in_array('mcp_servers.laravel.default_tools_approval_mode="approve"', $command, true)],
]);

/**
 * Drive the real bridge script as a CLI would, serving its tool calls from this process.
 */
function driveToolBridge(array $tools, array $calls): array
{
    $gateway = new class extends CodexGateway
    {
        public function bridge(string $directory, array $tools): ToolBridge
        {
            return new ToolBridge($directory, $this->toolDefinitions($tools), fn (string $name, array $arguments): string => $this->callTool($name, $arguments, $tools));
        }
    };

    mkdir($directory = sys_get_temp_dir().'/lai-test-'.bin2hex(random_bytes(4)));

    $bridge = $gateway->bridge($directory, $tools);
    $bridge->start();

    $requests = [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']];

    foreach ($calls as $index => $call) {
        $requests[] = ['jsonrpc' => '2.0', 'id' => $index + 3, 'method' => 'tools/call', 'params' => $call];
    }

    try {
        $process = new SymfonyProcess($bridge->command());
        $process->setInput(implode("\n", array_map(json_encode(...), $requests))."\n");
        $process->start();

        while ($process->isRunning()) {
            $bridge->serve();
            usleep(5000);
        }
    } finally {
        $bridge->close();
        (new Filesystem)->deleteDirectory($directory);
    }

    return array_map(fn (string $line) => json_decode($line), array_filter(explode("\n", $process->getOutput())));
}

test('the bridge lists the tools and runs their calls in this process', function (): void {
    [$initialize, $list, $call] = driveToolBridge([new FixedNumberGenerator], [['name' => 'FixedNumberGenerator', 'arguments' => []]]);

    expect($initialize->result->serverInfo->name)->toBe('laravel-ai')
        ->and($list->result->tools[0]->name)->toBe('FixedNumberGenerator')
        ->and($list->result->tools[0]->inputSchema->properties)->toBeObject()
        ->and($call->result->content[0]->text)->toBe('72019')
        ->and($call->result->isError)->toBeFalse();
});

test('the bridge refuses a tool call that requires approval', function (): void {
    [, , $call] = driveToolBridge([new ApprovableNumberGenerator], [['name' => 'ApprovableNumberGenerator', 'arguments' => []]]);

    expect($call->result->content[0]->text)->toContain('requires approval')->toContain('was not run');
});

test('the bridge reports unknown tools to the model', function (): void {
    [, , $call] = driveToolBridge([new FixedNumberGenerator], [['name' => 'Missing', 'arguments' => []]]);

    expect($call->result->content[0]->text)->toContain('does not exist');
});
