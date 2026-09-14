<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['auth.registration_enabled' => true]);
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => ' Example Person ',
            'email' => ' Example@Example.com ',
            'password' => 'a-long-test-password',
            'password_confirmation' => 'a-long-test-password',
        ], $overrides);
    }

    public function test_guests_are_redirected_and_auth_pages_render(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Log in');
        $this->get('/register')->assertOk()->assertSee('Create account');
    }

    public function test_registration_normalizes_identity_hashes_password_and_rotates_session(): void
    {
        $this->get('/register');
        $oldId = session()->getId();
        $this->post('/register', $this->registration())->assertRedirect('/');
        $user = User::sole();
        $this->assertSame('Example Person', $user->name);
        $this->assertSame('example@example.com', $user->email);
        $this->assertTrue(Hash::check('a-long-test-password', $user->password));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldId, session()->getId());
    }

    public function test_registration_rejects_invalid_fields_and_does_not_flash_passwords(): void
    {
        $this->from('/register')->post('/register', $this->registration([
            'name' => ' ', 'email' => 'invalid', 'password' => 'short',
        ]))->assertRedirect('/register')->assertSessionHasErrors(['name', 'email', 'password'])
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_requires_password_confirmation_and_bounds_password_bytes(): void
    {
        $this->post('/register', $this->registration(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
        $long = str_repeat('é', 40);
        $this->post('/register', $this->registration(['password' => $long, 'password_confirmation' => $long]))->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_existing_email_regardless_of_input_case(): void
    {
        User::factory()->create(['email' => 'example@example.com']);
        $this->post('/register', $this->registration())->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_disabled_registration_blocks_get_post_and_hides_link(): void
    {
        config(['auth.registration_enabled' => false]);
        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->registration())->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('Create an account');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_rotates_session_and_honors_protected_destination(): void
    {
        $user = User::factory()->create(['email' => 'example@example.com']);
        $this->get('/')->assertRedirect('/login');
        $oldId = session()->getId();
        $this->post('/login', ['email' => ' EXAMPLE@EXAMPLE.COM ', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldId, session()->getId());
        $this->get('/')->assertOk();
    }

    public function test_wrong_password_does_not_authenticate_and_is_not_flashed(): void
    {
        User::factory()->create(['email' => 'example@example.com']);
        $this->from('/login')->post('/login', ['email' => 'example@example.com', 'password' => 'wrong'])
            ->assertRedirect('/login')->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
    }

    public function test_login_validates_input_before_attempting_authentication(): void
    {
        $this->post('/login', ['email' => ['bad'], 'password' => []])->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest();
    }

    public function test_five_failed_logins_lock_out_even_correct_credentials_until_expiry(): void
    {
        $user = User::factory()->create(['email' => 'example@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many attempts', session('errors')->first('email'));
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_clears_failed_attempt_counter(): void
    {
        $user = User::factory()->create(['email' => 'example@example.com']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $key = 'login:'.hash('sha256', $user->email.'|127.0.0.1');
        $this->assertSame(1, RateLimiter::attempts($key));
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_ip_throttle_limits_attempts_across_different_email_addresses(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->post('/login', ['email' => "unknown-$i@example.com", 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => 'another@example.com', 'password' => 'wrong'])->assertStatus(429);
        $this->post('/register', $this->registration())->assertRedirect('/');
    }

    public function test_logout_invalidates_session_rotates_csrf_token_and_protects_home(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['private-marker' => 'remove-me'])->get('/');
        $oldId = session()->getId();
        $oldToken = session()->token();
        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('private-marker');
        $this->assertGuest();
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->get('/')->assertRedirect('/login');
        $this->get('/logout')->assertMethodNotAllowed();
    }

    public function test_authenticated_users_cannot_open_or_submit_guest_forms(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/login')->assertRedirect('/');
        $this->get('/register')->assertRedirect('/');
        $this->post('/register', $this->registration())->assertRedirect('/');
        $this->assertDatabaseCount('users', 1);
    }
}
