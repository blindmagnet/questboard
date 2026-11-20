<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('registers, logs in, reads profile and logs out', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ghazale', 'email' => 'g@example.com', 'password' => 'secret12345',
    ])->assertCreated()->assertJsonPath('data.user.email', 'g@example.com');

    $token = $this->postJson('/api/v1/auth/login', ['email' => 'g@example.com', 'password' => 'secret12345'])
        ->assertOk()->json('data.token');

    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'player');
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://questboard.dev/problems/unauthenticated');
});

it('validates registration and never mass-assigns the role', function () {
    $this->postJson('/api/v1/auth/register', ['name' => 'x', 'email' => 'bad', 'password' => 'short'])
        ->assertUnprocessable()->assertJsonStructure(['type', 'title', 'status', 'errors' => ['email', 'password']]);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret12345', 'role' => 'platform_admin',
    ])->assertCreated()->assertJsonPath('data.user.role', 'player');
});

it('locks the account temporarily after repeated failures', function () {
    User::factory()->create(['email' => 'v@example.com', 'password' => Hash::make('right-password1')]);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'v@example.com', 'password' => 'wrong'])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'v@example.com', 'password' => 'right-password1'])
        ->assertStatus(429)->assertJsonPath('type', 'https://questboard.dev/problems/account-locked');
});

it('rate limits the auth endpoints per IP', function () {
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'.uniqid()]);
    }
    $this->postJson('/api/v1/auth/login', ['email' => 'nobody2@example.com', 'password' => 'x'])
        ->assertStatus(429)->assertHeader('Retry-After');
});

it('anonymizes the account on deletion', function () {
    $user = User::factory()->create(['email' => 'pii@example.com']);
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/me')->assertNoContent();

    $user->refresh();
    expect($user->email)->not->toBe('pii@example.com')
        ->and($user->anonymized_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.anonymized', 'subject_id' => $user->id]);
});
