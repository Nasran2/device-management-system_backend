<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $request, AuditService $audit)
    {
        $request->validate([
            'login' => ['nullable', 'string', 'required_without:email'],
            'email' => ['nullable', 'email', 'required_without:login'],
            'password' => ['required', 'string']
        ]);

        $login = $request->input('login') ?: $request->input('email');
        $loginField = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        
        $credentials = [
            $loginField => $login,
            'password' => $request->input('password')
        ];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([$request->filled('login') ? 'login' : 'email' => 'The supplied credentials are invalid.']);
        }
        
        if (! $request->user()->is_active || ($request->user()->shop && $request->user()->shop->status !== 'active')) {
            Auth::logout();
            throw ValidationException::withMessages([$request->filled('login') ? 'login' : 'email' => 'This account is inactive.']);
        }
        
        $request->session()->regenerate();
        $request->user()->update(['last_login_at' => now()]);
        $audit->record('login', 'User signed in', $request->user());

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
