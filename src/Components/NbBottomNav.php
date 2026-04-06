<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;

class NbBottomNav extends Component
{
    public function __construct(
        public string $bg = '',
        public string $color = '',
        public string $activeColor = '',
        public string $borderColor = '',
    ) {}

    public function render()
    {
        return view('nativeblade::components.nativeblade.bottom-nav');
    }
}
