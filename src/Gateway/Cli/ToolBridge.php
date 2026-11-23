<?php

namespace Laravel\Ai\Gateway\Cli;

use Closure;
use Laravel\Ai\Exceptions\AiException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Serves the agent's tools to a CLI: the CLI starts a small MCP server that
 * relays each tool call over a unix socket back to this process, where the
 * tool instances live and are run.
 */
class ToolBridge
{
    /**
     * The name the MCP server is registered under.
     */
    public const SERVER = 'laravel';

    /**
     * @var resource|null
     */
    protected $socket;

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @param  Closure(string, array<string, mixed>): string  $handler
     */
    public function __construct(
        protected string $directory,
        protected array $definitions,
        protected Closure $handler,
    ) {}

    /**
     * Start listening for tool calls.
     */
    public function start(): void
    {
        file_put_contents($this->directory.'/tools.json', json_encode($this->definitions, JSON_THROW_ON_ERROR));

        $socket = @stream_socket_server('unix://'.$this->directory.'/t.sock', $code, $message);

        if ($socket === false) {
            throw new AiException('Unable to open the tool socket: '.$message);
        }

        stream_set_blocking($this->socket = $socket, false);
    }

    /**
     * Get the command that starts the MCP server relaying to this bridge.
     *
     * @return array<int, string>
     */
    public function command(?string $php = null): array
    {
        return [
            $php ?: ((new PhpExecutableFinder)->find(false) ?: 'php'),
            __DIR__.'/mcp-bridge.php',
            $this->directory.'/t.sock',
            $this->directory.'/tools.json',
        ];
    }

    /**
     * Run any tool calls that are waiting, replying to each with its result.
     */
    public function serve(): void
    {
        while ($this->socket && ($connection = @stream_socket_accept($this->socket, 0))) {
            stream_set_blocking($connection, true);
            stream_set_timeout($connection, 10);

            try {
                $call = json_decode((string) fgets($connection), true);

                if (is_array($call) && is_string($call['name'] ?? null)) {
                    fwrite($connection, json_encode([
                        'text' => ($this->handler)($call['name'], (array) ($call['arguments'] ?? [])),
                    ])."\n");
                }
            } finally {
                fclose($connection);
            }
        }
    }

    /**
     * Stop listening for tool calls.
     */
    public function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);

            $this->socket = null;
        }
    }
}
