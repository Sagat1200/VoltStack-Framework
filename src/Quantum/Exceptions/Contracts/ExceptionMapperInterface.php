<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Http\Request;
use Throwable;

interface ExceptionMapperInterface
{
    public function statusCode(Throwable $throwable): ?int;

    /**
     * @return array<string, string>
     */
    public function headers(Throwable $throwable): array;

    /**
     * @return array<string, mixed>
     */
    public function jsonExtensions(Throwable $throwable, bool $debug): array;

    /**
     * @return array<string, mixed>
     */
    public function voltExtensions(Throwable $throwable, bool $debug): array;

    public function message(Throwable $throwable, int $status): ?string;

    public function errorCode(Throwable $throwable, int $status): ?string;

    public function htmlBody(Throwable $throwable, int $status): ?string;
}
