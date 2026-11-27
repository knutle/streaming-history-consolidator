<?php

namespace App\Services;

use Illuminate\Support\Str;

class RegistrationAllowlist
{
    /**
     * Determine whether registration is open at all.
     */
    public function isOpen(): bool
    {
        return $this->entries() !== [];
    }

    /**
     * Determine whether the given email address is allowed to register.
     */
    public function allows(string $email): bool
    {
        $email = Str::lower(trim($email));
        $domain = Str::afterLast($email, '@');

        foreach ($this->entries() as $entry) {
            if (str_contains($entry, '@') && ! str_starts_with($entry, '@')) {
                if ($entry === $email) {
                    return true;
                }

                continue;
            }

            if (ltrim($entry, '@') === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the normalized allowlist entries.
     *
     * @return array<int, string>
     */
    private function entries(): array
    {
        $allowlist = config('registration.allowlist');

        return collect(explode(',', is_string($allowlist) ? $allowlist : ''))
            ->map(fn (string $entry): string => Str::lower(trim($entry)))
            ->filter()
            ->values()
            ->all();
    }
}
