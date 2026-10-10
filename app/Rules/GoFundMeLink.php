<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class GoFundMeLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $valid = is_string($value) && filter_var($value, FILTER_VALIDATE_URL)
            && ! preg_match('/[\x00-\x20\x7f\\\\<>]/', $value)
            && $parts && strtolower($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $valid = $valid && (
            ($host === 'gofund.me' && preg_match('/\A\/[a-zA-Z0-9]+\/?\z/', $path))
            || (in_array($host, ['gofundme.com', 'www.gofundme.com'], true)
                && preg_match('/\A\/f\/[a-zA-Z0-9-]+\/?\z/', $path))
        );
        if (! $valid) {
            $fail(__('support.invalid_gofundme'));
        }
    }
}
