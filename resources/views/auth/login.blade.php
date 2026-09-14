@component('layouts.app', ['title' => 'Log in · Little list'])
    <section class="w-full max-w-md" aria-labelledby="login-heading">
        <p class="mb-3 text-xs font-semibold tracking-widest text-emerald-800 uppercase">Welcome back</p>
        <h1 id="login-heading" class="text-4xl font-semibold tracking-tight">Pick up where you left off.</h1>
        <p class="mt-4 text-stone-600">Log in to your own little space.</p>
        <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5 rounded-3xl border border-stone-200 bg-white p-7 shadow-sm">
            @csrf
            <x-auth-input name="email" label="Email" type="email" autocomplete="username" maxlength="254" autofocus />
            <x-auth-input name="password" label="Password" type="password" autocomplete="current-password" />
            <button class="w-full rounded-xl bg-emerald-800 px-5 py-3.5 font-semibold text-white hover:bg-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-700">Log in</button>
        </form>
        @if(config('auth.registration_enabled'))
            <p class="mt-6 text-center text-sm text-stone-600">New here? <a href="{{ route('register') }}" class="font-semibold text-emerald-800 underline underline-offset-4">Create an account</a></p>
        @endif
    </section>
@endcomponent
