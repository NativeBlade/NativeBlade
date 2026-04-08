<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;
use NativeBlade\Facades\NativeBlade;

class NbSafe extends Component
{
    public function __construct(
        public bool $top = true,
        public bool $bottom = true,
    ) {}

    public function render()
    {
        return view('nativeblade::components.nativeblade.safe', [
            'platform' => NativeBlade::platform(),
            'isIos' => NativeBlade::isIos(),
            'isAndroid' => NativeBlade::isAndroid(),
            'isMobile' => NativeBlade::isMobile(),
            'isDesktop' => NativeBlade::isDesktop(),
        ]);
    }
}
