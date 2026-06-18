<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('provisions the developer account with the Developer role', function () {
    $this->seed(DatabaseSeeder::class);

    $developer = User::where('email', env('DEVELOPER_EMAIL', 'info@advisionplus.com'))->first();

    expect($developer)->not->toBeNull();
    expect($developer->hasRole('Developer'))->toBeTrue();
    expect($developer->getAllPermissions())->toHaveCount(56);
});
