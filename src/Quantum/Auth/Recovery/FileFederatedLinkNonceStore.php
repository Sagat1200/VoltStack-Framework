<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\FederatedLinkNonceStoreInterface;
use RuntimeException;

/**
 * Implementación en archivo JSONL del almacén de nonces de FederatedLink.
 *
 * Cada línea representa un nonce consumido: {"nonce":"...","consumed_at":...}.
 * La operación `consume()` es atómica: lee todo el archivo, verifica que el
 * nonce no exista, y lo apendea con `LOCK_EX`. Si ya existe, retorna false.
 */
final class FileFederatedLinkNonceStore implements FederatedLinkNonceStoreInterface
{
    public function __construct(
        private readonly string $storagePath,
    ) {
        $directory = dirname($this->storagePath());

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Unable to create federated link nonce directory [%s].',
                $directory,
            ));
        }
    }

    public function consume(string $nonce): bool
    {
        $key = trim($nonce);

        if ($key === '') {
            return false;
        }

        $path = $this->storagePath();
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return false;
            }

            // Verificar si el nonce ya fue consumido.
            rewind($handle);
            $already = false;
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($data) && isset($data['nonce']) && is_string($data['nonce']) && hash_equals($data['nonce'], $key)) {
                    $already = true;
                    break;
                }
            }

            if ($already) {
                return false;
            }

            // Apendear el nuevo nonce consumido.
            $payload = json_encode(
                ['nonce' => $key, 'consumed_at' => time()],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            fseek($handle, 0, SEEK_END);
            $written = fwrite($handle, $payload . PHP_EOL);
            fflush($handle);

            return $written !== false;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function isConsumed(string $nonce): bool
    {
        $key = trim($nonce);

        if ($key === '') {
            return false;
        }

        $path = $this->storagePath();

        if (! is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return false;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($data) && isset($data['nonce']) && is_string($data['nonce']) && hash_equals($data['nonce'], $key)) {
                    return true;
                }
            }

            return false;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function purge(int $beforeTimestamp): int
    {
        $path = $this->storagePath();

        if (! is_file($path)) {
            return 0;
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return 0;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return 0;
            }

            $kept = [];
            $purged = 0;
            rewind($handle);

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                $consumedAt = is_array($data) && isset($data['consumed_at']) && is_numeric($data['consumed_at'])
                    ? (int) $data['consumed_at']
                    : 0;

                if ($consumedAt > 0 && $consumedAt < $beforeTimestamp) {
                    $purged++;
                } else {
                    $kept[] = $line;
                }
            }

            // Reescribir el archivo con las líneas conservadas.
            ftruncate($handle, 0);
            rewind($handle);
            foreach ($kept as $line) {
                fwrite($handle, $line . PHP_EOL);
            }
            fflush($handle);

            return $purged;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function storagePath(): string
    {
        $path = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $this->storagePath));

        if ($path === '') {
            throw new RuntimeException('Federated link nonce storage path cannot be empty.');
        }

        return $path;
    }
}
