<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_can_be_created_with_signup_closed(): void
    {
        config(['auth.registration_enabled' => false]);
        $this->artisan('app:create-user')->expectsQuestion('Name', ' Owner ')
            ->expectsQuestion('Email', ' OWNER@example.test ')
            ->expectsQuestion('Password', 'a-long-local-password')
            ->expectsQuestion('Confirm password', 'a-long-local-password')
            ->expectsOutput('Account created.')->assertSuccessful();
        $user = User::sole();
        $this->assertSame('owner@example.test', $user->email);
        $this->assertTrue(Hash::check('a-long-local-password', $user->password));
    }

    public function test_invalid_password_does_not_create_an_account(): void
    {
        $this->artisan('app:create-user')->expectsQuestion('Name', 'Owner')
            ->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password', 'short')->expectsQuestion('Confirm password', 'different')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
