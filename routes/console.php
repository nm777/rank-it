<?php

use App\Inference\Budget;
use App\Inference\Providers;
use App\Jobs\IndexPassage;
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

Artisan::command('inference:resume', function () {
    DB::table('inference_work')->whereIn('stage', ['screen', 'embed'])->whereNull('call_id')
        ->eachById(fn ($work) => IndexPassage::dispatch($work->id));
})->purpose('Queue pending screening and embeddings from retained wording');

Artisan::command('inference:check-jev', function (Providers $providers, Budget $budget) {
    if (! config('inference.jev_key')) {
        $this->error('TYPESAFE_API_KEY is missing.');

        return 1;
    }
    $id = $budget->reserve('sample', 65536 * Providers::JEV_RATE);
    if (! $id) {
        $this->error('Insufficient background budget for the synthetic sample.');

        return 1;
    }
    try {
        [$keep, $tokens] = $providers->screen('The synthetic spare key is in locker B-17.', []);
    } catch (Throwable) {
        $this->error("Sample failed. Review reservation $id before retrying.");

        return 1;
    }
    $budget->settle($id, $tokens * Providers::JEV_RATE, $tokens);
    $this->info("Synthetic sample used $tokens input tokens. Qualified: ".($keep ? 'yes' : 'no'));

    return $keep ? 0 : 1;
})->purpose('Check Jev access and reported usage using fixed synthetic wording');

Artisan::command('inference:reconcile {call} {actual : Confirmed cost in USD billionths}', function (string $call, string $actual, Budget $budget) {
    if (! ctype_digit($call) || ! ctype_digit($actual) || filter_var($actual, FILTER_VALIDATE_INT) === false) {
        $this->error('Supply an integer call ID and nonnegative integer cost.');

        return 1;
    }

    return DB::transaction(function () use ($call, $actual, $budget) {
        $record = DB::table('inference_calls')->where('id', $call)->lockForUpdate()->first();
        if (! $record || $record->actual !== null) {
            $this->error('Call is missing or already settled.');

            return 1;
        }
        $budget->settle((int) $call, (int) $actual, null);
        DB::table('inference_work')->where('call_id', $call)->update(['call_id' => null]);

        return 0;
    });
})->purpose('Settle an interrupted call after stopping the worker and confirming its cost');
