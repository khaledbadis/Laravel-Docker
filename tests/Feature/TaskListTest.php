<?php

namespace Tests\Feature;

use App\Livewire\TaskList;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class TaskListTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_lifecycle_persists_through_the_interface(): void
    {
        $user = User::factory()->create();
        $ui = Livewire::actingAs($user)->test(TaskList::class)
            ->set('title', '  First step  ')->set('notes', 'Details')
            ->call('save')->assertHasNoErrors()->assertSee('Task added.')->assertSet('title', '');
        $task = $user->tasks()->sole();
        $this->assertSame('First step', $task->title);
        $ui->call('edit', $task->id)->assertSet('notes', 'Details')
            ->set('title', 'Updated step')->set('notes', '')->call('save')->assertHasNoErrors();
        $this->assertSame('Updated step', $task->refresh()->title);
        $this->assertNull($task->notes);
        $ui->call('complete', $task->id, true)->assertSee('Task completed.');
        $this->assertNotNull($task->refresh()->completed_at);
        $ui->call('complete', $task->id, false)->assertSee('Task reopened.');
        $this->assertNull($task->refresh()->completed_at);
        Livewire::test(TaskList::class)->assertSee('Updated step');
        $ui->call('delete');
        $this->assertModelExists($task);
        $ui->call('requestDelete', $task->id)->assertSee('Yes, delete task')->call('cancelDelete');
        $this->assertModelExists($task);
        $ui->call('edit', $task->id)->call('requestDelete', $task->id)->call('delete')
            ->assertSet('editingId', null)->assertSee('Task deleted.');
        $this->assertModelMissing($task);
    }

    public function test_validation_and_cancelling_edit(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create();
        Livewire::actingAs($user)->test(TaskList::class)
            ->set('title', '   ')->call('save')->assertHasErrors(['title' => 'required'])
            ->set('title', str_repeat('a', 256))->set('notes', str_repeat('b', 5001))
            ->call('save')->assertHasErrors(['title' => 'max', 'notes' => 'max'])
            ->call('edit', $task->id)->assertHasNoErrors()
            ->set('title', 'Unsaved')->call('cancelEdit')->assertSet('title', '')->assertSet('editingId', null);
        $this->assertNotSame('Unsaved', $task->refresh()->title);
        $this->assertSame(1, $user->tasks()->count());
    }

    public function test_filters_and_last_page_changes(): void
    {
        $user = User::factory()->create();
        $tasks = Task::factory()->for($user)->count(21)->create();
        Task::factory()->create(['title' => 'Private other task']);
        $ui = Livewire::actingAs($user)->test(TaskList::class)->assertDontSee('Private other task')
            ->call('gotoPage', 2)->assertSee($tasks->first()->title)
            ->call('showFilter', 'active')->assertSet('paginators.page', 1)
            ->call('gotoPage', 2)->call('complete', $tasks->first()->id, true)
            ->assertSet('paginators.page', 1)
            ->call('showFilter', 'completed')->assertSee($tasks->first()->title)
            ->call('complete', $tasks->first()->id, false)->assertSee('No completed tasks yet.')
            ->call('showFilter', 'all')->call('gotoPage', 2)
            ->call('requestDelete', $tasks->first()->id)->call('delete')->assertSet('paginators.page', 1)
            ->call('gotoPage', 999)->assertSet('paginators.page', 1);
        $ui->call('showFilter', 'completed')->set('title', 'New visible task')->call('save')
            ->assertSet('filter', 'all')->assertSet('paginators.page', 1)->assertSee('New visible task');
    }

    public function test_direct_actions_cannot_access_another_users_task(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create();
        foreach (['edit', 'requestDelete', 'complete'] as $action) {
            try {
                Livewire::actingAs($user)->test(TaskList::class)->call($action, $task->id, true);
                $this->fail('Another owner’s task was accessible.');
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(Task::class, $exception->getModel());
            }
        }
        $this->assertModelExists($task);
        $this->assertNull($task->refresh()->completed_at);
    }

    public function test_save_and_delete_recheck_ownership_after_selection(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create();
        $edit = Livewire::actingAs($user)->test(TaskList::class)->call('edit', $task->id);
        $delete = Livewire::test(TaskList::class)->call('requestDelete', $task->id);
        $task->user()->associate(User::factory()->create());
        $task->save();
        foreach ([[$edit, 'save'], [$delete, 'delete']] as [$component, $action]) {
            try {
                $component->set('title', 'Stolen')->call($action);
                $this->fail('Ownership was not checked again.');
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(Task::class, $exception->getModel());
            }
        }
        $this->assertModelExists($task);
        $this->assertNotSame('Stolen', $task->refresh()->title);
    }

    public function test_selected_task_id_cannot_be_overwritten(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs(User::factory()->create())->test(TaskList::class)->set('deletingId', 123);
    }

    public function test_invalid_filter_is_rejected(): void
    {
        Livewire::actingAs(User::factory()->create())->test(TaskList::class)->call('showFilter', 'invalid')->assertStatus(422);
    }
}
