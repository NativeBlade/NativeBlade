<div>
<style>::-webkit-scrollbar { width: 0; background: transparent; }</style>

{{-- Shell Header with custom slot --}}
<x-nativeblade-header bg="#111111" border-color="#2a2a2a">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;max-width:600px;margin:0 auto">
        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:#e74c3c">
            <x-nativeblade-icon name="flame-fill" size="20" class="text-[#e74c3c]" />
            <span>{{ $streak }}</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex:1;margin:0 16px">
            <span style="font-weight:700;color:#f1c40f;font-size:12px">XP</span>
            <div style="flex:1;height:12px;background:#1a1a1a;border-radius:9999px;overflow:hidden;border:1px solid #2a2a2a">
                <div style="height:100%;background:#f1c40f;border-radius:9999px;box-shadow:0 0 8px rgba(241,196,15,0.5);width:{{ min(($xp / max(($xp + 500), 1)) * 100, 100) }}%"></div>
            </div>
            <span style="font-weight:700;font-size:11px;color:#9ca3af">{{ number_format($xp) }}</span>
        </div>
        <div style="display:flex;align-items:center;gap:2px;color:#e74c3c">
            <x-nativeblade-icon name="heart-fill" size="20" class="text-[#e74c3c]" />
            <x-nativeblade-icon name="heart-fill" size="20" class="text-[#e74c3c]" />
            <x-nativeblade-icon name="heart-fill" size="20" class="text-[#e74c3c]" style="opacity:0.4" />
        </div>
    </div>
</x-nativeblade-header>

{{-- Trail --}}
<div class="flex-1 overflow-y-auto pb-24" style="-webkit-overflow-scrolling:touch">
    @php
        $positions = [195, 268, 268, 195, 122, 122, 195];
        $nodes = [];
        foreach ($lessons as $i => $lesson) {
            $nodes[] = ['cx' => $positions[$i % 7], 'cy' => 70 + $i * 130, 'lesson' => $lesson];
        }
        $totalH = count($lessons) * 130 + 100;
    @endphp

    <div class="relative mx-auto" style="width:390px;height:{{ $totalH }}px">
        <svg width="390" height="{{ $totalH }}" class="absolute inset-0 z-0" style="overflow:visible">
            @for($i = 0; $i < count($nodes) - 1; $i++)
                @php
                    $f = $nodes[$i]; $t = $nodes[$i + 1];
                    $midY = ($f['cy'] + $t['cy']) / 2;
                    $lit = !$f['lesson']['locked'] && !$t['lesson']['locked'];
                @endphp
                <path d="M {{ $f['cx'] }},{{ $f['cy'] }} C {{ $f['cx'] }},{{ $midY }} {{ $t['cx'] }},{{ $midY }} {{ $t['cx'] }},{{ $t['cy'] }}"
                    fill="none" stroke="{{ $lit ? '#8B0000' : '#2a2a2a' }}" stroke-width="8" stroke-linecap="round"
                    @if($lit) style="filter:drop-shadow(0 0 6px rgba(139,0,0,0.9))" @endif />
            @endfor
        </svg>

        @foreach($nodes as $i => $node)
            @php
                $l = $node['lesson']; $cx = $node['cx']; $cy = $node['cy'];
                $active = !$l['locked'] && !$l['completed'];
                $sz = $active ? 80 : ($l['completed'] ? 64 : 60);
                $half = $sz / 2;
            @endphp
            <div nb-animation="zoomIn" nb-animation-delay="{{ $i * 100 }}ms" nb-animation-speed="fast"
                 class="absolute z-10 flex flex-col items-center"
                 style="left:{{ $cx - $half }}px;top:{{ $cy - $half }}px;width:{{ $sz }}px;height:{{ $sz }}px">

                @if($l['title'])
                    <div class="absolute whitespace-nowrap text-sm font-bold text-center"
                         style="bottom:{{ $sz + 8 }}px;left:50%;transform:translateX(-50%);color:{{ $l['locked'] ? '#4b5563' : '#fff' }}">
                        {{ $l['title'] }}
                    </div>
                @endif

                @if($active)
                    <div nb-animation="bounceIn" nb-animation-delay="{{ $i * 100 + 300 }}ms"
                         class="absolute whitespace-nowrap bg-white text-[#0a0a0a] font-bold text-xs py-1.5 px-3 rounded-lg shadow-lg uppercase z-20"
                         style="bottom:{{ $sz + 10 }}px;left:50%;transform:translateX(-50%)">
                        Start
                        <div class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 w-0 h-0 border-l-[6px] border-l-transparent border-r-[6px] border-r-transparent border-t-[6px] border-t-white"></div>
                    </div>
                    <div nb-animation="pulseGlow" class="absolute inset-0 rounded-full bg-[#c0392b] blur-xl z-0" style="margin:-12px"></div>
                @endif

                @if($l['locked'])
                    <div class="w-full h-full rounded-full border-[3px] bg-[#1a1a1a] border-[#2a2a2a] shadow-[0_4px_0_rgba(42,42,42,1)] opacity-60 flex items-center justify-center">
                        <x-nativeblade-icon name="lock-simple" size="{{ (int)($sz * 0.44) }}" class="text-[#9ca3af]" />
                    </div>
                @elseif($l['completed'])
                    <div class="w-full h-full rounded-full border-[3px] bg-[#8B0000] border-[#c0392b] shadow-[0_5px_0_rgba(192,57,43,0.5),0_0_18px_rgba(139,0,0,0.7)] flex items-center justify-center">
                        <x-nativeblade-icon name="check" size="{{ (int)($sz * 0.48) }}" class="text-white" />
                    </div>
                @else
                    <button wire:nb-navigate="/lesson/{{ $l['id'] }}" nb-feedback
                        class="w-full h-full rounded-full border-[3px] bg-[#c0392b] border-[#e74c3c] shadow-[0_6px_0_rgba(139,0,0,1),0_0_28px_rgba(231,76,60,0.9)] flex items-center justify-center active:scale-95 active:shadow-[0_2px_0_rgba(139,0,0,1)] active:translate-y-[4px] transition-all relative z-10">
                        <x-nativeblade-icon name="{{ $l['icon'] }}" size="{{ (int)($sz * 0.5) }}" class="text-white" />
                    </button>
                @endif
            </div>
        @endforeach
    </div>
</div>

{{-- Shell Bottom Nav with custom slot --}}
<x-nativeblade-bottom-nav bg="#111111" border-color="#2a2a2a">
    <div style="display:flex;justify-content:space-around;align-items:center;padding:12px 24px 24px;max-width:600px;margin:0 auto">
        <div style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#e74c3c;cursor:pointer">
            <x-nativeblade-icon name="map-trifold" size="24" />
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em">Trail</span>
        </div>
        <div data-nav="/rank" style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#9ca3af;cursor:pointer">
            <x-nativeblade-icon name="trophy" size="24" />
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em">Rank</span>
        </div>
        <div data-nav="/profile" style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#9ca3af;cursor:pointer">
            <x-nativeblade-icon name="user" size="24" />
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em">Profile</span>
        </div>
    </div>
</x-nativeblade-bottom-nav>
</div>
