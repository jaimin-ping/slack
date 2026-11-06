<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nobody can sign up, so the very first person on a new server is added here.
Artisan::command('chat:first-user {email} {name}', function (string $email, string $name) {
    $email = strtolower($email);

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('That is not an email address.');

        return 1;
    }

    if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
        $this->error('That email already has an account.');

        return 1;
    }

    User::create(['name' => $name, 'email' => $email, 'password' => Str::password(40)])
        ->forceFill(['email_verified_at' => now()])->save();

    $this->info("{$email} can now sign in with an emailed code.");
})->purpose('Add the first person to a new server');

Schedule::command('chat:run-due')->everyMinute()->withoutOverlapping();
