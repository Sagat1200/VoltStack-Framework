<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Support;

use DateTimeInterface;

trait SanitizesTelemetryPayload
{
    private function sanitizeTelemetryValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 6) {
            return '[depth-exceeded]';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (strlen($value) <= 2048) {
                return $value;
            }

            return substr($value, 0, 2048) . '...';
        }

        if (is_array($value)) {
            $items = [];
            $count = 0;

            foreach ($value as $key => $item) {
                $count++;

                if ($count > 200) {
                    $items['_truncated'] = true;
                    break;
                }

                $items[is_int($key) ? $key : (string) $key] = $this->sanitizeTelemetryValue($item, $depth + 1);
            }

            return $items;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            return [
                '_type' => $value::class,
            ];
        }

        return '[unserializable]';
    }
}
