<?php

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class Counter extends Component
{
    #[Locked]
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function resetCount(): void
    {
        $this->count = 0;
    }

    public function render(): View
    {
        return view('livewire.counter');
    }
}
