<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Tests\Support;

/**
 * Starts PHP's built-in server for a test class, and makes raw HTTP requests to it
 */
trait BuiltInServer
{
    /**
     * @var resource|null
     */
    private static $server;
    private static int $port;

    /**
     * @param list<string> $arguments What the server serves: a router script, or ['-t', $directory]
     * @param array<string, string> $environment
     */
    private static function startServer(array $arguments, array $environment = []): void
    {
        // Find a free port
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $server = proc_open(
            // Errors are not displayed, as in production: a deprecation notice from an old dependency would otherwise
            // be printed before the headers. A single process, so that stopping it stops everything
            [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . self::$port, ...$arguments],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            [] === $environment ? null : $environment + getenv(),
        );
        self::assertIsResource($server);
        self::$server = $server;

        $deadline = microtime(true) + 5;
        while (false === $connection = @fsockopen('127.0.0.1', self::$port)) {
            if (microtime(true) > $deadline) {
                self::fail('The built-in server did not start.');
            }
            usleep(20_000);
        }
        fclose($connection);
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server, 9);
            proc_close(self::$server);
        }
    }

    /**
     * @param list<string> $headers
     * @return array{int, array<string, string>, string} The status, the headers by lowercase name, and the body
     */
    private function request(string $method, string $path, array $headers = [], string $body = ''): array
    {
        $connection = $this->open($method, $path, $headers, $body);
        $response = (string) stream_get_contents($connection);
        fclose($connection);

        return self::parseResponse($response);
    }

    /**
     * @param list<string> $headers
     * @return resource
     */
    private function open(string $method, string $path, array $headers = [], string $body = '')
    {
        $connection = fsockopen('127.0.0.1', self::$port);
        self::assertIsResource($connection);
        stream_set_timeout($connection, 10);
        if ('' !== $body) {
            $headers[] = 'Content-Length: ' . strlen($body);
        }
        fwrite($connection, implode("\r\n", ["{$method} {$path} HTTP/1.1", 'Host: 127.0.0.1', 'Connection: close', ...$headers]) . "\r\n\r\n" . $body);

        return $connection;
    }

    /**
     * Read until the text appears, then return everything read so far
     *
     * @param resource $connection
     */
    private function readUntil($connection, string $text): string
    {
        $received = '';
        while (!str_contains($received, $text) && !feof($connection) && !stream_get_meta_data($connection)['timed_out']) {
            $received .= (string) fread($connection, 1024);
        }
        self::assertStringContainsString($text, $received);

        return $received;
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private static function parseResponse(string $response): array
    {
        [$head, $body] = explode("\r\n\r\n", $response, 2) + [1 => ''];
        $lines = explode("\r\n", $head);
        $status = (int) (explode(' ', array_shift($lines))[1] ?? 0);
        $parsed = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $parsed[strtolower($name)] = trim($value);
        }

        return [$status, $parsed, $body];
    }
}
