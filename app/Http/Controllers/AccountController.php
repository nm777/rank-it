<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
        ]);
        $credentials['email'] = Str::lower($credentials['email']);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'Email or password is incorrect.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('memories.index'));
    }

    public function showInvitation(string $token)
    {
        $invitation = $this->invitation($token)->first();
        abort_unless($invitation && ! User::where('email', $invitation->email)->exists(), 404);

        return view('invitation', ['email' => $invitation->email, 'token' => $token]);
    }

    public function redeem(Request $request, string $token)
    {
        $account = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed', 'not_regex:/\x00/', function ($attribute, $value, $fail) {
                if (strlen($value) > 72) {
                    $fail('Use a password at most 72 bytes long.');
                }
            }],
        ]);

        $user = DB::transaction(function () use ($token, $account) {
            $invitation = $this->invitation($token)->lockForUpdate()->first();
            abort_unless($invitation && ! User::where('email', $invitation->email)->exists(), 404);

            $user = User::create($account + ['email' => $invitation->email]);
            DB::table('invitations')->where('id', $invitation->id)->delete();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('memories.index');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function invitation(string $token)
    {
        return DB::table('invitations')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now());
    }
}
