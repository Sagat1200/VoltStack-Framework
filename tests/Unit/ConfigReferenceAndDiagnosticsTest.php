<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Config\EnvReference;
use Quantum\Bootstrap\Config\SecretReference;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Diagnostics\ConfigRedactor;
use Quantum\Config\Reference\ConfigReferenceResolver;
use Quantum\Controllers\Observability\Engine\JsonLineControllerEventDispatcher;
use Quantum\Controllers\Observability\Events\ControllerEvent;
use Quantum\Telemetry\Engine\JsonLineTelemetryExporter;
use Quantum\Telemetry\TelemetrySignal;
use VoltStack\Framework\Application;

final class ConfigReferenceAndDiagnosticsTest extends TestCase
{
    private array $previousEnv = [];

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-config-diagnostics-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
                putenv($name);
                continue;
            }

            $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }

        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_repository_resolves_env_and_secret_references_explicitly(): void
    {
        $this->setEnv('CFG_REF_APP_ENV', 'production');
        $this->setEnv('CFG_REF_APP_KEY', 'base64:test-key');

        $repository = new ConfigRepository([
            'app' => [
                'env' => new EnvReference('CFG_REF_APP_ENV', 'local'),
                'key' => new SecretReference('CFG_REF_APP_KEY'),
                'debug' => new EnvReference('CFG_REF_APP_DEBUG', false),
            ],
        ]);

        $resolved = $repository->resolveReferences('app');

        self::assertSame('production', $resolved['env']);
        self::assertSame('base64:test-key', $resolved['key']);
        self::assertFalse($resolved['debug']);
    }

    public function test_repository_redact_hides_sensitive_paths_and_reference_payloads(): void
    {
        $repository = new ConfigRepository([
            'app' => [
                'key' => 'base64:secret',
            ],
            'database' => [
                'connections' => [
                    'default' => [
                        'password' => 'top-secret',
                    ],
                ],
            ],
            'secrets' => [
                'runtime' => new SecretReference('APP_KEY'),
            ],
        ]);

        $redacted = $repository->redact();

        self::assertSame('[redacted]', $redacted['app']['key']);
        self::assertSame('[redacted]', $redacted['database']['connections']['default']['password']);
        self::assertIsArray($redacted['secrets']['runtime']);
        self::assertSame('secret_reference', $redacted['secrets']['runtime']['_type']);
        self::assertSame('[redacted]', $redacted['secrets']['runtime']['value']);
    }

    public function test_env_helper_normalizes_boolean_and_null_tokens(): void
    {
        $this->setEnv('CFG_ENV_BOOL', 'true');
        $this->setEnv('CFG_ENV_NULL', '(null)');

        self::assertTrue(\env('CFG_ENV_BOOL'));
        self::assertNull(\env('CFG_ENV_NULL', 'fallback'));
        self::assertSame('fallback', \env('CFG_ENV_MISSING', 'fallback'));
    }

    public function test_telemetry_jsonl_exporter_redacts_sensitive_payloads(): void
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'telemetry.jsonl';
        $exporter = new JsonLineTelemetryExporter($file);

        $exporter->export(new TelemetrySignal(
            name: 'runtime.request',
            type: 'event',
            source: 'tests',
            occurredAt: '2026-10-04T00:00:00+00:00',
            payload: [
                'request' => [
                    'authorization' => 'Bearer abc123',
                    'password' => 'secret-value',
                ],
            ],
            attributes: [
                'api_token' => 'xyz',
            ],
        ));

        $decoded = $this->readJsonLine($file);

        self::assertSame('[redacted]', $decoded['payload']['request']['authorization']);
        self::assertSame('[redacted]', $decoded['payload']['request']['password']);
        self::assertSame('[redacted]', $decoded['attributes']['api_token']);
    }

    public function test_observability_jsonl_dispatcher_redacts_sensitive_payloads(): void
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'events.jsonl';
        $dispatcher = new JsonLineControllerEventDispatcher($file, redactor: new ConfigRedactor());

        $dispatcher->dispatch(new ControllerEvent(
            name: 'controllers.execution.created',
            version: 1,
            executionId: 'abc',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            sequence: 1,
            payload: [
                'session' => [
                    'cookie' => 'secret-cookie',
                ],
            ],
        ));

        $decoded = $this->readJsonLine($file);

        self::assertSame('[redacted]', $decoded['payload']['session']['cookie']);
    }

    public function test_application_registers_reference_and_redaction_services(): void
    {
        $app = new Application($this->basePath);

        self::assertInstanceOf(ConfigReferenceResolver::class, $app->make(ConfigReferenceResolver::class));
        self::assertInstanceOf(ConfigRedactor::class, $app->make(ConfigRedactor::class));
    }

    private function setEnv(string $name, string $value): void
    {
        if (! array_key_exists($name, $this->previousEnv)) {
            $existing = $_ENV[$name] ?? getenv($name);
            $this->previousEnv[$name] = ($existing === false || $existing === null) ? null : (string) $existing;
        }

        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonLine(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        return json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}
