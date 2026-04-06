<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;

class NbDrawerItem extends Component
{
    public function __construct(
        public string $icon = '',
        public string $label = '',
        public string $href = '/',
    ) {}

    public function render()
    {
        return view('nativeblade::components.nativeblade.drawer-item');
    }
}
