<div>

@if($finished)
    @php $perfect = $score === $totalQuestions; @endphp

    <div class="min-h-screen flex flex-col items-center justify-center p-6 relative overflow-hidden">

        {{-- Confetti --}}
        <div class="absolute inset-0 pointer-events-none overflow-hidden">
            @for($i = 0; $i < 25; $i++)
                @php
                    $colors = ['#e74c3c','#f1c40f','#ffffff','#8B0000','#c0392b'];
                    $color = $colors[array_rand($colors)];
                    $left = rand(5, 95);
                    $delay = rand(0, 500) / 1000;
                    $dur = 2.5 + rand(0, 200) / 100;
                @endphp
                <div nb-animation="confetti" nb-animation-delay="{{ $delay }}s" style="position:absolute;top:-20px;left:{{ $left }}%;width:12px;height:12px;background:{{ $color }};border-radius:2px;animation-duration:{{ $dur }}s"></div>
            @endfor
        </div>

        {{-- Star --}}
        <div class="flex flex-col items-center w-full z-10 flex-1 justify-center mt-8">
            <div nb-animation="jackInTheBox" nb-animation-delay="100ms" class="relative mb-6">
                <div class="absolute inset-0 bg-[#f1c40f] blur-[60px] opacity-40 rounded-full"></div>
                <x-nativeblade-icon name="star-fill" size="112" class="text-[#f1c40f] relative z-10 drop-shadow-[0_0_20px_rgba(241,196,15,0.8)]" />
            </div>

            <h1 nb-animation="fadeInUp" nb-animation-delay="200ms" class="text-4xl font-black text-center uppercase text-[#f1c40f] mb-2">
                Lesson Complete!
            </h1>
            <p nb-animation="fadeInUp" nb-animation-delay="300ms" class="text-[#9ca3af] text-center font-medium mb-8">
                {{ $perfect ? 'Perfect score!' : 'You completed the lesson!' }}
            </p>

            {{-- Stats --}}
            <div nb-animation="fadeInUp" nb-animation-delay="400ms" class="grid grid-cols-3 gap-3 w-full max-w-sm mb-8">
                <div class="bg-[#1a1a1a] border border-[#8B0000] rounded-xl p-3 flex flex-col items-center justify-center shadow-[0_0_15px_rgba(139,0,0,0.2)]">
                    <x-nativeblade-icon name="star-fill" size="24" class="text-[#f1c40f] mb-1" />
                    <span class="text-[10px] text-[#9ca3af] uppercase font-bold text-center">XP Earned</span>
                    <span class="text-[#f1c40f] font-black text-lg">+{{ $score * 10 }}</span>
                </div>
                <div class="bg-[#1a1a1a] border border-[#8B0000] rounded-xl p-3 flex flex-col items-center justify-center shadow-[0_0_15px_rgba(139,0,0,0.2)]">
                    <x-nativeblade-icon name="flame-fill" size="24" class="text-[#e74c3c] mb-1" />
                    <span class="text-[10px] text-[#9ca3af] uppercase font-bold text-center">Streak</span>
                    <span class="text-[#e74c3c] font-black text-lg">{{ $perfect ? '+1' : '—' }}</span>
                </div>
                <div class="bg-[#1a1a1a] border border-[#8B0000] rounded-xl p-3 flex flex-col items-center justify-center shadow-[0_0_15px_rgba(139,0,0,0.2)]">
                    <x-nativeblade-icon name="target" size="24" class="text-[#2ecc71] mb-1" />
                    <span class="text-[10px] text-[#9ca3af] uppercase font-bold text-center">Lessons</span>
                    <span class="text-[#2ecc71] font-black text-lg">{{ $score }}/{{ $totalQuestions }}</span>
                </div>
            </div>

            {{-- XP Bar --}}
            <div nb-animation="fadeInUp" nb-animation-delay="500ms" class="w-full max-w-sm mb-10">
                <div class="flex justify-between text-xs font-bold mb-2">
                    <span class="text-[#f1c40f]">Level {{ intdiv($xp ?? 0, 200) + 1 }}</span>
                    <span class="text-[#9ca3af]">{{ $perfect ? 'Max Level!' : (($xp ?? 0) + $score * 10) . ' XP' }}</span>
                </div>
                <div class="h-4 bg-[#1a1a1a] rounded-full overflow-hidden border border-[#2a2a2a]">
                    <div nb-animation="xpFill" nb-animation-delay="600ms" class="h-full bg-[#f1c40f] rounded-full shadow-[0_0_15px_rgba(241,196,15,0.8)] relative overflow-hidden" style="width:{{ min(100, (($xp ?? 0) + $score * 10) % 200 / 2) }}%">
                        <div nb-animation="shimmer" nb-animation-repeat="infinite" class="absolute top-0 bottom-0 w-[20%] bg-gradient-to-r from-transparent via-white/50 to-transparent"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="w-full max-w-sm mt-auto flex flex-col gap-4 z-10">
            <button nb-feedback nb-animation="fadeInUp" nb-animation-delay="500ms" wire:nb-navigate.replace="/"
                class="w-full bg-[#c0392b] text-white font-black text-lg py-4 rounded-xl shadow-[0_4px_0_rgba(139,0,0,1)] active:translate-y-[4px] active:shadow-none transition-all uppercase tracking-wider relative overflow-hidden group">
                Continue
            </button>
            <button nb-feedback nb-animation="fadeInUp" nb-animation-delay="600ms" wire:nb-bridge="toast" wire:nb-payload='{"message":"Coming soon!","type":"info"}'
                class="w-full bg-transparent border-2 border-[#2a2a2a] text-[#9ca3af] font-bold text-base py-3 rounded-xl hover:text-white hover:border-[#9ca3af] transition-all uppercase tracking-wide flex items-center justify-center gap-2">
                <x-nativeblade-icon name="share-network" size="20" />
                Share
            </button>
        </div>

        <p nb-animation="fadeIn" nb-animation-delay="700ms" class="mt-5 text-[#9ca3af] text-xs font-bold uppercase tracking-widest flex items-center gap-1 z-10">
            <x-nativeblade-icon name="star-fill" size="12" class="text-[#f1c40f]" />
            Lesson completed!
        </p>
    </div>

@else
    <div class="min-h-screen flex flex-col">

        {{-- Header --}}
        <header class="fixed top-0 left-0 right-0 z-50 bg-[#0a0a0a] px-4 pb-3 flex items-center justify-between gap-4" style="padding-top:max(var(--nb-safe-top), 30px)">
            <button wire:nb-navigate="/" nb-feedback class="text-[#9ca3af] active:text-white transition-colors">
                <x-nativeblade-icon name="x" size="24" />
            </button>
            <div class="flex-1 h-3 bg-[#1a1a1a] rounded-full overflow-hidden border border-[#2a2a2a]">
                <div class="h-full bg-[#c0392b] rounded-full shadow-[0_0_10px_rgba(192,57,43,0.5)] transition-all duration-700"
                     style="width: {{ (($currentQuestion + ($answered ? 1 : 0)) / $totalQuestions) * 100 }}%"></div>
            </div>
            <div class="flex items-center gap-1">
                <x-nativeblade-icon name="heart-fill" size="20" class="text-[#e74c3c]" />
                <x-nativeblade-icon name="heart-fill" size="20" class="text-[#e74c3c]" />
                <x-nativeblade-icon name="heart-fill" size="20" class="text-[#e74c3c]" style="opacity:0.4" />
            </div>
        </header>

        <div class="h-16"></div>

        {{-- Question --}}
        <div class="flex-1 px-4 py-6 flex flex-col">
            <div nb-animation="fadeInRight" nb-animation-speed="fast" class="mb-3">
                <p class="text-[#9ca3af] text-sm font-bold uppercase tracking-widest mb-3">Translate this word in portuguese</p>
                <h2 class="text-3xl font-black tracking-tight leading-tight">
                    {{ $questions[$currentQuestion]['q'] }}
                </h2>
            </div>

            <div class="space-y-3 mt-auto mb-4">
                @foreach($questions[$currentQuestion]['options'] as $idx => $option)
                    @php
                        $isSelected = $selected === $option;
                        $isCorrect = $correct === $option;
                        $isWrong = $isSelected && !$isCorrect && $answered;
                        $letters = ['A','B','C','D'];

                        if ($answered && $isCorrect) {
                            $optClasses = 'bg-[rgba(46,204,113,0.1)] border-[#2ecc71] shadow-[0_4px_0_rgba(46,204,113,0.5)]';
                            $labelClasses = 'text-[#2ecc71]';
                            $badgeClasses = 'bg-[#2ecc71] text-black';
                            $animName = 'tada';
                        } elseif ($isWrong) {
                            $optClasses = 'bg-[rgba(231,76,60,0.1)] border-[#e74c3c] shadow-[0_4px_0_rgba(231,76,60,0.5)]';
                            $labelClasses = 'text-[#e74c3c]';
                            $badgeClasses = 'bg-[#e74c3c] text-white';
                            $animName = 'shakeX';
                        } elseif ($isSelected) {
                            $optClasses = 'bg-[rgba(192,57,43,0.1)] border-[#c0392b] shadow-[0_4px_0_rgba(192,57,43,1)] -translate-y-[2px]';
                            $labelClasses = 'text-white';
                            $badgeClasses = 'bg-[#c0392b] text-white';
                            $animName = '';
                        } else {
                            $optClasses = 'bg-[#1a1a1a] border-[#2a2a2a] shadow-[0_4px_0_rgba(42,42,42,1)] hover:bg-[#222]';
                            $labelClasses = 'text-[#d1d5db]';
                            $badgeClasses = 'bg-[#2a2a2a] text-[#9ca3af]';
                            $animName = '';
                        }
                    @endphp

                    <button wire:click="select('{{ $option }}')"
                        @if($answered) disabled @endif
                        @if($animName) nb-animation="{{ $animName }}" nb-animation-speed="fast" @endif
                        nb-animation="{{ !$answered && !$animName ? 'fadeInUp' : ($animName ?: '') }}"
                        nb-animation-delay="{{ $idx * 80 }}ms"
                        @if(!$answered && !$animName) nb-animation-speed="fast" @endif
                        class="w-full text-left p-4 rounded-xl border-2 transition-all flex items-center gap-4 {{ $optClasses }} {{ !$answered ? 'active:translate-y-[2px] active:shadow-none' : '' }}"
                        >
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center font-bold text-sm flex-shrink-0 {{ $badgeClasses }}">
                            {{ $letters[$idx] }}
                        </div>
                        <span class="font-medium text-lg flex-1 {{ $labelClasses }}">{{ $option }}</span>
                        @if($answered && $isCorrect)
                            <x-nativeblade-icon name="check-circle" size="20" class="text-[#2ecc71]" />
                        @elseif($isWrong)
                            <x-nativeblade-icon name="x-circle" size="20" class="text-[#e74c3c]" />
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Bottom --}}
        @if($answered)
            <div nb-animation="slideInUp" nb-animation-speed="fast" class="sticky bottom-0 p-4 border-t {{ $selected === $correct ? 'bg-[#0f1a0f] border-[#2ecc71]' : 'bg-[#1a0a0a] border-[#e74c3c]' }}" style="padding-bottom:max(var(--nb-safe-bottom), 60px)">
                <div class="flex items-center gap-2 mb-3">
                    @if($selected === $correct)
                        <x-nativeblade-icon name="check-circle" size="24" class="text-[#2ecc71]" />
                        <p class="font-black text-lg text-[#2ecc71]">Correct!</p>
                    @else
                        <x-nativeblade-icon name="x-circle" size="24" class="text-[#e74c3c]" />
                        <p class="font-black text-lg text-[#e74c3c]">Incorrect!</p>
                    @endif
                </div>
                @if($selected !== $correct)
                    <p class="text-[#9ca3af] text-sm mb-3">
                        Correct answer: <span class="text-white font-bold">{{ $correct }}</span>
                    </p>
                @endif
                <button wire:click="next"
                    class="w-full py-4 rounded-xl font-bold text-lg uppercase tracking-wide text-white active:translate-y-[4px] active:shadow-none transition-all
                    {{ $selected === $correct
                        ? 'bg-[#2ecc71] shadow-[0_4px_0_rgba(39,174,96,1)]'
                        : 'bg-[#e74c3c] shadow-[0_4px_0_rgba(192,57,43,1)]' }}">
                    {{ $currentQuestion + 1 >= $totalQuestions ? 'See Results' : 'Continue' }}
                </button>
            </div>
        @else
            <div class="sticky bottom-0 p-4 border-t border-[#2a2a2a] bg-[#111111]" style="padding-bottom:max(var(--nb-safe-bottom), 60px)">
                <p class="text-[#9ca3af] text-sm text-center mb-3 font-medium">Select the best answer</p>
                <button wire:click="check"
                    class="w-full py-4 rounded-xl font-bold text-lg uppercase tracking-wide transition-all
                    {{ $selected
                        ? 'bg-[#8B0000] text-white shadow-[0_4px_0_rgba(60,0,0,1)] active:translate-y-[4px] active:shadow-none hover:bg-[#c0392b]'
                        : 'bg-[#2a2a2a] text-[#6b7280] cursor-not-allowed' }}">
                    Check
                </button>
            </div>
        @endif
    </div>
@endif
</div>
