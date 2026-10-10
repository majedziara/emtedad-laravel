<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PalestinianIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\APS[0-9]{2}[A-Z]{4}[A-Z0-9]{21}\z/', $value)) {
            $fail(__('support.invalid_iban'));

            return;
        }

        $remainder = 0;
        foreach (str_split(substr($value, 4).substr($value, 0, 4)) as $character) {
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }
        if ($remainder !== 1) {
            $fail(__('support.invalid_iban'));
        }
    }
}
