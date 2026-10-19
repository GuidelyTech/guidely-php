<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Exception\TransportException;
use GuidelyTech\Flags\Http\DefaultHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises DefaultHttpClient (cURL and streams) against PHP's built-in web server. */
final class HttpTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        self::$port = random_int(20000, 60000);
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fixtures/router.php'];
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = @proc_open($cmd, [['pipe', 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $pipes);
        if (!is_resource($server)) {
            return;
        }
        self::$server = $server;
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    public static function transports(): iterable
    {
        yield 'curl' => [true];
        yield 'streams' => [false];
    }

    private function client(bool $curl): DefaultHttpClient
    {
        if (self::$server === null) {
            self::markTestSkipped('Could not start the PHP built-in server');
        }
        if ($curl && !function_exists('curl_init')) {
            self::markTestSkipped('ext-curl is not installed');
        }
        return new DefaultHttpClient($curl);
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    #[DataProvider('transports')]
    public function testGetWithHeaders(bool $curl): void
    {
        $response = $this->client($curl)->send('GET', $this->url('/api/v1/sdk/flags?key=pk_1'), ['Authorization' => 'Bearer gly_sk_x'], null, 5);
        self::assertSame(200, $response->status);
        self::assertSame('"e1"', $response->header('ETag'));
        $echo = json_decode($response->body, true);
        self::assertSame('GET', $echo['method']);
        self::assertSame('/api/v1/sdk/flags?key=pk_1', $echo['uri']);
        self::assertSame('Bearer gly_sk_x', $echo['authorization']);
    }

    #[DataProvider('transports')]
    public function testNotModified(bool $curl): void
    {
        $response = $this->client($curl)->send('GET', $this->url('/flags'), ['If-None-Match' => '"e1"'], null, 5);
        self::assertSame(304, $response->status);
        self::assertSame('', $response->body);
    }

    #[DataProvider('transports')]
    public function testPostAndErrorStatus(bool $curl): void
    {
        $http = $this->client($curl);
        $response = $http->send('POST', $this->url('/events'), ['Content-Type' => 'application/json'], '{"a":1}', 5);
        $echo = json_decode($response->body, true);
        self::assertSame('POST', $echo['method']);
        self::assertSame('{"a":1}', $echo['body']);
        self::assertSame('application/json', $echo['contentType']);

        self::assertSame(404, $http->send('GET', $this->url('/missing'), [], null, 5)->status);
    }

    #[DataProvider('transports')]
    public function testConnectionFailure(bool $curl): void
    {
        $http = $this->client($curl);
        $this->expectException(TransportException::class);
        $http->send('GET', 'http://127.0.0.1:1/flags', [], null, 1);
    }
}
