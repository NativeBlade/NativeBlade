@php
    $styles = '';
    if ($top) {
        $styles .= $isIos
            ? 'padding-top:env(safe-area-inset-top, 0px);'
            : ($isAndroid ? 'padding-top:24px;' : ($isDesktop ? 'padding-top:0;' : 'padding-top:env(safe-area-inset-top, 0px);'));
    }
    if ($bottom) {
        $styles .= $isIos
            ? 'padding-bottom:env(safe-area-inset-bottom, 0px);'
            : ($isAndroid ? 'padding-bottom:0;' : 'padding-bottom:env(safe-area-inset-bottom, 0px);');
    }
@endphp
<div style="{{ $styles }}">{{ $slot }}</div>
