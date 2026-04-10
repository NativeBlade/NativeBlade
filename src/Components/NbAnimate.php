<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;

class NbAnimate extends Component
{
    public function __construct(
        public string $in = 'fadeIn',
        public string $out = '',
        public string $delay = '',
        public string $speed = '',
        public string $repeat = '',
        public bool $once = false,
        public string $dismiss = '',
    ) {}

    public function render()
    {
        return view('nativeblade::components.nativeblade.animate');
    }
}
