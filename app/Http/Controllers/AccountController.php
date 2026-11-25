<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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
            'password' => $this->passwordRules(),
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

    public function profile(Request $request): View
    {
        return view('profile', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $email = $request->input('email');
        $emailUnchanged = is_string($email) && Str::lower($email) === Str::lower($user->email);
        if (is_string($email)) {
            $request->merge(['email' => $emailUnchanged ? $user->email : Str::lower($email)]);
        }
        $profile = $request->validate([
            'name' => ['required', 'string', 'max:255', 'not_regex:/\x00/'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'current_password' => [Rule::excludeIf($emailUnchanged), 'bail', 'required', 'string', 'max:72', 'not_regex:/\x00/', 'current_password'],
        ]);

        $user->fill(['name' => $profile['name'], 'email' => $profile['email']]);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }

        return redirect()->route('profile.show')->with('status', 'Profile updated.');
    }

    public function showPasswordForm(): View
    {
        return view('profile-password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $passwords = $request->validate([
            'current_password' => ['bail', 'required', 'string', 'max:72', 'not_regex:/\x00/', 'current_password'],
            'password' => $this->passwordRules(),
        ]);

        $user = $request->user();
        $user->password = $passwords['password'];
        $user->setRememberToken(Str::random(60));
        $user->save();
        $request->session()->regenerate(true);

        return redirect()->route('profile.show')->with('status', 'Password updated.');
    }

    public function usage(Request $request): View
    {
        $usage = DB::table('inference_calls')
            ->where('user_id', $request->user()->id)
            ->whereNotNull('actual')
            ->selectRaw('count(*) as calls, coalesce(sum(actual), 0) as cost')
            ->first();

        return view('profile-usage', ['usage' => $usage]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function passwordRules(): array
    {
        return ['bail', 'required', 'string', 'min:12', 'confirmed', 'not_regex:/\x00/', function ($attribute, $value, $fail) {
            if (strlen($value) > 72) {
                $fail('Use a password at most 72 bytes long.');
            }
        }];
    }

    private function invitation(string $token)
    {
        return DB::table('invitations')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now());
    }
}
