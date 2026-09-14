<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class TaskPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_can_access_an_individual_task(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $task = (new Task)->forceFill(['user_id' => $owner->id]);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability, $task));
            $this->assertFalse(Gate::forUser($other)->allows($ability, $task));
            $this->assertFalse(Gate::allows($ability, $task));
        }
        $this->assertTrue(Gate::forUser($owner)->allows('create', Task::class));
        $this->assertFalse(Gate::allows('create', Task::class));
    }
}
