<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use NativeBlade\Facades\NativeBlade;

#[Layout('components.layouts.app')]
class Trail extends Component
{
    public string $userName = '';
    public int $xp = 0;
    public int $streak = 0;
    public array $lessons = [];

    public function mount()
    {
        $user = NativeBlade::getState('auth.user');
        $this->userName = $user['name'] ?? 'Guest';
        $this->xp = NativeBlade::getState('trail.xp', 0);
        $this->streak = NativeBlade::getState('trail.streak', 0);

        $completed = NativeBlade::getState('trail.completed', []);

        $this->lessons = [
            ['id' => 1, 'title' => 'Basics',    'icon' => 'star',         'completed' => in_array(1, $completed), 'locked' => false],
            ['id' => 2, 'title' => '',           'icon' => 'translate',    'completed' => in_array(2, $completed), 'locked' => !in_array(1, $completed)],
            ['id' => 3, 'title' => '',           'icon' => 'book-open',    'completed' => in_array(3, $completed), 'locked' => !in_array(2, $completed)],
            ['id' => 4, 'title' => 'Phrases',    'icon' => 'chat-circle',  'completed' => in_array(4, $completed), 'locked' => !in_array(3, $completed)],
            ['id' => 5, 'title' => '',           'icon' => 'headphones',   'completed' => in_array(5, $completed), 'locked' => !in_array(4, $completed)],
            ['id' => 6, 'title' => '',           'icon' => 'pencil-simple','completed' => in_array(6, $completed), 'locked' => !in_array(5, $completed)],
            ['id' => 7, 'title' => 'Grammar',    'icon' => 'brain',        'completed' => in_array(7, $completed), 'locked' => !in_array(6, $completed)],
        ];
    }

    public function render()
    {
        return view('livewire.trail');
    }
}
