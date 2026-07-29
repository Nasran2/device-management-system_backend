<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · DeviceGuard</title>
    @vite(['resources/css/app.css'])
    <!-- Ensure Alpine.js is included if needed for toggling password visibility, but we can just use inline JS -->
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased font-sans selection:bg-indigo-100 selection:text-indigo-900">
    
<div class="flex min-h-screen">
    <!-- Left Sidebar (Branding & Features) -->
    <div class="hidden w-1/2 bg-[#0A102A] text-white lg:flex flex-col justify-between p-12 xl:p-16 relative overflow-hidden">
        
        <!-- Abstract gradient background -->
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute -top-[10%] -left-[10%] w-[50%] h-[50%] rounded-full bg-indigo-900/40 blur-[100px]"></div>
            <div class="absolute top-[40%] right-[10%] w-[40%] h-[40%] rounded-full bg-blue-900/30 blur-[120px]"></div>
        </div>

        <div class="relative z-10 flex items-center gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-2xl font-black shadow-lg shadow-indigo-600/30">D</div>
            <div>
                <h1 class="text-2xl font-bold tracking-tight">DeviceGuard</h1>
                <p class="text-sm text-slate-400">Secure device operations</p>
            </div>
        </div>

        <!-- Illustration Area (CSS Mockup of Phones) -->
        <div class="relative z-10 flex-1 flex flex-col items-center justify-center py-12">
            <!-- Center Phone Mockup -->
            <div class="relative w-64 h-[400px] bg-slate-900 rounded-[2.5rem] border-[6px] border-slate-800 shadow-2xl flex flex-col overflow-hidden ring-1 ring-white/10 z-20 shadow-indigo-900/50">
                <!-- Phone Top Bar -->
                <div class="h-6 w-full flex justify-center pt-2 absolute top-0 z-30 bg-slate-900/50 backdrop-blur">
                    <div class="w-16 h-4 bg-black rounded-full"></div>
                </div>
                
                <!-- Phone Screen Content -->
                <div class="flex-1 bg-gradient-to-b from-slate-900 to-[#0A102A] flex flex-col items-center justify-center p-6 relative">
                    <!-- Glowing Shield/Lock Icon -->
                    <div class="relative flex items-center justify-center w-24 h-24 mb-6">
                        <div class="absolute inset-0 bg-indigo-600 rounded-full blur-[20px] opacity-60"></div>
                        <svg class="relative w-20 h-24 text-indigo-500 drop-shadow-xl" viewBox="0 0 24 24" fill="currentColor"><path d="M11 2.2a2 2 0 012 0l7 3.5c.6.3 1 1 1 1.7v5.6c0 5-3.3 9.7-8 11.2a3 3 0 01-2 0c-4.7-1.5-8-6.2-8-11.2V7.4c0-.7.4-1.4 1-1.7l7-3.5z" opacity="0.2"/><path d="M12 2l8 4v6c0 5.5-3.5 10.5-8 12-4.5-1.5-8-6.5-8-12V6l8-4z" fill="#4F46E5"/><path d="M12 11a2 2 0 100-4 2 2 0 000 4zm0 2c-1.3 0-4 .7-4 2v1h8v-1c0-1.3-2.7-2-4-2z" fill="white"/></svg>
                    </div>
                    <div class="text-white font-bold text-lg text-center leading-tight">Device Protected</div>
                    <div class="text-slate-400 text-xs mt-1 text-center">All systems secure</div>
                </div>
            </div>

            <!-- Background Mockups (Left/Right) -->
            <div class="absolute top-1/2 left-1/2 -translate-x-[110%] -translate-y-[45%] w-56 h-[340px] bg-slate-900 rounded-[2rem] border-[4px] border-slate-800 shadow-xl opacity-60 z-10 flex flex-col p-4">
                 <div class="flex items-center gap-2 mb-4"><div class="w-3 h-3 rounded-full bg-emerald-500"></div><div class="text-xs font-semibold text-slate-400">Online</div></div>
                 <div class="text-3xl font-bold text-white mb-1">12</div>
                 <div class="text-xs text-slate-500">Devices</div>
                 <div class="mt-auto flex gap-4 text-slate-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg><span class="text-sm font-semibold text-white">2</span></div>
            </div>
            
            <div class="absolute top-1/2 left-1/2 translate-x-[10%] -translate-y-[40%] w-56 h-[340px] bg-slate-900 rounded-[2rem] border-[4px] border-slate-800 shadow-xl opacity-60 z-10 flex flex-col p-4">
                 <div class="text-xs font-semibold text-slate-400 mb-2">Protection</div>
                 <div class="flex items-center gap-2 mb-4"><div class="w-3 h-3 rounded-full bg-emerald-500"></div><div class="text-sm font-bold text-white">Active</div></div>
                 <div class="mt-auto bg-indigo-900/50 rounded-xl p-3 border border-indigo-500/30">
                     <div class="text-xs text-indigo-200 mb-1">Policy</div>
                     <div class="text-sm font-bold text-indigo-400 flex items-center gap-1"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg> Enforced</div>
                 </div>
            </div>
        </div>

        <div class="relative z-10 space-y-8 max-w-md">
            <div class="flex gap-4 items-start group cursor-default">
                <div class="p-3 rounded-2xl bg-[#1A2040] text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white transition-colors duration-300 shadow-inner ring-1 ring-white/5">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <div>
                    <h3 class="font-bold text-base text-slate-100">Real-time device control</h3>
                    <p class="text-sm text-slate-400 mt-1">Monitor and manage devices instantly from anywhere.</p>
                </div>
            </div>
            
            <div class="flex gap-4 items-start group cursor-default">
                <div class="p-3 rounded-2xl bg-[#1A2040] text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white transition-colors duration-300 shadow-inner ring-1 ring-white/5">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                </div>
                <div>
                    <h3 class="font-bold text-base text-slate-100">Offline protection</h3>
                    <p class="text-sm text-slate-400 mt-1">Keep your devices secure even when they go offline.</p>
                </div>
            </div>
            
            <div class="flex gap-4 items-start group cursor-default">
                <div class="p-3 rounded-2xl bg-[#1A2040] text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white transition-colors duration-300 shadow-inner ring-1 ring-white/5">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </div>
                <div>
                    <h3 class="font-bold text-base text-slate-100">Shop owner & super admin access</h3>
                    <p class="text-sm text-slate-400 mt-1">Role-based access for complete operational control.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Sidebar (Login Form) -->
    <div class="w-full lg:w-1/2 flex flex-col bg-slate-50 relative">
        <div class="flex-1 flex flex-col justify-center px-6 sm:px-12 lg:px-20 xl:px-32 py-12">
            
            <div class="w-full max-w-md mx-auto">
                
                <div class="text-center mb-10">
                    <div class="mx-auto w-16 h-16 bg-indigo-50 border border-indigo-100 rounded-2xl flex items-center justify-center text-indigo-600 mb-6 shadow-sm">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </div>
                    <h2 class="text-3xl font-extrabold tracking-tight text-slate-900">Welcome back</h2>
                    <p class="mt-3 text-sm text-slate-500">Sign in to manage protected devices</p>
                </div>

                <form class="space-y-6 bg-white p-8 sm:p-10 rounded-[2rem] border border-slate-100 shadow-xl shadow-slate-200/50" method="post" action="/login">
                    @csrf
                    
                    @if($errors->any())
                        <div class="rounded-xl border border-red-200 bg-red-50 p-4 animate-pulse">
                            <p class="text-sm font-semibold text-red-700">{{ $errors->first() }}</p>
                        </div>
                    @endif
                    
                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-bold text-slate-900 mb-2">Email or username</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                </div>
                                <input type="text" name="login" value="{{ old('login') }}" class="block w-full pl-12 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 focus:bg-white transition-all shadow-sm" placeholder="Enter your email or username" required autofocus autocomplete="username">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-900 mb-2">Password</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                </div>
                                <input type="password" name="password" id="password_input" class="block w-full pl-12 pr-12 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 focus:bg-white transition-all shadow-sm" placeholder="Enter your password" required autocomplete="current-password">
                                <button type="button" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-indigo-600 focus:outline-none transition-colors" onclick="const p = document.getElementById('password_input'); p.type = p.type === 'password' ? 'text' : 'password';">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2.5 cursor-pointer group">
                            <div class="relative flex items-center justify-center">
                                <input type="checkbox" name="remember" value="1" class="peer sr-only">
                                <div class="h-5 w-5 rounded-md border border-slate-300 bg-slate-50 transition-colors peer-checked:border-indigo-600 peer-checked:bg-indigo-600 group-hover:border-indigo-500"></div>
                                <svg class="absolute w-3.5 h-3.5 pointer-events-none opacity-0 text-white peer-checked:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <span class="text-sm text-slate-700 font-semibold select-none">Remember me</span>
                        </label>
                        <a href="#" class="text-sm font-bold text-indigo-600 hover:text-indigo-800 transition-colors">Forgot password?</a>
                    </div>

                    <div>
                        <button type="submit" class="w-full flex justify-center py-4 px-4 border border-transparent rounded-xl shadow-[0_4px_14px_0_rgba(79,70,229,0.39)] text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 hover:shadow-[0_6px_20px_rgba(79,70,229,0.23)] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-600 transition-all active:scale-[0.98]">
                            Sign in
                        </button>
                    </div>
                    
                    <div class="relative my-6">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-slate-100"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-3 bg-white text-slate-400 text-xs font-semibold uppercase tracking-wider">or</span>
                        </div>
                    </div>

                    <button type="button" class="w-full flex justify-center items-center gap-3 py-3.5 px-4 border-2 border-slate-100 rounded-xl text-sm font-bold text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-600 transition-all">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                        <div class="flex flex-col items-center leading-tight">
                            <span>Request access</span>
                            <span class="text-[11px] font-medium text-slate-500 mt-0.5">Don't have an account? Request access.</span>
                        </div>
                    </button>
                </form>

            </div>
            
        </div>
        
        <!-- Footer -->
        <div class="mt-auto py-6 text-center text-xs font-medium text-slate-400 flex flex-col items-center gap-1.5 w-full bg-transparent">
            <div class="flex items-center gap-1.5 text-slate-500 px-3 py-1 bg-slate-100 rounded-full w-max mx-auto border border-slate-200">
                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                Authorized management only
            </div>
            <div class="mt-1">© 2024 DeviceGuard. All rights reserved.</div>
        </div>
    </div>
</div>

</body>
</html>
