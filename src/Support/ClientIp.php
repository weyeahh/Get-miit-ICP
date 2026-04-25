<?php

declare(strict_types=1);

namespace Miit\Support;

final class ClientIp
{
    public static function detect(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = $_SERVER[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }

            $parts = array_map('trim', explode(',', $value));
            if ($parts !== [] && $parts[0] !== '') {
                return $parts[0];
            }
        }

        return 'unknown';
    }
}
