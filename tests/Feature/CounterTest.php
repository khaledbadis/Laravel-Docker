<?php

namespace Tests\Feature;

use App\Livewire\Counter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CounterTest extends TestCase
{
    public function test_counter_actions_update_the_rendered_component(): void
    {
        Livewire::test(Counter::class)
            ->assertSet('count', 0)
            ->call('increment')
            ->assertSet('count', 1)
            ->assertSee('step taken')
            ->call('increment')
            ->assertSet('count', 2)
            ->call('resetCount')
            ->assertSet('count', 0)
            ->assertSee('steps taken');
    }

    public function test_the_browser_cannot_overwrite_the_locked_count(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Counter::class)->set('count', -1);
    }
}
