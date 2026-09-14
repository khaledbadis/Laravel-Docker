<section aria-labelledby="tasks-heading" class="w-full min-w-0">
    <p class="mb-3 text-xs font-semibold tracking-widest text-emerald-800 uppercase">One thing at a time</p>
    <h1 id="tasks-heading" class="text-4xl font-semibold tracking-tight sm:text-5xl">Your little list.</h1>
    <p class="mt-4 text-stone-600">Make room for what matters. Start with a small step.</p>
    <p role="status" aria-live="polite" aria-atomic="true" class="min-h-10 py-3 text-sm font-medium text-emerald-800">{{ $feedback }}</p>

    <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">
        <form wire:submit="save" class="min-w-0 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="mb-5 text-lg font-semibold">{{ $editingId ? 'Edit task' : 'A new task' }}</h2>
            <label for="task-title" class="block text-sm font-medium">Title <span class="text-stone-500">(required)</span></label>
            <input id="task-title" wire:model="title" x-on:focus-task-title.window="$el.focus()" type="text" maxlength="255" required aria-invalid="{{ $errors->has('title') ? 'true' : 'false' }}" aria-describedby="title-error" class="mt-2 w-full rounded-lg border border-stone-300 px-3 py-2.5 focus:border-emerald-700 focus:outline-2 focus:outline-emerald-700">
            <p id="title-error" class="mt-1 text-sm text-red-700">@error('title') {{ $message }} @enderror</p>
            <label for="task-notes" class="mt-5 block text-sm font-medium">Notes <span class="text-stone-500">(optional)</span></label>
            <textarea id="task-notes" wire:model="notes" rows="4" maxlength="5000" aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}" aria-describedby="notes-error" class="mt-2 w-full resize-y rounded-lg border border-stone-300 px-3 py-2.5 focus:border-emerald-700 focus:outline-2 focus:outline-emerald-700"></textarea>
            <p id="notes-error" class="mt-1 text-sm text-red-700">@error('notes') {{ $message }} @enderror</p>
            <button wire:loading.attr="disabled" class="mt-5 w-full rounded-lg bg-emerald-800 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:opacity-50">{{ $editingId ? 'Save changes' : 'Add task' }}</button>
            @if ($editingId)
                <button type="button" wire:click="cancelEdit" class="mt-2 w-full rounded-lg px-4 py-3 text-sm font-medium hover:bg-stone-100">Cancel editing</button>
            @endif
        </form>

        <div class="min-w-0">
            <div aria-label="Filter tasks" role="group" class="mb-5 flex flex-wrap gap-2">
                @foreach (['all' => 'All', 'active' => 'Active', 'completed' => 'Completed'] as $value => $label)
                    <button type="button" wire:click="showFilter('{{ $value }}')" wire:loading.attr="disabled" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" @class(['rounded-full px-4 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:opacity-50', 'bg-emerald-800 text-white' => $filter === $value, 'bg-white text-stone-600 border border-stone-200 hover:bg-stone-100' => $filter !== $value])>{{ $label }}</button>
                @endforeach
            </div>
            <h2 class="mb-3 text-sm text-stone-500">{{ $tasks->total() }} {{ Str::plural('task', $tasks->total()) }} · {{ ucfirst($filter) }}</h2>
            <ul class="space-y-3" aria-label="Tasks">
                @forelse ($tasks as $task)
                    <li wire:key="task-{{ $task->id }}" class="min-w-0 rounded-2xl border border-stone-200 bg-white p-5">
                        <div class="flex items-start gap-3">
                            <span aria-hidden="true" class="mt-0.5 text-emerald-800">{{ $task->completed_at ? '✓' : '○' }}</span>
                            <div class="min-w-0 flex-1">
                                <h3 @class(['font-medium break-words [overflow-wrap:anywhere]', 'text-stone-500 line-through' => $task->completed_at])>{{ $task->title }}</h3>
                                <p class="mt-1 text-xs text-stone-500">{{ $task->completed_at ? 'Completed' : 'Active' }}</p>
                                @if ($task->notes)
                                    <p class="mt-3 text-sm leading-6 whitespace-pre-wrap text-stone-600 [overflow-wrap:anywhere]">{{ $task->notes }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-1 text-sm">
                            <button type="button" wire:click="complete({{ $task->id }}, {{ $task->completed_at ? 'false' : 'true' }})" wire:loading.attr="disabled" aria-label="{{ $task->completed_at ? 'Reopen' : 'Complete' }} {{ $task->title }}" class="rounded-lg px-3 py-2 font-medium text-emerald-800 hover:bg-emerald-50 disabled:opacity-50">{{ $task->completed_at ? 'Reopen' : 'Complete' }}</button>
                            <button type="button" wire:click="edit({{ $task->id }})" wire:loading.attr="disabled" aria-label="Edit {{ $task->title }}" class="rounded-lg px-3 py-2 text-stone-600 hover:bg-stone-100 disabled:opacity-50">Edit</button>
                            <button type="button" wire:click="requestDelete({{ $task->id }})" wire:loading.attr="disabled" aria-label="Delete {{ $task->title }}" class="rounded-lg px-3 py-2 text-red-700 hover:bg-red-50 disabled:opacity-50">Delete</button>
                        </div>
                        @if ($deletingId === $task->id)
                            <div role="group" aria-label="Confirm deletion" class="mt-3 rounded-lg bg-red-50 p-3" x-init="$nextTick(() => $refs.cancel.focus())">
                                <p class="text-sm text-red-900">Delete this task? This cannot be undone.</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" wire:click="delete" wire:loading.attr="disabled" class="rounded-lg bg-red-700 px-3 py-2 text-sm font-medium text-white disabled:opacity-50">Yes, delete task</button>
                                    <button type="button" x-ref="cancel" wire:click="cancelDelete" class="rounded-lg px-3 py-2 text-sm font-medium text-stone-700 hover:bg-white">Keep task</button>
                                </div>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="rounded-2xl border border-dashed border-stone-300 p-8 text-center">
                        <p class="font-medium">{{ $filter === 'completed' ? 'No completed tasks yet.' : ($filter === 'active' ? 'Nothing left to do.' : 'A little space for your next step.') }}</p>
                        <p class="mt-2 text-sm leading-6 text-stone-500">{{ $filter === 'completed' ? 'Completed tasks will appear here.' : 'Add a task whenever you’re ready.' }}</p>
                    </li>
                @endforelse
            </ul>
            <div class="mt-6">{{ $tasks->links() }}</div>
        </div>
    </div>
</section>
