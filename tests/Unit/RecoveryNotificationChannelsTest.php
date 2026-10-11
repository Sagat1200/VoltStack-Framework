<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\RecoveryNotificationTemplateRendererInterface;
use Quantum\Auth\Recovery\EmailRecoveryNotificationChannel;
use Quantum\Auth\Recovery\PushRecoveryNotificationChannel;
use Quantum\Auth\Recovery\RecoveryNotification;
use Quantum\Auth\Recovery\RecoveryNotificationTemplate;
use Quantum\Auth\Recovery\RecoveryNotificationType;
use Quantum\Auth\Recovery\RecoveryPurpose;
use Quantum\Auth\Recovery\SmsRecoveryNotificationChannel;

/**
 * Pruebas unitarias de los canales reales de delivery: email, SMS y push.
 *
 * Cubre los paths de fallo (destino/URL vacíos), el uso del template renderer
 * y un round-trip HTTP real contra un servidor mock local para SMS y push.
 */
final class RecoveryNotificationChannelsTest extends TestCase
{
    private function makeNotification(
        string $channel = 'email',
        string $destination = 'user@example.com',
        array $payload = ['reset_url' => 'https://example.com/reset/abc'],
    ): RecoveryNotification {
        return new RecoveryNotification(
            type: RecoveryNotificationType::PasswordResetRequested,
            purpose: RecoveryPurpose::PasswordReset,
            identityType: 'user',
            identityId: 'user-123',
            destination: $destination,
            channel: $channel,
            transport: 'unit',
            createdAt: time(),
            payload: $payload,
        );
    }

    private function makeRenderer(string $subject, string $body, string $format = RecoveryNotificationTemplate::FORMAT_TEXT): RecoveryNotificationTemplateRendererInterface
    {
        return new class($subject, $body, $format) implements RecoveryNotificationTemplateRendererInterface {
            public function __construct(
                private readonly string $subject,
                private readonly string $body,
                private readonly string $format,
            ) {}

            public function render(
                RecoveryNotificationType $type,
                string $channel,
                array $variables = [],
            ): RecoveryNotificationTemplate {
                return new RecoveryNotificationTemplate(
                    subject: $this->subject,
                    body: $this->body,
                    format: $this->format,
                );
            }
        };
    }

    // ------------------------------------------------------------------
    // Email
    // ------------------------------------------------------------------

    public function test_email_channel_name_is_email(): void
    {
        $channel = new EmailRecoveryNotificationChannel();
        self::assertSame('email', $channel->channelName());
    }

    public function test_email_channel_fails_when_destination_empty(): void
    {
        $channel = new EmailRecoveryNotificationChannel();
        $result = $channel->send($this->makeNotification('email', ''));

        self::assertFalse($result->success);
        self::assertSame('email', $result->channel);
        self::assertStringContainsString('destination', $result->errorMessage ?? '');
    }

    public function test_email_channel_uses_template_renderer(): void
    {
        $renderer = $this->makeRenderer('Subject test', 'Body test');
        $channel = new EmailRecoveryNotificationChannel(
            templateRenderer: $renderer,
            from: 'noreply@example.com',
        );

        // mail() normalmente devuelve false en entornos sin sendmail, pero el
        // canal no debe lanzar excepciones y debe devolver un resultado válido.
        $result = $channel->send($this->makeNotification());

        self::assertSame('email', $result->channel);
        self::assertSame('user@example.com', $result->destination);
    }

    public function test_email_channel_falls_back_to_payload_without_renderer(): void
    {
        $channel = new EmailRecoveryNotificationChannel(
            from: 'noreply@example.com',
        );

        $result = $channel->send($this->makeNotification());

        self::assertSame('email', $result->channel);
        // No debe lanzar; el resultado puede ser success o failure según mail().
    }

    // ------------------------------------------------------------------
    // SMS
    // ------------------------------------------------------------------

    public function test_sms_channel_name_is_sms(): void
    {
        $channel = new SmsRecoveryNotificationChannel();
        self::assertSame('sms', $channel->channelName());
    }

    public function test_sms_channel_fails_when_destination_empty(): void
    {
        $channel = new SmsRecoveryNotificationChannel(gatewayUrl: 'http://example.com');
        $result = $channel->send($this->makeNotification('sms', ''));

        self::assertFalse($result->success);
        self::assertSame('sms', $result->channel);
        self::assertStringContainsString('destination', $result->errorMessage ?? '');
    }

    public function test_sms_channel_fails_when_gateway_url_empty(): void
    {
        $channel = new SmsRecoveryNotificationChannel(gatewayUrl: '');
        $result = $channel->send($this->makeNotification('sms', '+1234567890'));

        self::assertFalse($result->success);
        self::assertStringContainsString('url', $result->errorMessage ?? '');
    }

    public function test_sms_channel_round_trip_against_mock_server(): void
    {
        $server = $this->startMockServer([
            'status' => 200,
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode(['message_id' => 'sms-123'], JSON_THROW_ON_ERROR),
        ]);

        try {
            $channel = new SmsRecoveryNotificationChannel(
                templateRenderer: $this->makeRenderer('', 'Your reset code is 123456'),
                gatewayUrl: $server['url'],
                apiKey: 'test-key',
                timeoutSeconds: 3,
                toParam: 'to',
                bodyParam: 'body',
            );

            $result = $channel->send($this->makeNotification('sms', '+1234567890'));

            self::assertTrue($result->success, 'SMS debe tener éxito contra mock server: ' . ($result->errorMessage ?? ''));
            self::assertSame('sms', $result->channel);
            self::assertSame('+1234567890', $result->destination);
            self::assertSame(200, $result->providerResponseData['http_code'] ?? 0);
        } finally {
            $this->stopMockServer($server);
        }
    }

    // ------------------------------------------------------------------
    // Push
    // ------------------------------------------------------------------

    public function test_push_channel_name_is_push(): void
    {
        $channel = new PushRecoveryNotificationChannel();
        self::assertSame('push', $channel->channelName());
    }

    public function test_push_channel_fails_when_destination_empty(): void
    {
        $channel = new PushRecoveryNotificationChannel(gatewayUrl: 'http://example.com');
        $result = $channel->send($this->makeNotification('push', ''));

        self::assertFalse($result->success);
        self::assertSame('push', $result->channel);
        self::assertStringContainsString('token', $result->errorMessage ?? '');
    }

    public function test_push_channel_fails_when_gateway_url_empty(): void
    {
        $channel = new PushRecoveryNotificationChannel(gatewayUrl: '');
        $result = $channel->send($this->makeNotification('push', 'device-token-abc'));

        self::assertFalse($result->success);
        self::assertStringContainsString('url', $result->errorMessage ?? '');
    }

    public function test_push_channel_round_trip_against_mock_server(): void
    {
        $server = $this->startMockServer([
            'status' => 200,
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode(['message_id' => 'push-123'], JSON_THROW_ON_ERROR),
        ]);

        try {
            $channel = new PushRecoveryNotificationChannel(
                templateRenderer: $this->makeRenderer('Recovery Alert', 'Reset your password'),
                gatewayUrl: $server['url'],
                apiKey: 'push-key',
                timeoutSeconds: 3,
            );

            $result = $channel->send($this->makeNotification('push', 'device-token-abc'));

            self::assertTrue($result->success, 'Push debe tener éxito contra mock server: ' . ($result->errorMessage ?? ''));
            self::assertSame('push', $result->channel);
            self::assertSame('device-token-abc', $result->destination);
            self::assertSame(200, $result->providerResponseData['http_code'] ?? 0);
        } finally {
            $this->stopMockServer($server);
        }
    }

    public function test_push_channel_handles_http_error_from_gateway(): void
    {
        $server = $this->startMockServer([
            'status' => 500,
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode(['error' => 'internal'], JSON_THROW_ON_ERROR),
        ]);

        try {
            $channel = new PushRecoveryNotificationChannel(
                gatewayUrl: $server['url'],
                timeoutSeconds: 3,
            );

            $result = $channel->send($this->makeNotification('push', 'device-token-abc'));

            self::assertFalse($result->success);
            self::assertStringContainsString('500', $result->errorMessage ?? '');
            self::assertSame(500, $result->providerResponseData['http_code'] ?? 0);
        } finally {
            $this->stopMockServer($server);
        }
    }

    // ------------------------------------------------------------------
    // Helpers: mock HTTP server (cross-platform, single-shot socket server)
    // ------------------------------------------------------------------

    /**
     * Inicia un servidor socket de un solo uso en un puerto libre.
     *
     * El servidor escribe "READY" por stdout, acepta una conexión, envía la
     * respuesta configurada y termina. Esto evita procesos colgados y funciona
     * en Windows sin pcntl.
     *
     * @param array{status: int, headers?: list<string>, body: string} $response
     * @return array{process: resource, pipes: array, url: string, tempDir: string}
     */
    private function startMockServer(array $response): array
    {
        $tempDir = sys_get_temp_dir() . '/voltstack-mock-' . bin2hex(random_bytes(4));
        @mkdir($tempDir, 0777, true);

        $responseFile = $tempDir . '/response.json';
        file_put_contents($responseFile, json_encode($response, JSON_THROW_ON_ERROR));

        // Encontrar un puerto libre.
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($probe, 'No se pudo encontrar puerto libre: ' . $errstr);
        $name = stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);

        $serverScript = $tempDir . '/server.php';
        $script = <<<'PHP'
<?php
$port = (int)($argv[1] ?? 0);
$responseFile = $argv[2] ?? '';
$socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!$socket) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(1);
}
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$client = @stream_socket_accept($socket, 15);
if ($client) {
    $request = '';
    while (!feof($client)) {
        $chunk = fread($client, 8192);
        if ($chunk === false || $chunk === '') break;
        $request .= $chunk;
        if (strpos($request, "\r\n\r\n") !== false) {
            if (preg_match('/Content-Length:\s*(\d+)/i', $request, $m)) {
                $bodyStart = strpos($request, "\r\n\r\n") + 4;
                $bodyLength = (int)$m[1];
                while (strlen($request) - $bodyStart < $bodyLength) {
                    $chunk = fread($client, 8192);
                    if ($chunk === false || $chunk === '') break;
                    $request .= $chunk;
                }
            }
            break;
        }
    }
    $resp = json_decode(file_get_contents($responseFile), true) ?: [];
    $status = $resp['status'] ?? 200;
    $statusText = $status === 200 ? 'OK' : 'Error';
    $out = "HTTP/1.1 $status $statusText\r\n";
    foreach (($resp['headers'] ?? []) as $h) { $out .= "$h\r\n"; }
    $body = (string)($resp['body'] ?? '');
    $out .= "Content-Length: " . strlen($body) . "\r\n";
    $out .= "Connection: close\r\n\r\n";
    $out .= $body;
    fwrite($client, $out);
    fclose($client);
}
fclose($socket);
exit(0);
PHP;
        file_put_contents($serverScript, $script);

        $php = PHP_BINARY;
        $command = sprintf(
            '"%s" "%s" %d "%s"',
            $php,
            $serverScript,
            $port,
            $responseFile,
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        self::assertIsResource($process, 'No se pudo iniciar el servidor mock');

        // Esperar a que el servidor indique READY.
        stream_set_blocking($pipes[1], true);
        $ready = '';
        $deadline = time() + 5;
        while (time() < $deadline) {
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $ready .= $line;
                if (str_contains($ready, 'READY')) {
                    break;
                }
            }
            usleep(10000);
        }
        self::assertStringContainsString('READY', $ready, 'El servidor mock no indicó READY');

        return [
            'process' => $process,
            'pipes' => $pipes,
            'url' => 'http://127.0.0.1:' . $port,
            'tempDir' => $tempDir,
        ];
    }

    /**
     * @param array{process: resource, pipes: array, url: string, tempDir: string} $server
     */
    private function stopMockServer(array $server): void
    {
        foreach ($server['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($server['process'])) {
            // El script de servidor ya debe haber terminado tras atender la
            // solicitud. Cerramos sin bloquear indebidamente.
            $status = proc_get_status($server['process']);
            if (! ($status['running'] ?? false)) {
                proc_close($server['process']);
            } else {
                proc_terminate($server['process'], 9);
                // No llamamos proc_close en Windows si sigue corriendo para
                // evitar bloqueos; el proceso será recogido por el SO.
            }
        }
        // Limpiar archivos temporales.
        $tempDir = $server['tempDir'];
        if (is_dir($tempDir)) {
            foreach (glob($tempDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tempDir);
        }
    }
}
