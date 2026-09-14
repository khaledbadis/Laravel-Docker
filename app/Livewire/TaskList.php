<?php

namespace App\Livewire;

use App\Services\TaskService;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class TaskList extends Component
{
    use WithPagination;

    public string $title = '';

    public string $notes = '';

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $deletingId = null;

    #[Locked]
    public string $filter = 'all';

    public string $feedback = '';

    public function save(TaskService $tasks): void
    {
        $input = ['title' => $this->title, 'notes' => $this->notes];
        if ($this->editingId !== null) {
            $tasks->update($this->editingId, $input);
            $this->feedback = 'Task updated.';
        } else {
            $tasks->create($input);
            $this->filter = 'all';
            $this->resetPage();
            $this->feedback = 'Task added.';
        }
        $this->cancelEdit();
    }

    public function edit(int $id, TaskService $tasks): void
    {
        $task = $tasks->find($id);
        $this->resetValidation();
        $this->editingId = $task->id;
        $this->title = $task->title;
        $this->notes = $task->notes ?? '';
        $this->dispatch('focus-task-title');
    }

    public function cancelEdit(): void
    {
        $this->reset('title', 'notes', 'editingId');
        $this->resetValidation();
    }

    public function complete(int $id, bool $completed, TaskService $tasks): void
    {
        $tasks->setCompleted($id, $completed);
        $this->feedback = $completed ? 'Task completed.' : 'Task reopened.';
    }

    public function requestDelete(int $id, TaskService $tasks): void
    {
        $tasks->find($id);
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function delete(TaskService $tasks): void
    {
        if ($this->deletingId === null) {
            return;
        }
        $tasks->delete($this->deletingId);
        if ($this->editingId === $this->deletingId) {
            $this->cancelEdit();
        }
        $this->deletingId = null;
        $this->feedback = 'Task deleted.';
    }

    public function showFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['all', 'active', 'completed'], true), 422);
        $this->filter = $filter;
        $this->cancelDelete();
        $this->resetPage();
    }

    public function render(TaskService $tasks): View
    {
        $page = $tasks->paginate($this->filter);
        // A deletion or status change can remove the final row on a page.
        if ($page->currentPage() > $page->lastPage()) {
            $this->setPage($page->lastPage());
            $page = $tasks->paginate($this->filter);
        }

        return view('livewire.task-list', ['tasks' => $page]);
    }
}
