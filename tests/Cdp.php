<?php
declare(strict_types=1);
// Minimal Chrome DevTools client for real browser checks, implemented only in PHP.
final class Cdp
{
    private $socket;
    private int $nextId = 0;
    public array $errors = [];
    public function __construct(string $url)
    {
        $parts = parse_url($url);
        if (($parts['host'] ?? '') !== '127.0.0.1') throw new RuntimeException('CDP solo su loopback.');
        $this->socket = stream_socket_client('tcp://' . $parts['host'] . ':' . $parts['port'], $errno, $error, 10);
        stream_set_timeout($this->socket, 30);
        $key = base64_encode(random_bytes(16));
        fwrite($this->socket, "GET {$parts['path']} HTTP/1.1\r\nHost: {$parts['host']}:{$parts['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $header = '';
        while (!str_ends_with($header, "\r\n\r\n")) $header .= $this->read(1);
        if (!preg_match('/^HTTP\/1\.1 101 /', $header)) throw new RuntimeException('Handshake CDP fallito.');
    }
    private function read(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) { $part = fread($this->socket, $length - strlen($data)); if ($part === '' || $part === false) throw new RuntimeException('CDP non risponde.'); $data .= $part; }
        return $data;
    }
    public function call(string $method, array $params = []): array
    {
        $id = ++$this->nextId;
        $payload = json_encode(['id' => $id, 'method' => $method, 'params' => (object)$params], JSON_THROW_ON_ERROR);
        $length = strlen($payload); $mask = random_bytes(4);
        $header = chr(0x81) . ($length < 126 ? chr(0x80 | $length) : ($length < 65536 ? chr(0xfe) . pack('n', $length) : chr(0xff) . pack('NN', 0, $length)));
        $masked = ''; for ($i = 0; $i < $length; $i++) $masked .= $payload[$i] ^ $mask[$i % 4];
        fwrite($this->socket, $header . $mask . $masked);
        while (true) {
            $frame = unpack('Cfirst/Csecond', $this->read(2));
            $size = $frame['second'] & 127;
            if ($size === 126) $size = unpack('n', $this->read(2))[1];
            elseif ($size === 127) { $parts = unpack('Nhigh/Nlow', $this->read(8)); if ($parts['high']) throw new RuntimeException('Frame CDP troppo grande.'); $size = $parts['low']; }
            $body = $this->read($size);
            $message = json_decode($body, true);
            if (($message['method'] ?? '') === 'Runtime.exceptionThrown') $this->errors[] = $message['params']['exceptionDetails'];
            if (($message['id'] ?? null) !== $id) continue;
            if (isset($message['error'])) throw new RuntimeException(json_encode($message['error']));
            return $message['result'] ?? [];
        }
    }
    public function evaluate(string $expression): mixed
    {
        $result = $this->call('Runtime.evaluate', ['expression' => $expression, 'awaitPromise' => true, 'returnByValue' => true]);
        if (isset($result['exceptionDetails'])) throw new RuntimeException(json_encode($result['exceptionDetails']));
        return $result['result']['value'] ?? null;
    }
    public function wait(string $expression, int $seconds = 20): mixed
    {
        $end = microtime(true) + $seconds;
        do { $value = $this->evaluate($expression); if ($value) return $value; usleep(100000); } while (microtime(true) < $end);
        throw new RuntimeException('Timeout browser: ' . $expression);
    }
}
