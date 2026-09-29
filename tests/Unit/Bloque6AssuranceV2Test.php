<?php

declare(strict_types=1);

namespace Quantum\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Exceptions\AssuranceInsufficientException;
use Quantum\Auth\Exceptions\AuthExceptionMapper;

final class Bloque6AssuranceV2Test extends TestCase
{
    public function test_b6_01_assurance_exception_maps_to_423(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new AssuranceInsufficientException(requiredMinAssurance: 400, currentAssurance: 100, operation: 'funds.transfer');
        $this->assertSame(423, $mapper->statusCode($e));
        $this->assertSame('auth.assurance_insufficient', $e->reasonCode);
    }

    public function test_b6_02_headers_include_required_and_current(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new AssuranceInsufficientException(requiredMinAssurance: 500, currentAssurance: 150, operation: 'admin.settings');
        $h = $mapper->headers($e);
        $this->assertSame('true', $h['X-Auth-Assurance-Insufficient']);
        $this->assertSame('500', $h['X-Auth-Assurance-Required-Min']);
        $this->assertSame('150', $h['X-Auth-Assurance-Current']);
        $this->assertSame('admin.settings', $h['X-Auth-Operation']);
    }

    public function test_b6_03_constructor_public_props(): void
    {
        $e = new AssuranceInsufficientException(
            message: 'Needs stronger method',
            requiredMinAssurance: 600,
            currentAssurance: 200,
            operation: 'sensitive.delete',
        );
        $this->assertSame(600, $e->requiredMinAssurance);
        $this->assertSame(200, $e->currentAssurance);
        $this->assertSame('sensitive.delete', $e->operation);
        $this->assertStringContainsStringIgnoringCase('stronger', $e->getMessage());
    }

    public function test_b6_04_json_extensions_fields(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new AssuranceInsufficientException(requiredMinAssurance: 300, currentAssurance: 80, operation: 'profile.update_email');
        $ext = $mapper->jsonExtensions($e, false);
        $this->assertSame('auth.assurance_insufficient', $ext['reason_code']);
        $this->assertSame('300', $ext['required_min_assurance']);
        $this->assertSame('80', $ext['current_assurance']);
        $this->assertSame('profile.update_email', $ext['operation']);
    }

    public function test_b6_05_mapper_error_code_and_message(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new AssuranceInsufficientException(requiredMinAssurance: 350, currentAssurance: 50, operation: 'op');
        $this->assertSame('auth.assurance_insufficient', $mapper->errorCode($e, 423));
        $this->assertSame($e->getMessage(), $mapper->message($e, 423));
    }

    public function test_b6_06_html_body_mentions_mfa_or_passkey(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new AssuranceInsufficientException();
        $body = $mapper->htmlBody($e, 423);
        $this->assertNotNull($body);
        $lower = strtolower($body);
        $hasKeyword = str_contains($lower, 'mfa') || str_contains($lower, 'passkey') || str_contains($lower, 'stronger');
        $this->assertTrue($hasKeyword, 'Expected HTML body to reference MFA, passkey or stronger method.');
    }
}
