<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class WebsiteUrl implements ValidationRule
{
    public function __construct(private readonly bool $allowInternal = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/[\\\\\s\x00-\x1F\x7F]/u', $value)) {
            $fail('validation.url')->translate();

            return;
        }
        if ($this->allowInternal && str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return;
        }
        $parts = parse_url($value);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            $fail('validation.url')->translate();
        }
    }
}
