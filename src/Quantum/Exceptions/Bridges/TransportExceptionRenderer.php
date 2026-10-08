<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges;

use Quantum\Exceptions\Bridges\Http\Json\ProblemDetailsExceptionRenderer;
use Quantum\Exceptions\Bridges\Spa\SpaExceptionRenderer;
use Quantum\Exceptions\Contracts\ExceptionRendererInterface;
use Quantum\Exceptions\Model\PublicError;
use Quantum\Exceptions\Model\RenderedOutput;
use Quantum\Exceptions\Model\TransportPlan;

final class TransportExceptionRenderer implements ExceptionRendererInterface
{
    private readonly ProblemDetailsExceptionRenderer $problemDetailsRenderer;
    private readonly SpaExceptionRenderer $spaRenderer;

    public function __construct(
        ?ProblemDetailsExceptionRenderer $problemDetailsRenderer = null,
        ?SpaExceptionRenderer $spaRenderer = null,
    )
    {
        $this->problemDetailsRenderer = $problemDetailsRenderer ?? new ProblemDetailsExceptionRenderer();
        $this->spaRenderer = $spaRenderer ?? new SpaExceptionRenderer();
    }

    public function render(PublicError $error, TransportPlan $plan): RenderedOutput
    {
        return match ($plan->target) {
            'spa.error.v1' => $this->spaRenderer->render($error, $plan),
            'http.problem_json' => $this->problemDetailsRenderer->render($error, $plan),
            'http.json' => $this->renderHttpJson($error, $plan),
            'http.html' => $this->renderHttpHtml($error, $plan),
            'cli.json' => $this->renderCliJson($error, $plan),
            'cli.text' => $this->renderCliText($error, $plan),
            default => $this->renderFallback($error, $plan),
        };
    }

    private function renderHttpJson(PublicError $error, TransportPlan $plan): RenderedOutput
    {
        $payload = [
            'code' => $error->code,
            'message' => $error->message,
            'occurrence_id' => $error->occurrenceId,
        ];

        if ($plan->status !== null) {
            $payload['status'] = $plan->status;
        }

        if ($error->fields !== null) {
            $payload['fields'] = $error->fields;
        }

        return new RenderedOutput(
            target: $plan->target,
            bodyBytes: $this->json($payload),
            mediaType: 'application/json',
            status: $plan->status,
            safeHeaders: $plan->headers,
        );
    }

    private function renderHttpHtml(PublicError $error, TransportPlan $plan): RenderedOutput
    {
        $language = $this->header($plan, 'Content-Language') ?? 'en';
        $title = $this->escapeHtml($error->message);
        $occurrenceId = $this->escapeHtml($error->occurrenceId);
        $body = "<!doctype html>\n"
            . sprintf("<html lang=\"%s\">\n", $this->escapeHtml($language))
            . "<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . sprintf("<title>%s</title>\n", $title)
            . "<main>\n"
            . sprintf("  <h1>%s</h1>\n", $title)
            . sprintf("  <p>%s</p>\n", $title)
            . sprintf("  <p>Reference: <code>%s</code></p>\n", $occurrenceId)
            . "</main>";

        return new RenderedOutput(
            target: $plan->target,
            bodyBytes: $body,
            mediaType: 'text/html; charset=utf-8',
            status: $plan->status,
            safeHeaders: $plan->headers,
        );
    }

    private function renderCliJson(PublicError $error, TransportPlan $plan): RenderedOutput
    {
        return new RenderedOutput(
            target: $plan->target,
            bodyBytes: $this->json([
                'version' => 1,
                'code' => $error->code,
                'message' => $error->message,
                'occurrence_id' => $error->occurrenceId,
                'exit_code' => $plan->exitCode ?? 1,
            ]) . PHP_EOL,
            mediaType: 'application/json',
            exitCode: $plan->exitCode ?? 1,
        );
    }

    private function renderCliText(PublicError $error, TransportPlan $plan): RenderedOutput
    {
        $body = sprintf(
            "%s%sReference: %s%sCode: %s%s",
            $this->stripControl($error->message),
            PHP_EOL,
            $this->stripControl($error->occurrenceId),
            PHP_EOL,
            $this->stripControl($error->code),
            PHP_EOL,
        );

        return new RenderedOutput(
            target: $plan->target,
            bodyBytes: $body,
            mediaType: 'text/plain; charset=utf-8',
            exitCode: $plan->exitCode ?? 1,
        );
    }

    private function renderFallback(PublicError $error, TransportPlan $plan): RenderedOutput
    {
        return new RenderedOutput(
            target: $plan->target,
            bodyBytes: $this->stripControl($error->message),
            mediaType: 'text/plain; charset=utf-8',
            status: $plan->status,
            exitCode: $plan->exitCode,
            safeHeaders: $plan->headers,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new \RuntimeException('TransportExceptionRenderer failed to encode JSON output.');
        }

        return $encoded;
    }

    private function header(TransportPlan $plan, string $name): ?string
    {
        foreach ($plan->headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function stripControl(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;
    }
}
