<?php

namespace Tests\Unit;

use App\Services\RegistrationAllowlist;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RegistrationAllowlistTest extends TestCase
{
    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith([' , '])]
    public function test_registration_is_closed_when_the_allowlist_is_empty(?string $allowlist)
    {
        config(['registration.allowlist' => $allowlist]);

        $service = new RegistrationAllowlist;

        $this->assertFalse($service->isOpen());
        $this->assertFalse($service->allows('anyone@example.com'));
    }

    public function test_registration_is_open_when_the_allowlist_has_entries()
    {
        config(['registration.allowlist' => 'me@example.com']);

        $this->assertTrue((new RegistrationAllowlist)->isOpen());
    }

    #[TestWith(['me@example.com', 'me@example.com', true])]
    #[TestWith(['me@example.com', 'Me@Example.COM', true])]
    #[TestWith(['me@example.com', 'other@example.com', false])]
    #[TestWith(['example.org', 'anyone@example.org', true])]
    #[TestWith(['example.org', 'anyone@example.com', false])]
    #[TestWith(['@example.org', 'anyone@example.org', true])]
    #[TestWith(['EXAMPLE.ORG', 'anyone@example.org', true])]
    #[TestWith(['example.org', 'anyone@sub.example.org', false])]
    #[TestWith([' me@example.com , example.org ', 'me@example.com', true])]
    #[TestWith([' me@example.com , example.org ', 'anyone@example.org', true])]
    #[TestWith([' me@example.com , example.org ', 'other@example.net', false])]
    public function test_it_matches_emails_against_full_email_and_domain_entries(
        string $allowlist,
        string $email,
        bool $expected,
    ) {
        config(['registration.allowlist' => $allowlist]);

        $this->assertSame($expected, (new RegistrationAllowlist)->allows($email));
    }
}
