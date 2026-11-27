<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());

        config(['registration.allowlist' => 'example.com']);
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_registration_screen_redirects_to_login_when_the_allowlist_is_empty()
    {
        config(['registration.allowlist' => '']);

        $response = $this->get(route('register'));

        $response->assertRedirect(route('login'));
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_cannot_register_when_the_allowlist_is_empty()
    {
        config(['registration.allowlist' => '']);

        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertInvalid(['email' => 'Registration is currently closed.']);
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_users_with_an_email_outside_the_allowlist_cannot_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.net',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertInvalid(['email' => 'Registration is currently closed.']);
        $this->assertDatabaseMissing('users', ['email' => 'test@example.net']);
    }
}
