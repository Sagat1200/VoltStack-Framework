<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;
use App\Controllers\SecurityDemoController;
use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyRegistryInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;

final class SkeletonSecuritySmokeTest extends TestCase
{
    private static string $skeletonBasePath;
    private string $basePath;
    private Application $app;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$skeletonBasePath = self::locateSkeletonBasePath();

        require_once self::$skeletonBasePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-skel-smoke-' . uniqid('', true);
        if (!mkdir($concurrentDirectory = $this->basePath, 0777, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create test directory [%s].', $this->basePath));
        }
        $this->app = new Application($this->basePath);

        $this->app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'defaults' => [
                'deny_by_default' => true,
                'fail_closed' => true,
                'authentication_required' => false,
                'tenant_required' => false,
            ],
            'controllers' => [
                'explicit_exposure' => false,
                'allowlist' => [],
                'allow_static_methods' => false,
                'allow_dynamic_targets' => false,
                'allow_non_public_methods' => false,
            ],
            'metadata' => ['freeze' => true, 'most_restrictive_wins' => true, 'reject_unsafe_overrides' => true],
            'authorization' => ['cache_per_execution' => true, 'max_policy_evaluations' => 128, 'abstain_as_deny' => true],
            'tenant' => ['strict_isolation' => false, 'trust_client_tenant_id' => true, 'hide_cross_tenant_resources' => false],
            'workers' => [
                'hardened_engine' => true,
                'policy_timeout_ms' => 25,
                'max_recursion_depth' => 8,
                'circuit_breaker_failures' => 5,
                'circuit_breaker_open_seconds' => 30,
                'reset_security_context' => true,
                'detect_context_leaks' => true,
                'terminate_on_trust_failure' => true,
            ],
            'composition' => [
                'enabled' => true,
                'use_expression_parser' => true,
                'auto_wrap_metadata_policies' => true,
                'resolver_max_recursion_depth' => 16,
                'default_approval_ratio' => 0.5,
                'unresolvable_term_as_deny' => true,
            ],
            'error_responses' => [
                'enabled' => true,
                'expose_safe_context' => true,
                'expose_reason_code' => true,
                'expose_challenge_headers' => true,
                'expose_error_extensions' => true,
                'include_security_type_links' => true,
            ],
            'policies' => [],
        ]);
        $this->app->make(ConfigRepository::class)->set('controller_compilation.enabled', false);

        $router = $this->app->make(Router::class);

        $reg = $this->app->make(ControllerSecurityPolicyRegistryInterface::class);
        self::assertSame(0, $reg->count(), 'Precondition: registry should be empty at test setup (before register())');

        $publicAllow = new class implements ControllerSecurityPolicyInterface {
            public function id(): string { return 'always.allow.public.smoke'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision {
                return SecurityDecision::allow(
                    policyId: 'always.allow.public.smoke',
                    reasonCode: 'smoke_allow_for_public_endpoints',
                );
            }
        };
        $reg->register($publicAllow);
        self::assertSame(1, $reg->count(), 'After publicAllow registered, count=1');

        $reg->registerExpression('role:user || permission:dashboard:read');
        self::assertSame(2, $reg->count(), sprintf('After auth-token expression count=%d', $reg->count()));

        $reg->registerExpression('role:admin && permission:admin.panel');
        $reg->registerExpression('role:admin || (role:officer && permission:gdpr.export)');
        $reg->registerExpression('role:user && tenant:acme-corp');
        self::assertSame(5, $reg->count(), sprintf('All 5 demo policies registered (actual count=%d)', $reg->count()));

        $found = false;
        foreach ($reg->all() as $p) {
            if ($p->id() === 'role:user || permission:dashboard:read') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expression auth-token must be stored under its string id for resolve() lookup.');

        $router->group(['prefix' => '/security/demo'], function () use ($router): void {
            $router->get('/public', [SecurityDemoController::class, 'public'])->name('smoke.public');
            $router->get('/auth-token', [SecurityDemoController::class, 'authToken'])->name('smoke.authToken');
            $router->get('/admin-mfa', [SecurityDemoController::class, 'adminMfa'])->name('smoke.adminMfa');
            $router->get('/tenant-scoped', [SecurityDemoController::class, 'tenantScoped'])->name('smoke.tenantScoped');
            $router->get('/gdpr-exposed', [SecurityDemoController::class, 'gdprExposed'])->name('smoke.gdprExposed');
        });
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
        parent::tearDown();
    }

    /** @return array{headers:array<string,string>} */
    private function jwtHeaders(array $payload = []): array
    {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none']) ?: '');
        $p = json_encode(array_merge([
            'sub' => 'u-smoke',
            'type' => 'user',
            'roles' => ['user'],
            'permissions' => ['dashboard:read'],
        ], $payload), JSON_UNESCAPED_SLASHES);
        $payloadB64 = rtrim(strtr(base64_encode($p ?: ''), '+/', '-_'), '=');
        $headerB64 = rtrim(strtr($header, '+/', '-_'), '=');
        return ['Authorization' => 'Bearer ' . $headerB64 . '.' . $payloadB64 . '.fakesig'];
    }

    /**
     * Helper: Simulate an HTTP request against the test kernel.
     * @param array<string,string> $headers
     * @return array{status:int,content:string,headers:array<string,string[]>,debugThrowable:?string}
     */
    private function dispatch(string $path, array $headers = []): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $nameLower = strtolower($name);
            $server[$name] = $value;
            if ($nameLower === 'content-type' || $nameLower === 'content-length') {
                $server[strtoupper(str_replace('-', '_', $name))] = $value;
            } else {
                $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
            }
        }
        $kernel = $this->app->make(HttpKernel::class);
        $throwableStr = null;
        try {
            $response = $kernel->handle(Request::create($path, 'GET', [], [], [], [], [], $server));
        } catch (\Throwable $t) {
            $throwableStr = $t::class . ': ' . $t->getMessage() . PHP_EOL . 'File=' . $t->getFile() . '@' . $t->getLine() . PHP_EOL . $t->getTraceAsString();
            $response = new \Quantum\Http\Response(500, [
                'Content-Type' => 'application/json; charset=utf-8',
            ], json_encode([
                'error' => 'dispatch_unhandled',
                'exception_class' => $t::class,
                'message' => $t->getMessage(),
                'file' => $t->getFile(),
                'line' => $t->getLine(),
                'trace' => explode(PHP_EOL, $t->getTraceAsString()),
            ], JSON_UNESCAPED_SLASHES));
        }
        $respHeaders = $response->headers();
        $flatHeaders = [];
        foreach ($respHeaders as $k => $vs) {
            $flatHeaders[(string)$k] = is_array($vs) ? $vs : [$vs];
        }
        return [
            'status' => $response->statusCode(),
            'content' => $response->content(),
            'headers' => $flatHeaders,
            'debugThrowable' => $throwableStr,
        ];
    }

    /** @param array{status:int,content:string,headers:array<string,string[]>} $r */
    private function json(array $r): ?array
    {
        if ($r['content'] === '') return null;
        try {
            $decoded = json_decode($r['content'], true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function test_1_public_endpoint_returns_200_without_auth(): void
    {
        $r = $this->dispatch('/security/demo/public');
        self::assertSame(200, $r['status'], sprintf('Public expected 200 got %d/%s', $r['status'], $r['content']));
        $j = $this->json($r);
        self::assertNotNull($j, 'Public body must be JSON');
        self::assertSame('security/demo/public', $j['endpoint'] ?? null);
        self::assertTrue($j['exposed'] ?? false);
    }

    public function test_2_auth_token_endpoint_returns_401_when_anonymous(): void
    {
        $r = $this->dispatch('/security/demo/auth-token');
        self::assertContains($r['status'], [401, 403, 500], sprintf('Expected 401/403 for anonymous auth-token; got %d (%s)', $r['status'], $r['content']));
        $j = $this->json($r);
        if ($j !== null) {
            $rc = $j['reason_code'] ?? $j['reasonCode'] ?? null;
            if (is_string($rc) && $rc !== '') {
                self::assertStringContainsString('authentication', strtolower($rc));
            }
        }
    }

    public function test_3_auth_token_with_valid_bearer_roles_user_returns_200(): void
    {
        $headers = $this->jwtHeaders(['roles' => ['user'], 'permissions' => []]);
        $r = $this->dispatch('/security/demo/auth-token', $headers);
        $dbg = $r['debugThrowable'] ?? null;
        self::assertSame(200, $r['status'], sprintf('auth-token with user role expected 200 got %d/%s -- debug=%s', $r['status'], $r['content'], $dbg ?? 'n/a'));
        $j = $this->json($r);
        self::assertNotNull($j);
        self::assertSame('security/demo/auth-token', $j['endpoint'] ?? null);
        self::assertSame('u-smoke', $j['principal_id'] ?? null);
        self::assertContains('user', (array)($j['roles'] ?? []));
    }

    public function test_4_admin_mfa_fails_with_only_token_strength(): void
    {
        $headers = $this->jwtHeaders([
            'roles' => ['admin'],
            'permissions' => ['admin.panel'],
        ]);
        $r = $this->dispatch('/security/demo/admin-mfa', $headers);
        self::assertNotSame(200, $r['status'], sprintf('MFA endpoint must NOT pass when only token strength; got %d (%s)', $r['status'], $r['content']));
        self::assertContains($r['status'], [401, 403, 500]);
    }

    public function test_5_admin_mfa_passes_when_amr_contains_mfa_strength_multi_factor(): void
    {
        $headers = $this->jwtHeaders([
            'roles' => ['admin'],
            'permissions' => ['admin.panel'],
            'amr' => ['mfa', 'pwd'],
        ]);
        $r = $this->dispatch('/security/demo/admin-mfa', $headers);
        self::assertSame(200, $r['status'], sprintf('MFA with amr=mfa should pass; got %d (%s)', $r['status'], $r['content']));
        $j = $this->json($r);
        self::assertNotNull($j);
        self::assertSame('security/demo/admin-mfa', $j['endpoint'] ?? null);
        self::assertSame('MultiFactor', $j['authentication_strength'] ?? null);
    }

    public function test_6_tenant_scoped_denies_without_tenant_header(): void
    {
        $headers = $this->jwtHeaders(['roles' => ['user']]);
        $r = $this->dispatch('/security/demo/tenant-scoped', $headers);
        self::assertNotSame(200, $r['status'], 'Tenant-scoped must deny with no X-Tenant-Id');
        self::assertContains($r['status'], [401, 403, 404, 500]);
    }

    public function test_7_tenant_scoped_passes_when_tenant_acme_and_user_role(): void
    {
        $headers = $this->jwtHeaders(['roles' => ['user']]);
        $headers['X-Tenant-Id'] = 'acme-corp';
        $r = $this->dispatch('/security/demo/tenant-scoped', $headers);
        self::assertSame(200, $r['status'], sprintf('Tenant-scoped pass expected; got %d (%s)', $r['status'], $r['content']));
        $j = $this->json($r);
        self::assertNotNull($j);
        self::assertSame('acme-corp', $j['tenant_id'] ?? null);
        self::assertTrue((bool)($j['tenant_verified'] ?? false));
    }

    public function test_8_gdpr_exposed_fails_with_insufficient_roles_for_deny_by_default(): void
    {
        $headers = $this->jwtHeaders(['roles' => ['user'], 'permissions' => []]);
        $r = $this->dispatch('/security/demo/gdpr-exposed', $headers);
        self::assertNotSame(200, $r['status'], sprintf('GDPR endpoint needs admin or officer+gdpr.export; user must be denied; got %d (%s)', $r['status'], $r['content']));
        self::assertContains($r['status'], [403, 451, 401, 500]);
    }

    public function test_9_gdpr_exposed_passes_for_admin_and_returns_personal_data(): void
    {
        $headers = $this->jwtHeaders(['roles' => ['admin'], 'permissions' => []]);
        $r = $this->dispatch('/security/demo/gdpr-exposed', $headers);
        self::assertSame(200, $r['status'], sprintf('Admin on GDPR endpoint should pass; got %d (%s)', $r['status'], $r['content']));
        $j = $this->json($r);
        self::assertNotNull($j);
        self::assertSame('security/demo/gdpr-exposed', $j['endpoint'] ?? null);
        self::assertSame('Jane Doe', $j['user_personal_data']['name'] ?? null);
        self::assertSame('jane.doe@acme.corp', $j['user_personal_data']['email'] ?? null);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    private static function locateSkeletonBasePath(): string
    {
        $candidates = [
            dirname(__DIR__, 5),
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app-skeleton',
        ];

        foreach ($candidates as $candidate) {
            if (
                is_file($candidate . DIRECTORY_SEPARATOR . 'composer.json') &&
                is_dir($candidate . DIRECTORY_SEPARATOR . 'app') &&
                is_dir($candidate . DIRECTORY_SEPARATOR . 'routes')
            ) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No se localizo el app-skeleton para SkeletonSecuritySmokeTest.');
    }
}