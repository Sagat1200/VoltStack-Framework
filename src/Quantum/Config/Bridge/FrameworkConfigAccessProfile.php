<?php

declare(strict_types=1);

namespace Quantum\Config\Bridge;

final class FrameworkConfigAccessProfile
{
    public static function build(): ConfigAccessRegistry
    {
        $registry = new ConfigAccessRegistry();

        $registry->static('app.env', 'Application::environment');
        $registry->static('app.debug', 'ExceptionHandler shutdown fallback');
        $registry->static('cache.compiled.views', 'CompiledViewStore');
        $registry->static('telemetry.exporter', 'TelemetryExporterInterface');
        $registry->static('telemetry.jsonl_path', 'TelemetryExporterInterface');
        $registry->static('telemetry.webhook_url', 'TelemetryExporterInterface');
        $registry->static('telemetry.webhook_headers', 'TelemetryExporterInterface');
        $registry->static('telemetry.webhook_timeout_ms', 'TelemetryExporterInterface');
        $registry->static('controller_observability.dispatcher', 'ControllerEventDispatcherInterface');
        $registry->static('controller_observability.jsonl_path', 'ControllerEventDispatcherInterface');
        $registry->static('controller_compilation.paths', 'Artifact stores');
        $registry->static('controller_compilation.artifacts.format', 'Artifact stores');
        $registry->static('controller_compilation.cache', 'CompiledControllerFactory');
        $registry->scoped('controller_security.authorization.max_policy_evaluations', 'ControllerSecurityContextFactoryInterface', 'request');

        return $registry;
    }
}
