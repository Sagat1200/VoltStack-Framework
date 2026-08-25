<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use JsonException;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseIdempotencyAcquireResult;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;

final class DirectoryDatabaseIdempotencyStore implements DatabaseIdempotencyStoreInterface
{
    public function __construct(
        private readonly string $directoryPath,
    ) {}

    public function acquire(DatabaseIdempotencyRecord $record): DatabaseIdempotencyAcquireResult
    {
        if (!is_dir($this->directoryPath)) {
            mkdir($this->directoryPath, 0777, true);
        }

        $filePath = $this->filePathForHash($record->keyHash);
        if (!is_file($filePath)) {
            $this->writeRecord($filePath, $record);

            return DatabaseIdempotencyAcquireResult::acquired($record);
        }

        $existing = $this->readRecord($filePath);
        if (!$existing instanceof DatabaseIdempotencyRecord) {
            $this->writeRecord($filePath, $record);

            return DatabaseIdempotencyAcquireResult::acquired($record);
        }

        if ($existing->operationFingerprint === $record->operationFingerprint) {
            return DatabaseIdempotencyAcquireResult::duplicate($existing);
        }

        return DatabaseIdempotencyAcquireResult::conflict($existing);
    }

    public function complete(DatabaseIdempotencyRecord $record): void
    {
        $this->writeRecord($this->filePathForHash($record->keyHash), $record->withStatus('completed'));
    }

    public function fail(DatabaseIdempotencyRecord $record): void
    {
        $this->writeRecord($this->filePathForHash($record->keyHash), $record->withStatus('failed'));
    }

    public function release(DatabaseIdempotencyRecord $record): void
    {
        $filePath = $this->filePathForHash($record->keyHash);
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    private function filePathForHash(string $keyHash): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $keyHash);
        $safe = is_string($safe) && trim($safe) !== '' ? $safe : 'unknown-key';

        return $this->directoryPath . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private function readRecord(string $filePath): ?DatabaseIdempotencyRecord
    {
        $contents = file_get_contents($filePath);
        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to decode database idempotency record.', 0, $exception);
        }

        return is_array($payload) ? DatabaseIdempotencyRecord::fromArray($payload) : null;
    }

    private function writeRecord(string $filePath, DatabaseIdempotencyRecord $record): void
    {
        try {
            $json = json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode database idempotency record.', 0, $exception);
        }

        file_put_contents($filePath, $json, LOCK_EX);
    }
}
