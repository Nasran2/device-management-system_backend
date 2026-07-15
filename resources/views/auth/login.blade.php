<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in · DeviceGuard</title>@vite(['resources/css/app.css'])</head>
<body class="min-h-screen bg-slate-950 antialiased">
<main class="grid min-h-screen place-items-center px-5 py-10">
    <section class="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl shadow-indigo-950/30">
        <div class="bg-gradient-to-br from-indigo-600 to-blue-700 px-8 py-9 text-white"><div class="mb-5 grid size-12 place-items-center rounded-2xl bg-white/15 text-xl font-black">D</div><h1 class="text-3xl font-bold">Welcome back</h1><p class="mt-2 text-sm text-indigo-100">Sign in to manage authorized Android devices.</p></div>
        <form method="post" action="/login" class="space-y-5 p-8">@csrf
            @if($errors->any())<p class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
            <label class="field-label">Email address<input class="field-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"></label>
            <label class="field-label">Password<input class="field-input" type="password" name="password" required autocomplete="current-password"></label>
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-indigo-600"> Remember me</label>
            <button class="primary-button w-full" type="submit">Sign in securely</button>
        </form>
        <footer class="border-t border-slate-100 px-8 py-4 text-center text-sm text-slate-500">Powered by <a class="font-semibold text-indigo-600" href="https://twinsofte.com" target="_blank" rel="noopener noreferrer">twinsofte.com</a></footer>
    </section>
</main>
</body></html>
