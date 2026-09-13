<?php

namespace Tests\Feature;

use App\Livewire\Counter;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->withoutVite()->get('/');

        $response->assertStatus(200)->assertSeeLivewire(Counter::class);
    }
}
