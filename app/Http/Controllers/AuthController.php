<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const LoginLockoutMinutes = 5;

    private const MaximumLoginAttempts = 5;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuditService $auditService): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MaximumLoginAttempts)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$minutes} minute(s).",
            ]);
        }

        if (! Auth::attempt([...$credentials, 'status' => 'active'], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::LoginLockoutMinutes * 60);
            $pendingUser = User::query()->where('email', $credentials['email'])->where('status', 'inactive')->first();

            if ($pendingUser && Auth::validate($credentials)) {
                return back()->with('error', 'Your account is waiting for administrator approval. You will be able to sign in after it is approved.')->onlyInput('email');
            }

            return back()->withErrors(['email' => 'The email, password, or account status is invalid.'])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        if (! $request->user()->isActive()) {
            Auth::logout();

            return back()->withErrors(['email' => 'This account or its assigned role is inactive.']);
        }

        $loggedInAt = now('Asia/Manila');
        $request->user()->update(['last_login_at' => $loggedInAt]);
        $auditService->record(
            'login',
            'Signed in to the inventory system.',
            $request->user(),
            newValues: ['login_time' => $loggedInAt->format('M d, Y g:i:s A').' PHT'],
        );

        return redirect()->intended(route('dashboard'))->with('success', 'Welcome back, '.$request->user()->name.'.');
    }

    public function destroy(Request $request, AuditService $auditService): RedirectResponse
    {
        $loggedOutAt = now('Asia/Manila');
        $auditService->record(
            'logout',
            'Signed out of the inventory system.',
            $request->user(),
            newValues: ['logout_time' => $loggedOutAt->format('M d, Y g:i:s A').' PHT'],
        );
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been signed out securely.');
    }

    private function throttleKey(Request $request): string
    {
        return 'login:'.sha1(Str::lower($request->string('email')->toString()).'|'.$request->ip());
    }
}
