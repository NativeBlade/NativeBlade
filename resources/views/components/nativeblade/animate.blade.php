<div {{ $attributes }}
    nb-animation="{{ $in }}"
    @if($out) nb-animation-out="{{ $out }}" @endif
    @if($delay) nb-animation-delay="{{ $delay }}" @endif
    @if($speed) nb-animation-speed="{{ $speed }}" @endif
    @if($repeat) nb-animation-repeat="{{ $repeat }}" @endif
    @if($dismiss) nb-animation-dismiss="{{ $dismiss }}" @endif
>{{ $slot }}</div>
