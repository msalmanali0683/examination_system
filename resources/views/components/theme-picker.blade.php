@php
    $themes = \App\Support\Themes::all();
    $modes = ['light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon'], 'system' => ['System', 'computer']];
@endphp

{{--
    Colour theme + light/dark picker. Picking applies instantly in the browser
    (window.appearance, see partials/theme-init) and saves to the account.
    Each swatch sits inside its own data-theme, so it paints in that theme's
    own accent.
--}}
<div
    x-data="{
        theme: document.documentElement.dataset.theme,
        mode: document.documentElement.dataset.appearance,
        set(theme, mode) {
            this.theme = theme;
            this.mode = mode;
            window.appearance.save(theme, mode);
        },
    }"
    x-on:appearance-changed.window="theme = $event.detail.theme; mode = $event.detail.mode"
    {{ $attributes->merge(['class' => 'space-y-4']) }}
>
    <div>
        <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Color theme</div>
        <div class="grid grid-cols-4 gap-1.5">
            @foreach ($themes as $key => $theme)
                <button type="button" data-theme="{{ $key }}" title="{{ $theme['label'] }}"
                    x-on:click="set('{{ $key }}', mode)" :aria-pressed="theme === '{{ $key }}'"
                    class="flex flex-col items-center gap-1 rounded-lg p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                    <span class="relative flex h-9 w-9 items-center justify-center overflow-hidden rounded-full bg-primary-600 ring-2 ring-offset-2 ring-offset-white transition dark:ring-offset-gray-800"
                        :class="theme === '{{ $key }}' ? 'ring-primary-600' : 'ring-transparent'">
                        <span class="absolute inset-y-0 right-0 w-1/2 bg-primary-300"></span>
                        <span x-show="theme === '{{ $key }}'" class="relative flex"><x-icon name="check" class="h-4 w-4 text-white" /></span>
                    </span>
                    <span class="text-[11px] leading-none text-gray-600 dark:text-gray-300">{{ $theme['label'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div>
        <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Appearance</div>
        <div class="grid grid-cols-3 gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-900/60">
            @foreach ($modes as $value => [$label, $icon])
                <button type="button" x-on:click="set(theme, '{{ $value }}')" :aria-pressed="mode === '{{ $value }}'"
                    :class="mode === '{{ $value }}' ? 'bg-white text-primary-700 shadow-sm dark:bg-gray-700 dark:text-primary-300' : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'"
                    class="flex items-center justify-center gap-1.5 rounded-md py-1.5 text-xs font-medium transition">
                    <x-icon :name="$icon" class="h-3.5 w-3.5" /> {{ $label }}
                </button>
            @endforeach
        </div>
    </div>
</div>
