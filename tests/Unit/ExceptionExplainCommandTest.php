<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\ExceptionExplainCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class ExceptionExplainCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-exception-explain-command-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Exception Explain',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'exceptions.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'environment' => 'development',
    'debug' => false,
    'runtime' => 'sapi',
    'rendering' => [
        'api_format' => 'problem_json',
        'browser_format' => 'html',
        'spa_versions' => [1],
        'cache_control' => 'no-store',
    ],
    'reporting' => [
        'enabled' => true,
        'sample_rate' => 1.0,
        'ignore_codes' => ['validation.failed'],
        'reporters' => ['exceptions.log'],
    ],
    'recovery' => [
        'automatic_replay' => false,
    ],
];
PHP
        );

        $this->writeBootstrapApp();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_exception_explain_command_reports_mapping_and_transport_plan_for_json_fixture(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=validation',
                '--transport=json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('exceptions:explain', $decoded['command'] ?? null);
        self::assertSame('validation', $decoded['report']['fixture']['type'] ?? null);
        self::assertSame('json', $decoded['report']['fixture']['transport'] ?? null);
        self::assertSame('validation.failed', $decoded['report']['mapping']['code'] ?? null);
        self::assertSame('validation.failed', $decoded['report']['mapping']['matched_rule']['id'] ?? null);
        self::assertSame('http.problem_json', $decoded['report']['transport_plan']['target'] ?? null);
        self::assertSame(422, $decoded['report']['transport_plan']['status'] ?? null);
        self::assertSame(false, $decoded['report']['mapping']['used_fallback'] ?? null);
        self::assertSame('application/problem+json', $decoded['report']['rendered']['media_type'] ?? null);
        self::assertSame(true, $decoded['report']['reporting']['ignored_by_policy'] ?? null);
    }

    public function test_exception_explain_command_supports_configuration_fixture_and_cli_json_transport(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=config_invalid',
                '--transport=cli-json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('config_invalid', $decoded['report']['fixture']['type'] ?? null);
        self::assertSame('configuration.invalid', $decoded['report']['mapping']['code'] ?? null);
        self::assertSame('invalid.argument.configuration', $decoded['report']['mapping']['matched_rule']['id'] ?? null);
        self::assertSame('cli.json', $decoded['report']['transport_plan']['target'] ?? null);
        self::assertSame(78, $decoded['report']['transport_plan']['exit_code'] ?? null);
        self::assertSame('application/json', $decoded['report']['rendered']['media_type'] ?? null);
    }

    public function test_exception_explain_command_reports_unknown_fixtures_cleanly(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=unknown-fixture',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(null, $decoded['report'] ?? null);
        self::assertIsString($decoded['error'] ?? null);
        self::assertStringContainsString('Unknown explain fixture', $decoded['error']);
        self::assertStringContainsString('cancelled', $decoded['error']);
    }

    public function test_exception_explain_command_emits_reporting_decision_and_recovery_shape(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=validation',
                '--transport=json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        $report = $decoded['report'] ?? null;
        self::assertIsArray($report);
        self::assertIsArray($report['reporting']['decision'] ?? null);
        self::assertArrayHasKey('should_report', $report['reporting']['decision']);
        self::assertArrayHasKey('ignored_by_policy', $report['reporting']['decision']);
        self::assertArrayHasKey('reasons', $report['reporting']['decision']);
        self::assertIsArray($report['reporting']['receipts'] ?? null);
        self::assertIsArray($report['recovery']['decision'] ?? null);
        $recovery = $report['recovery']['decision'];
        self::assertArrayHasKey('simulated', $recovery);
        self::assertArrayHasKey('action', $recovery);
        self::assertArrayHasKey('reason_code', $recovery);
        self::assertArrayHasKey('automatic_replay_enabled', $recovery);
        self::assertArrayHasKey('replay_allowed_for_code', $recovery);
        self::assertArrayHasKey('fallback_action', $recovery);
    }

    public function test_exception_explain_command_cancelled_fixture_triggers_recovery_abort(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=cancelled',
                '--transport=json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('cancelled', $decoded['report']['fixture']['type'] ?? null);
        self::assertSame('abort', $decoded['report']['recovery']['decision']['action'] ?? null);
        self::assertSame('operation.cancelled', $decoded['report']['recovery']['decision']['reason_code'] ?? null);
        self::assertSame('reject_surface', $decoded['report']['recovery']['decision']['fallback_action'] ?? null);
    }

    public function test_exception_explain_command_db_default_fixture_triggers_recovery_reconcile(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=db_default',
                '--transport=json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('db_default', $decoded['report']['fixture']['type'] ?? null);
        self::assertSame('operation.indeterminate', $decoded['report']['mapping']['code'] ?? null);
        self::assertSame('reconcile', $decoded['report']['recovery']['decision']['action'] ?? null);
        self::assertSame('invoke_reconcile_hook', $decoded['report']['recovery']['decision']['fallback_action'] ?? null);
    }

    public function test_exception_explain_command_default_text_run_keeps_stable_output_without_receipts_section(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=validation',
                '--transport=json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $stdout = $output->stdout();
        self::assertStringContainsString('Fixture: validation', $stdout);
        self::assertStringContainsString('Code: validation.failed', $stdout);
        self::assertStringNotContainsString('Reporting receipts:', $stdout);
        self::assertStringNotContainsString('Recovery:', $stdout);
    }

    public function test_exception_explain_command_show_receipts_emits_policy_skipped_validation_receipt_and_recovery_shape(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=validation',
                '--transport=json',
                '--json',
                '--show-receipts',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);

        $reporting = $decoded['report']['reporting'] ?? null;
        self::assertIsArray($reporting);
        self::assertSame(true, $reporting['ignored_by_policy'] ?? null);
        self::assertSame(false, $reporting['decision']['should_report'] ?? null);
        self::assertContains('policy_ignored', (array) ($reporting['decision']['reasons'] ?? []));
        self::assertIsArray($reporting['receipts'] ?? null);
        self::assertNotSame([], $reporting['receipts']);

        $logReceipt = null;
        foreach ($reporting['receipts'] as $receipt) {
            if (($receipt['reporter_id'] ?? null) === 'exceptions.log') {
                $logReceipt = $receipt;
                break;
            }
        }
        self::assertNotNull($logReceipt);
        self::assertSame('skipped', $logReceipt['state'] ?? null);
        self::assertSame('policy_ignored', $logReceipt['reason_code'] ?? null);

        $recovery = $decoded['report']['recovery']['decision'] ?? null;
        self::assertIsArray($recovery);
        self::assertSame(false, $recovery['automatic_replay_enabled'] ?? null);
        self::assertSame(false, $recovery['replay_allowed_for_code'] ?? null);
        self::assertContains('automatic_replay_disabled', (array) ($recovery['reasons'] ?? []));
        self::assertSame(true, $recovery['simulated'] ?? null);
    }

    public function test_exception_explain_command_show_receipts_allows_reporting_for_configuration_invalid(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=config_invalid',
                '--transport=cli-json',
                '--json',
                '--show-receipts',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);

        $reporting = $decoded['report']['reporting'] ?? null;
        self::assertIsArray($reporting);
        self::assertSame(false, $reporting['ignored_by_policy'] ?? null);
        self::assertSame(true, $reporting['decision']['should_report'] ?? null);
        self::assertIsArray($reporting['receipts'] ?? null);
        self::assertNotSame([], $reporting['receipts']);

        $logReceipt = null;
        foreach ($reporting['receipts'] as $receipt) {
            if (($receipt['reporter_id'] ?? null) === 'exceptions.log') {
                $logReceipt = $receipt;
                break;
            }
        }
        self::assertNotNull($logReceipt);
        self::assertSame('accepted', $logReceipt['state'] ?? null);

        $recovery = $decoded['report']['recovery']['decision'] ?? null;
        self::assertIsArray($recovery);
        self::assertSame('configuration.invalid', $recovery['reason_code'] ?? null);
        self::assertSame('surface_error', $recovery['fallback_action'] ?? null);
    }

    public function test_exception_explain_command_default_run_has_no_bridge_matrix(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=validation',
                '--transport=json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('bridge_matrix', $decoded['report'] ?? []);
    }

    public function test_exception_explain_command_matrix_emits_five_bridges_with_expected_shapes(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=validation',
                '--json',
                '--matrix',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);

        $matrix = $decoded['report']['bridge_matrix'] ?? null;
        self::assertIsArray($matrix);
        self::assertCount(5, $matrix);

        $bridges = array_map(
            static fn (array $row): string => is_string($row['bridge'] ?? null) ? $row['bridge'] : '',
            $matrix,
        );
        self::assertSame(['json', 'html', 'spa', 'cli', 'cli-json'], $bridges);

        $jsonRow = $matrix[0];
        self::assertSame('http', $jsonRow['transport_kind'] ?? null);
        self::assertSame('api', $jsonRow['route_profile'] ?? null);
        self::assertSame(422, $jsonRow['status'] ?? null);
        self::assertSame('http.problem_json', $jsonRow['target'] ?? null);
        self::assertSame(true, $jsonRow['reporting']['ignored_by_policy'] ?? null);
        self::assertContains('policy_ignored', (array) ($jsonRow['reporting']['reasons'] ?? []));
        self::assertSame('validation.failed', $jsonRow['mapping']['code'] ?? null);
        self::assertSame(false, $jsonRow['recovery']['replay_allowed_for_code'] ?? null);
        self::assertSame('surface_error', $jsonRow['recovery']['fallback_action'] ?? null);
        self::assertIsArray($jsonRow['receipts'] ?? null);
        self::assertNotSame([], $jsonRow['receipts']);

        $spaRow = $matrix[2];
        self::assertSame('spa', $spaRow['bridge'] ?? null);
        self::assertSame('http', $spaRow['transport_kind'] ?? null);
        self::assertSame('spa', $spaRow['route_profile'] ?? null);
        self::assertStringStartsWith('application/vnd.voltstack.spa-error+json', (string) ($spaRow['media_type'] ?? ''));

        $cliJsonRow = $matrix[4];
        self::assertSame('cli', $cliJsonRow['transport_kind'] ?? null);
        self::assertSame('structured', $cliJsonRow['route_profile'] ?? null);
        self::assertSame('cli.json', $cliJsonRow['target'] ?? null);
    }

    public function test_exception_explain_command_matrix_with_show_receipts_includes_receipts_per_bridge(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=config_invalid',
                '--json',
                '--matrix',
                '--show-receipts',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);

        $matrix = $decoded['report']['bridge_matrix'] ?? [];
        self::assertIsArray($matrix);
        self::assertCount(5, $matrix);

        foreach ($matrix as $row) {
            self::assertArrayHasKey('receipts', $row);
            self::assertIsArray($row['receipts']);
            self::assertNotSame([], $row['receipts']);
            $logReceipt = null;
            foreach ($row['receipts'] as $receipt) {
                if (($receipt['reporter_id'] ?? null) === 'exceptions.log') {
                    $logReceipt = $receipt;
                    break;
                }
            }
            self::assertNotNull($logReceipt);
            self::assertSame('accepted', $logReceipt['state'] ?? null);
            self::assertSame(false, $row['reporting']['ignored_by_policy'] ?? null);
        }
    }

    public function test_exception_explain_command_emits_transport_bridge_diagnostic_and_receipts_matrix_in_json_payload(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=db_default',
                '--transport=json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        $report = $decoded['report'] ?? null;
        self::assertIsArray($report);

        $transport = $report['transport'] ?? null;
        self::assertIsArray($transport);
        self::assertSame('composite', $transport['bridge'] ?? null);
        self::assertSame('http', $transport['kind'] ?? null);
        self::assertSame('api', $transport['route_profile'] ?? null);
        self::assertSame('http.problem_json', $transport['target'] ?? null);
        self::assertIsArray($transport['http'] ?? null);
        self::assertSame(503, $transport['http']['status'] ?? null);
        self::assertSame(false, $transport['http']['has_problem_extensions'] ?? null);

        $matrix = $report['reporting']['receipts_matrix'] ?? null;
        self::assertIsArray($matrix);
        self::assertArrayHasKey('exceptions.log', $matrix);
        $logCount = $matrix['exceptions.log'];
        self::assertSame(1, $logCount['accepted'] ?? null);
        self::assertSame(0, $logCount['dropped'] ?? null);
        self::assertSame(0, $logCount['failed'] ?? null);
        self::assertSame(0, $logCount['skipped'] ?? null);
        self::assertSame(1, $logCount['total'] ?? null);
    }

    public function test_exception_explain_command_show_receipts_emits_bridge_and_receipts_matrix_text_blocks(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=cancelled',
                '--transport=spa',
                '--show-receipts',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $stdout = $output->stdout();
        self::assertStringContainsString('Bridge:', $stdout);
        self::assertStringContainsString('Bridge: composite', $stdout);
        self::assertStringContainsString('Kind: http', $stdout);
        self::assertStringContainsString('Route profile: spa', $stdout);
        self::assertStringContainsString('SPA:', $stdout);
        self::assertStringContainsString('Receipts matrix:', $stdout);
        self::assertStringContainsString('exceptions.log:', $stdout);
    }

    public function test_exception_explain_command_default_run_has_no_effects_or_runtime_blocks(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=validation',
                '--transport=json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        $report = $decoded['report'] ?? [];
        self::assertArrayNotHasKey('effect_matrix', $report);
        self::assertArrayNotHasKey('receipts_matrix_by_scope', $report);
        self::assertArrayNotHasKey('runtime', $report);
    }

    public function test_exception_explain_command_effects_emits_effect_matrix_runtime_and_receipts_by_scope(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=validation',
                '--json',
                '--matrix',
                '--show-receipts',
                '--effects',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        $report = $decoded['report'] ?? null;
        self::assertIsArray($report);

        self::assertIsArray($report['effect_matrix'] ?? null);
        self::assertNotSame([], $report['effect_matrix']);
        $noneRow = null;
        foreach ($report['effect_matrix'] as $row) {
            if (($row['effect'] ?? null) === 'none') {
                $noneRow = $row;
                break;
            }
        }
        self::assertNotNull($noneRow);
        self::assertGreaterThanOrEqual(5, $noneRow['rows'] ?? 0);
        self::assertContains('json', (array) ($noneRow['bridges'] ?? []));
        self::assertContains('cli', (array) ($noneRow['bridges'] ?? []));
        self::assertContains('validation.failed', (array) ($noneRow['semantic_codes'] ?? []));

        self::assertIsArray($report['runtime'] ?? null);
        self::assertCount(2, $report['runtime']['scopes'] ?? []);
        self::assertSame(['runtime_http', 'runtime_job'], array_map(
            static fn (array $row): string => is_string($row['bridge'] ?? null) ? $row['bridge'] : '',
            (array) $report['runtime']['scopes'],
        ));
        self::assertSame('http', $report['runtime']['scopes'][0]['transport'] ?? null);
        self::assertSame('cli', $report['runtime']['scopes'][1]['transport'] ?? null);
        self::assertSame(true, $report['runtime']['scopes'][0]['finalized'] ?? null);
        self::assertSame('completed', is_array($report['runtime']['scopes'][0]['finalization'] ?? null) ? ($report['runtime']['scopes'][0]['finalization']['kind'] ?? null) : null);
        self::assertSame(2, $report['runtime']['reset']['scopes_closed'] ?? null);
        self::assertSame(false, $report['runtime']['reset']['leak_detected'] ?? null);

        self::assertIsArray($report['receipts_matrix_by_scope'] ?? null);
        self::assertNotSame([], $report['receipts_matrix_by_scope']);
        $hasMainScope = false;
        $hasAnyBridgeScope = false;
        foreach (array_keys($report['receipts_matrix_by_scope']) as $scopeId) {
            if (str_starts_with((string) $scopeId, 'scope.main.')) {
                $hasMainScope = true;
            } elseif (is_string($scopeId)) {
                $hasAnyBridgeScope = true;
            }
        }
        self::assertTrue($hasMainScope);
        self::assertTrue($hasAnyBridgeScope);
        foreach ($report['receipts_matrix_by_scope'] as $scopeReceipts) {
            $log = null;
            foreach ((array) $scopeReceipts as $receipt) {
                if (($receipt['reporter_id'] ?? null) === 'exceptions.log') {
                    $log = $receipt;
                    break;
                }
            }
            self::assertNotNull($log);
            self::assertSame('skipped', $log['state'] ?? null);
            self::assertSame('policy_ignored', $log['reason_code'] ?? null);
        }
    }

    public function test_exception_explain_command_effects_cancelled_produces_abort_effect_and_failed_finalization(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=cancelled',
                '--json',
                '--matrix',
                '--effects',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        $report = $decoded['report'] ?? null;
        self::assertIsArray($report);

        $abortRow = null;
        foreach ((array) ($report['effect_matrix'] ?? []) as $row) {
            if (($row['effect'] ?? null) === 'abort') {
                $abortRow = $row;
                break;
            }
        }
        self::assertNotNull($abortRow);
        self::assertGreaterThanOrEqual(2, $abortRow['rows'] ?? 0);
        self::assertContains('runtime_http', (array) ($abortRow['bridges'] ?? []));
        self::assertContains('runtime_job', (array) ($abortRow['bridges'] ?? []));
        self::assertContains('operation.cancelled', (array) ($abortRow['semantic_codes'] ?? []));

        self::assertIsArray($report['runtime'] ?? null);
        foreach ((array) ($report['runtime']['scopes'] ?? []) as $scopeRow) {
            self::assertSame('failed', is_array($scopeRow['finalization'] ?? null) ? ($scopeRow['finalization']['kind'] ?? null) : null);
            self::assertSame('cancelled', is_array($scopeRow['finalization']['metadata'] ?? null) ? ($scopeRow['finalization']['metadata']['fixture'] ?? null) : null);
        }
    }

    public function test_exception_explain_command_emits_stream_confirmation_in_json_payload_with_expected_shape(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=db_default',
                '--transport=json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        $stream = $decoded['report']['reporting']['stream_confirmation'] ?? null;
        self::assertIsArray($stream);
        self::assertSame('quantum.exceptions.occurrence_stream.emulated_persistent', $stream['emitter'] ?? null);
        self::assertSame(true, $stream['stream_written'] ?? null);
        self::assertNotEmpty($stream['occurrence_id'] ?? null);
        self::assertIsArray($stream['delivery_ids_by_reporter'] ?? null);
        self::assertArrayHasKey('exceptions.log', $stream['delivery_ids_by_reporter']);
        self::assertIsArray($stream['delivery_ids_by_reporter']['exceptions.log'] ?? null);
        self::assertNotSame([], $stream['delivery_ids_by_reporter']['exceptions.log']);
        self::assertIsArray($stream['finalization'] ?? null);
        self::assertContains(
            $stream['finalization']['state'] ?? null,
            ['stream_accepted', 'stream_duplication_guarded'],
        );
        self::assertSame(1, $stream['finalization']['reporter_count'] ?? null);
        self::assertGreaterThanOrEqual(1, $stream['finalization']['delivery_count'] ?? null);
        self::assertIsArray($stream['deduplication'] ?? null);
    }

    public function test_exception_explain_command_show_receipts_emits_stream_text_block_and_deliveries_preview(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=db_retryable',
                '--transport=json',
                '--show-receipts',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $stdout = $output->stdout();
        self::assertStringContainsString('Stream:', $stdout);
        self::assertStringContainsString('Emitter: quantum.exceptions.occurrence_stream.emulated_persistent', $stdout);
        self::assertStringContainsString('Stream written: yes', $stdout);
        self::assertStringContainsString('Finalization: state=stream_', $stdout);
        self::assertStringContainsString('Deliveries by reporter:', $stdout);
        self::assertStringContainsString('exceptions.log (', $stdout);
    }

    private function writeBootstrapApp(): void
    {
        $escapedBasePath = var_export($this->basePath, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();
\$app->boot();

return \$app;
PHP
        );
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
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($target) || is_link($target)) {
                @unlink($target);
                continue;
            }

            $this->deleteDirectory($target);
        }

        @rmdir($path);
    }
}
