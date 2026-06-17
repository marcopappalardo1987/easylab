<?php

use App\Models\User;

it('renders the login screen', function () {
    $this->get('/login')->assertOk();
});

it('authenticates with valid credentials and lands on the dashboard', function () {
    $user = User::factory()->create(['password' => bcrypt('secret-pw-123')]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'secret-pw-123',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect('/dashboard');
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('secret-pw-123')]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('protects the dashboard from guests', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('lets an authenticated user reach the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee($user->name);
});

it('logs the user out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    $this->assertGuest();
});
