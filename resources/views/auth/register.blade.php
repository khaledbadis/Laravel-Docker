@component('layouts.app', ['title' => 'Create an account · Little list'])
    <section class="w-full max-w-md" aria-labelledby="register-heading">
        <p class="mb-3 text-xs font-semibold tracking-widest text-emerald-800 uppercase">A fresh start</p>
        <h1 id="register-heading" class="text-4xl font-semibold tracking-tight">Make a little space.</h1>
        <p class="mt-4 text-stone-600">Create an account to get started.</p>
        <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-5 rounded-3xl border border-stone-200 bg-white p-7 shadow-sm">
            @csrf
            <x-auth-input name="name" label="Name" autocomplete="name" maxlength="100" autofocus />
            <x-auth-input name="email" label="Email" type="email" autocomplete="username" maxlength="254" />
            <x-auth-input name="password" label="Password" type="password" autocomplete="new-password" minlength="12" hint="Use at least 12 characters." />
            <x-auth-input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" minlength="12" />
            <button class="w-full rounded-xl bg-emerald-800 px-5 py-3.5 font-semibold text-white hover:bg-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-700">Create account</button>
        </form>
        <p class="mt-6 text-center text-sm text-stone-600">Already have an account? <a href="{{ route('login') }}" class="font-semibold text-emerald-800 underline underline-offset-4">Log in</a></p>
    </section>
@endcomponent
