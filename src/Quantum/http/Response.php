<?php

declare(strict_types=1);

namespace Quantum\Http;

class Response
{
    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        protected string $content = '',
        protected int $statusCode = 200,
        protected array $headers = [],
    ) {
    }

    public function content(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): static
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, string $value): static
    {
        if (isset($this->headers[$name])) {
            $existing = $this->headers[$name];

            if (is_array($existing)) {
                $existing[] = $value;
                $this->headers[$name] = array_values($existing);

                return $this;
            }

            if (strcasecmp($name, 'Set-Cookie') === 0) {
                $this->headers[$name] = [$existing, $value];

                return $this;
            }
        }

        $this->headers[$name] = $value;

        return $this;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $index => $headerValue) {
                    header($name . ': ' . $headerValue, $index === 0);
                }

                continue;
            }

            header($name . ': ' . $value, true);
        }

        echo $this->content;
    }
}
