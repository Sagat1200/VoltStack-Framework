<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Core;

use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\PublicError;

final readonly class PublicErrorProjector
{
    public function project(ExceptionDescriptor $descriptor): PublicError
    {
        return new PublicError(
            code: $descriptor->semantic->code,
            message: $this->messageFor($descriptor->semantic->code),
            occurrenceId: $descriptor->occurrenceId,
            fields: $this->fieldsFor($descriptor),
        );
    }

    private function messageFor(string $code): string
    {
        return match ($code) {
            'validation.failed' => 'The submitted data is invalid.',
            'authentication.required' => 'Authentication is required to continue.',
            'authentication.failed' => 'Authentication failed.',
            'authorization.denied' => 'You are not allowed to perform this operation.',
            'authorization.challenge' => 'Additional authorization is required.',
            'resource.not_found' => 'The requested resource was not found.',
            'resource.conflict' => 'The operation conflicts with the current resource state.',
            'request.throttled' => 'Too many attempts were detected. Please try again later.',
            'dependency.unavailable' => 'The service is temporarily unavailable.',
            'operation.indeterminate' => 'The result of the operation could not be confirmed.',
            'operation.cancelled' => 'The operation was cancelled.',
            'configuration.invalid' => 'The service configuration is invalid.',
            default => 'An internal error occurred.',
        };
    }

    /**
     * @return array<int, array<string, scalar|array|null>>|null
     */
    private function fieldsFor(ExceptionDescriptor $descriptor): ?array
    {
        if ($descriptor->semantic->code !== 'validation.failed') {
            return null;
        }

        $fields = $descriptor->semantic->safeParameters['fields'] ?? null;

        if (! is_array($fields)) {
            return null;
        }

        $result = [];

        foreach ($fields as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $result[] = [
                'path' => '/' . ltrim($field, '/'),
                'code' => 'validation.invalid',
            ];
        }

        return $result === [] ? null : $result;
    }
}
