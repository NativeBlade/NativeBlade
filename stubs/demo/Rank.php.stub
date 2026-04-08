<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use NativeBlade\Facades\NativeBlade;

#[Layout('components.layouts.app')]
class Rank extends Component
{
    public int $xp = 0;
    public int $streak = 0;
    public array $players = [];

    public function mount()
    {
        $this->xp = NativeBlade::getState('trail.xp', 0);
        $this->streak = NativeBlade::getState('trail.streak', 0);

        $this->players = [
            ['rank' => 1, 'name' => 'Lucas Silva', 'xp' => 5840, 'streak' => 32, 'avatar' => 'LS'],
            ['rank' => 2, 'name' => 'Ana Carvalho', 'xp' => 4320, 'streak' => 19, 'avatar' => 'AC'],
            ['rank' => 3, 'name' => 'Pedro Nunes', 'xp' => 3910, 'streak' => 14, 'avatar' => 'PN'],
            ['rank' => 4, 'name' => 'You', 'xp' => $this->xp, 'streak' => $this->streak, 'avatar' => 'ME', 'isMe' => true],
            ['rank' => 5, 'name' => 'Mariana Costa', 'xp' => 1180, 'streak' => 8, 'avatar' => 'MC'],
            ['rank' => 6, 'name' => 'Rafael Lima', 'xp' => 990, 'streak' => 5, 'avatar' => 'RL'],
            ['rank' => 7, 'name' => 'Juliana Reis', 'xp' => 870, 'streak' => 3, 'avatar' => 'JR'],
            ['rank' => 8, 'name' => 'Thiago Melo', 'xp' => 740, 'streak' => 2, 'avatar' => 'TM'],
            ['rank' => 9, 'name' => 'Camila Souza', 'xp' => 620, 'streak' => 1, 'avatar' => 'CS'],
            ['rank' => 10, 'name' => 'Bruno Alves', 'xp' => 510, 'streak' => 1, 'avatar' => 'BA'],
        ];

        usort($this->players, fn($a, $b) => $b['xp'] - $a['xp']);

        foreach ($this->players as $i => &$p) {
            $p['rank'] = $i + 1;
        }
    }

    public function render()
    {
        return view('livewire.rank');
    }
}
