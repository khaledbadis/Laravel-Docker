<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TaskPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_normalizes_input_and_ignores_forged_owner_and_completion(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);
        $task = app(TaskService::class)->create([
            'title' => '  Buy groceries  ', 'notes' => '  Milk and bread  ',
            'user_id' => $other->id, 'completed_at' => now(), 'id' => 9999,
        ])->fresh();
        $this->assertSame('Buy groceries', $task->title);
        $this->assertSame('Milk and bread', $task->notes);
        $this->assertSame($owner->id, $task->user_id);
        $this->assertNull($task->completed_at);
        $this->assertTrue($task->user->is($owner));
        $this->assertTrue($owner->tasks()->sole()->is($task));
    }

    public function test_boundaries_and_optional_notes(): void
    {
        $this->actingAs(User::factory()->create());
        $service = app(TaskService::class);
        $task = $service->create(['title' => str_repeat('é', 255), 'notes' => str_repeat('é', 5000)])->fresh();
        $this->assertSame(255, mb_strlen($task->title));
        $this->assertSame(5000, mb_strlen($task->notes));
        $this->assertNull($service->create(['title' => 'x', 'notes' => '  '])->fresh()->notes);
        $this->assertNull($service->create(['title' => 'y'])->fresh()->notes);
        $this->assertNull($service->create(['title' => 'z', 'notes' => null])->fresh()->notes);
    }

    public static function invalidInput(): array
    {
        return [
            'missing title' => [[], 'title'],
            'blank title' => [['title' => " \t\n"], 'title'],
            'null title' => [['title' => null], 'title'],
            'array title' => [['title' => ['invalid']], 'title'],
            'long title' => [['title' => str_repeat('a', 256)], 'title'],
            'long notes' => [['title' => 'Valid', 'notes' => str_repeat('n', 5001)], 'notes'],
            'array notes' => [['title' => 'Valid', 'notes' => []], 'notes'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_does_not_write_records(array $input, string $field): void
    {
        $this->actingAs(User::factory()->create());
        try {
            app(TaskService::class)->create($input);
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_update_completion_reopening_and_delete_persist(): void
    {
        $this->freezeSecond();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $service = app(TaskService::class);
        $task = $service->create(['title' => 'Before', 'notes' => 'Keep']);
        $service->update($task->id, ['title' => ' After ', 'user_id' => 9999]);
        $this->assertSame('After', $task->fresh()->title);
        $this->assertSame('Keep', $task->fresh()->notes);
        $service->setCompleted($task->id, true);
        $completed = $task->fresh()->completed_at;
        $this->assertTrue($completed->equalTo(now()));
        $this->travel(10)->minutes();
        $service->setCompleted($task->id, true);
        $this->assertTrue($task->fresh()->completed_at->equalTo($completed));
        $service->update($task->id, ['title' => 'After', 'notes' => null, 'completed_at' => null]);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertNull($task->fresh()->notes);
        $service->setCompleted($task->id, false);
        $this->assertNull($task->fresh()->completed_at);
        $service->delete($task->id);
        $this->assertModelMissing($task);
    }

    public function test_invalid_update_leaves_existing_values_untouched(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $task = Task::factory()->for($user)->create(['title' => 'Keep this']);
        try {
            app(TaskService::class)->update($task->id, ['title' => '']);
            $this->fail('Expected validation failure.');
        } catch (ValidationException) {
            $this->assertSame('Keep this', $task->fresh()->title);
        }
    }

    public function test_other_users_cannot_find_update_complete_reopen_or_delete_tasks(): void
    {
        $task = Task::factory()->create(['title' => 'Private']);
        $this->actingAs(User::factory()->create());
        $service = app(TaskService::class);
        foreach (['find' => [], 'update' => [['title' => 'Changed']], 'setCompleted' => [true], 'delete' => []] as $method => $args) {
            try {
                $service->$method($task->id, ...$args);
                $this->fail("$method must not expose another user's task.");
            } catch (ModelNotFoundException) {
                $this->assertSame('Private', $task->fresh()->title);
                $this->assertNull($task->fresh()->completed_at);
            }
        }
        $this->assertSame(0, $service->paginate()->total());
        $this->expectException(ModelNotFoundException::class);
        $service->setCompleted($task->id, false);
    }

    public function test_guests_cannot_use_the_service(): void
    {
        $service = app(TaskService::class);
        foreach (['paginate' => [], 'create' => [['title' => 'Guest']], 'find' => [1], 'update' => [1, ['title' => 'Guest']], 'setCompleted' => [1, true], 'delete' => [1]] as $method => $args) {
            try {
                $service->$method(...$args);
                $this->fail('Expected authentication failure.');
            } catch (HttpException $e) {
                $this->assertSame(401, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_policy_denial_is_enforced_even_for_an_owned_task(): void
    {
        $owner = User::factory()->create();
        $task = Task::factory()->for($owner)->create();
        $this->actingAs($owner);
        Gate::before(fn () => false);
        $this->expectException(AuthorizationException::class);
        app(TaskService::class)->update($task->id, ['title' => 'Denied']);
    }

    public function test_filters_pagination_and_tie_breaking_are_owner_scoped(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        Task::factory()->for($owner)->count(21)->create();
        $done = Task::factory()->for($owner)->completed()->create();
        Task::factory()->count(2)->create();
        $service = app(TaskService::class);
        $all = $service->paginate();
        $this->assertSame(22, $all->total());
        $this->assertCount(20, $all->items());
        $this->assertTrue($all->first()->is($done));
        $this->assertSame(21, $service->paginate('active')->total());
        $this->assertSame([$done->id], $service->paginate('completed')->pluck('id')->all());
        Paginator::currentPageResolver(fn () => 2);
        try {
            $this->assertCount(2, $service->paginate()->items());
        } finally {
            Paginator::currentPageResolver(fn () => 1);
        }
        $this->expectException(ValidationException::class);
        $service->paginate('invalid');
    }

    public static function invalidDatabaseRows(): array
    {
        return [
            'foreign key' => [['user_id' => 999999]],
            'null owner' => [['user_id' => null]],
            'null title' => [['title' => null]],
            'blank title' => [['title' => " \t\n"]],
            'long title' => [['title' => str_repeat('x', 256)]],
            'long notes' => [['notes' => str_repeat('x', 5001)]],
        ];
    }

    #[DataProvider('invalidDatabaseRows')]
    public function test_postgresql_rejects_invalid_rows(array $overrides): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('tasks')->insert(array_replace(['user_id' => $user->id, 'title' => 'Valid'], $overrides));
    }

    public function test_deleting_a_user_cascades_only_their_tasks(): void
    {
        $owner = User::factory()->create();
        $task = Task::factory()->for($owner)->create();
        $other = Task::factory()->create();
        $owner->delete();
        $this->assertModelMissing($task);
        $this->assertModelExists($other);
    }

    public function test_default_seeding_is_empty_and_demo_seeding_rejects_nonlocal_environments(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 0);
        $this->expectException(RuntimeException::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_demo_seeding_is_repeatable_and_preserves_existing_data(): void
    {
        $this->app['env'] = 'local';
        try {
            $this->seed(DemoSeeder::class);
            $user = User::where('email', 'demo@example.test')->sole();
            $hash = $user->password;
            $task = $user->tasks()->first();
            $task->update(['title' => 'My edited demo task']);
            $this->seed(DemoSeeder::class);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('tasks', 4);
            $this->assertSame($hash, $user->fresh()->password);
            $this->assertSame('My edited demo task', $task->fresh()->title);
            $this->assertSame(1, $user->tasks()->whereNotNull('completed_at')->count());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
