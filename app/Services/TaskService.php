<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TaskService
{
    public function paginate(string $filter = 'all'): LengthAwarePaginator
    {
        $user = $this->user();
        Gate::authorize('viewAny', Task::class);
        Validator::make(['filter' => $filter], ['filter' => [Rule::in(['all', 'active', 'completed'])]])->validate();

        return $user->tasks()
            ->when($filter === 'active', fn ($query) => $query->whereNull('completed_at'))
            ->when($filter === 'completed', fn ($query) => $query->whereNotNull('completed_at'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
    }

    public function find(int $id): Task
    {
        return $this->ownedTask($id, 'view');
    }

    public function create(array $input): Task
    {
        $user = $this->user();
        Gate::authorize('create', Task::class);

        return $user->tasks()->create($this->validate($input));
    }

    public function update(int $id, array $input): Task
    {
        $task = $this->ownedTask($id, 'update');
        $task->update($this->validate($input));

        return $task;
    }

    public function setCompleted(int $id, bool $completed): Task
    {
        $task = $this->ownedTask($id, 'update');
        // Repeating "complete" preserves the original completion time.
        $task->completed_at = $completed ? ($task->completed_at ?? now()) : null;
        $task->save();

        return $task;
    }

    public function delete(int $id): void
    {
        $this->ownedTask($id, 'delete')->delete();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function ownedTask(int $id, string $ability): Task
    {
        $task = $this->user()->tasks()->findOrFail($id);
        Gate::authorize($ability, $task);

        return $task;
    }

    private function validate(array $input): array
    {
        if (isset($input['title']) && is_string($input['title'])) {
            $input['title'] = trim($input['title']);
        }
        if (isset($input['notes']) && is_string($input['notes'])) {
            $input['notes'] = trim($input['notes']);
            $input['notes'] = $input['notes'] === '' ? null : $input['notes'];
        }

        return Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();
    }
}
