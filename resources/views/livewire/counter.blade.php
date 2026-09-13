<section aria-labelledby="counter-heading" class="w-full max-w-lg">
    <p class="mb-4 text-xs font-semibold tracking-widest text-emerald-800 uppercase">A fresh start</p>
    <h1 id="counter-heading" class="text-4xl leading-tight font-semibold tracking-tight sm:text-5xl">A little progress,<br>every day.</h1>
    <p class="mt-5 max-w-sm text-base leading-7 text-stone-600">Big things begin with a small step. Take one now, then another.</p>

    <div class="mt-9 rounded-3xl border border-stone-200 bg-white p-7 shadow-sm sm:p-9">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-medium text-stone-600">Focus counter</h2>
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-800">Practice space</span>
        </div>
        <p role="status" aria-live="polite" aria-atomic="true" class="py-8 text-center">
            <span data-testid="count" class="block text-7xl font-semibold tracking-tight tabular-nums">{{ $count }}</span>
            <span class="mt-3 block text-sm text-stone-500">{{ $count === 1 ? 'step taken' : 'steps taken' }}</span>
        </p>
        <button type="button" wire:click="increment" wire:loading.attr="disabled" class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-800 px-5 py-3.5 text-sm font-semibold text-white transition hover:bg-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-700 disabled:cursor-wait disabled:opacity-60">
            <span aria-hidden="true">＋</span> Take a step
        </button>
        <button type="button" wire:click="resetCount" wire:loading.attr="disabled" @disabled($count === 0) class="mt-3 w-full rounded-xl px-5 py-3 text-sm font-medium text-stone-600 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-default disabled:opacity-40">Start over</button>
    </div>
    <p class="mt-5 text-center text-xs leading-5 text-stone-500">Demo counter · starts fresh when you refresh.</p>
</section>
