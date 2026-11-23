<?php

/*
 * A minimal MCP stdio server that exposes an agent's tools to a CLI.
 *
 * It only relays: tool calls are forwarded over a unix socket to the PHP
 * process that started the CLI, which owns the tool instances and runs them.
 *
 * Usage: php mcp-bridge.php <socket path> <tool definitions json path>
 */

[$socket, $definitions] = [$argv[1] ?? '', $argv[2] ?? ''];

// Decoded as objects so that an empty schema object is not re-encoded as an empty list...
$tools = json_decode((string) @file_get_contents($definitions)) ?: [];

$call = function (array $params) use ($socket): array {
    $connection = @stream_socket_client('unix://'.$socket, $code, $message, 10);

    if ($connection === false) {
        return ['content' => [['type' => 'text', 'text' => 'The tool is unavailable: '.$message]], 'isError' => true];
    }

    // Tools may run for a long time, so the reply is awaited without a deadline...
    stream_set_timeout($connection, 86400);

    fwrite($connection, json_encode([
        'name' => $params['name'] ?? '',
        'arguments' => (object) ($params['arguments'] ?? []),
    ])."\n");

    $reply = json_decode((string) fgets($connection), true);

    fclose($connection);

    return [
        'content' => [['type' => 'text', 'text' => (string) ($reply['text'] ?? 'The tool returned no result.')]],
        'isError' => (bool) ($reply['error'] ?? ! is_array($reply)),
    ];
};

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);

    // Notifications carry no id and expect no reply...
    if (! is_array($message) || ! isset($message['id'])) {
        continue;
    }

    $result = match ($message['method'] ?? null) {
        'initialize' => [
            'protocolVersion' => $message['params']['protocolVersion'] ?? '2025-06-18',
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'laravel-ai', 'version' => '1.0.0'],
        ],
        'ping' => (object) [],
        'tools/list' => ['tools' => $tools],
        'tools/call' => $call($message['params'] ?? []),
        default => null,
    };

    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $message['id']] + ($result === null
        ? ['error' => ['code' => -32601, 'message' => 'Method not found']]
        : ['result' => $result]))."\n");

    fflush(STDOUT);
}
