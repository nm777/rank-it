<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

Artisan::command('pilot:invite {email}', function (string $email): int {
    $email = Str::lower(trim($email));
    $validation = Validator::make(['email' => $email], ['email' => 'required|email|max:255']);

    if ($validation->fails()) {
        $this->error($validation->errors()->first());

        return 1;
    }

    if (User::where('email', $email)->exists()) {
        $this->error('An account already exists for this email.');

        return 1;
    }

    $token = Str::random(64);
    DB::table('invitations')->upsert([[
        'email' => $email,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]], ['email'], ['token_hash', 'expires_at']);

    $this->line(route('invitation.show', $token));

    return 0;
})->purpose('Issue or replace a seven-day pilot invitation');
