<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;

class NbDrawer extends Component
{
    public function __construct(
        public string $title = '',
        public string $bg = '',
        public string $color = '',
        public string $borderColor = '',
    ) {}

    public function render()
    {
        return view('nativeblade::components.nativeblade.drawer');
    }
}
