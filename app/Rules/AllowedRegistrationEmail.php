<?php

namespace App\Rules;

use App\Services\RegistrationAllowlist;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class AllowedRegistrationEmail implements ValidationRule
{
    public function __construct(private readonly RegistrationAllowlist $allowlist) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->allowlist->allows($value)) {
            $fail('Registration is currently closed.');
        }
    }
}
