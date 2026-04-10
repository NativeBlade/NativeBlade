<div class="min-h-screen w-full bg-[#0a0a0a] text-white flex flex-col items-center justify-center p-6 relative overflow-hidden">

    <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[300px] h-[300px] bg-[#8B0000] rounded-full blur-[120px] opacity-30 pointer-events-none"></div>

    <div class="w-full max-w-sm flex flex-col items-center z-10">

        <div nb-animation="fadeInUp" class="flex flex-col items-center mb-12">
            <div class="relative mb-6">
                <x-nativeblade-image asset="logo_nb.png" class="w-20 h-20 rounded-2xl drop-shadow-[0_0_15px_rgba(231,76,60,0.8)]" />
                <div class="absolute inset-0 bg-[#e74c3c] blur-2xl opacity-40 rounded-full"></div>
            </div>
            <h1 class="text-4xl font-black tracking-tighter mb-2 text-center uppercase">
                Native<span class="text-[#c0392b]">Blade</span>
            </h1>
            <p class="text-[#9ca3af] text-center font-medium max-w-[250px]">
                Master any language. One challenge at a time.
            </p>
        </div>

        @if($error)
            <x-nativeblade-animate in="shakeX" out="fadeOutUp" dismiss="3s" speed="fast" class="w-full bg-[#e74c3c]/10 border border-[#e74c3c] text-[#e74c3c] px-4 py-3 rounded-xl mb-4 text-sm font-medium">
                {{ $error }}
            </x-nativeblade-animate>
        @endif

        <div nb-animation="fadeInUp" nb-animation-delay="200ms" class="w-full space-y-4 mb-3">
            <input type="email" wire:model="email"
                placeholder="Email"
                class="w-full bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl px-4 py-4 text-white placeholder:text-[#9ca3af] outline-none focus:border-[#c0392b] focus:shadow-[0_0_10px_rgba(192,57,43,0.3)] transition-all" />
            <input type="password" wire:model="password"
                placeholder="Password"
                class="w-full bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl px-4 py-4 text-white placeholder:text-[#9ca3af] outline-none focus:border-[#c0392b] focus:shadow-[0_0_10px_rgba(192,57,43,0.3)] transition-all" />
        </div>

        <div nb-animation="fadeIn" nb-animation-delay="350ms" class="w-full mb-6 px-1">
            <p class="text-[#9ca3af] text-xs">
                Default access: <span class="text-[#e74c3c] font-mono">admin@admin.com</span> / <span class="text-[#e74c3c] font-mono">123456</span>
            </p>
        </div>

        <div nb-animation="fadeInUp" nb-animation-delay="400ms" class="w-full flex flex-col items-center gap-4">
            <button wire:click="login" nb-feedback
                class="w-full bg-[#8B0000] hover:bg-[#c0392b] text-white font-bold text-lg py-4 rounded-xl shadow-[0_4px_0_rgba(60,0,0,1)] active:shadow-none active:translate-y-[4px] transition-all uppercase tracking-wide">
                Sign In
            </button>
            <p class="text-[#4b5563] text-xs text-center">
                Forgot your password? <span class="text-[#6b7280] underline">Reset access</span>
            </p>
        </div>
    </div>
</div>
