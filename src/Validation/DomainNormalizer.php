<?php

declare(strict_types=1);

namespace Miit\Validation;

use Miit\Exception\ValidationException;

final class DomainNormalizer
{
    public function normalize(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            throw new ValidationException('domain parameter is required');
        }

        if (preg_match('/[\x00-\x1F\x7F\s]/u', $domain) === 1) {
            throw new ValidationException('domain contains invalid characters');
        }

        $domain = strtolower(rtrim($domain, '.'));
        if ($domain === '') {
            throw new ValidationException('domain parameter is required');
        }

        if (strlen($domain) > 253) {
            throw new ValidationException('domain is too long');
        }

        if (str_contains($domain, '..')) {
            throw new ValidationException('domain format is invalid');
        }

        if (preg_match('/^[a-z0-9.-]+$/', $domain) !== 1) {
            throw new ValidationException('domain format is invalid');
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            throw new ValidationException('domain format is invalid');
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                throw new ValidationException('domain format is invalid');
            }

            if ($label[0] === '-' || $label[strlen($label) - 1] === '-') {
                throw new ValidationException('domain format is invalid');
            }
        }

        return $domain;
    }
}
