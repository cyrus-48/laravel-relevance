<?php

declare(strict_types=1);

namespace Relevance\Support;

final readonly class Hasher
{
    /**
     * Compute a deterministic sha256 hash of a string or serializable structure.
     */
    public static function hash(mixed $data): string
    {
        if (is_string($data)) {
            return hash('sha256', $data);
        }

        return hash('sha256', (string) json_encode(self::normalize($data), JSON_THROW_ON_ERROR));
    }

    /**
     * Hash a string to a deterministic float in [0.0, 1.0) for experiment bucketing.
     */
    public static function bucket(string $identifier, string $salt = ''): float
    {
        $hash = hash('murmur3a', $identifier . ':' . $salt);
        $int = (int) hexdec(substr($hash, 0, 8));

        return ($int % 10000) / 10000.0;
    }

    private static function normalize(mixed $data): mixed
    {
        if (is_array($data)) {
            ksort($data);
            foreach ($data as $key => $value) {
                $data[$key] = self::normalize($value);
            }
            return $data;
        }

        return $data;
    }
}
