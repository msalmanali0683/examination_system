{{--
    Applies the colour theme and light/dark choice before the page paints, and
    exposes window.appearance for the picker.

    Signed-in users: the server-rendered <html data-theme data-appearance> is
    the truth (it's what they saved), and is mirrored into localStorage so the
    login page remembers it after logout. Guests: whatever they last picked on
    this device, falling back to the defaults on <html>.
--}}
<script>
    (function () {
        var html = document.documentElement;
        var themes = @json(\App\Support\Themes::keys());
        var modes = @json(\App\Support\Themes::appearances());
        var fallbackTheme = @json(\App\Support\Themes::default());
        var fallbackMode = @json(\App\Support\Themes::defaultAppearance());
        var guest = html.hasAttribute('data-guest');
        var dark = window.matchMedia('(prefers-color-scheme: dark)');

        var read = function (key) {
            try { return localStorage.getItem(key); } catch (e) { return null; }
        };
        var write = function (key, value) {
            try { localStorage.setItem(key, value); } catch (e) { /* private mode etc. */ }
        };

        var api = window.appearance = {
            apply: function (theme, mode) {
                if (themes.indexOf(theme) === -1) { theme = fallbackTheme; }
                if (modes.indexOf(mode) === -1) { mode = fallbackMode; }

                html.dataset.theme = theme;
                html.dataset.appearance = mode;
                html.classList.toggle('dark', mode === 'dark' || (mode === 'system' && dark.matches));
                write('theme', theme);
                write('appearance', mode);
                window.dispatchEvent(new CustomEvent('appearance-changed', { detail: { theme: theme, mode: mode } }));
            },

            // Applies straight away, then remembers it on the account (signed-in users only).
            save: function (theme, mode) {
                api.apply(theme, mode);

                if (guest) { return; }

                var token = document.querySelector('meta[name="csrf-token"]');

                fetch(@json(route('appearance.update')), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token ? token.content : '',
                    },
                    body: JSON.stringify({ theme: theme, appearance: mode }),
                });
            },
        };

        api.apply(
            guest ? (read('theme') || html.dataset.theme) : html.dataset.theme,
            guest ? (read('appearance') || html.dataset.appearance) : html.dataset.appearance
        );

        // "System" follows the device live.
        dark.addEventListener('change', function () {
            if (html.dataset.appearance === 'system') { api.apply(html.dataset.theme, 'system'); }
        });

        // wire:navigate swaps pages without reloading; keep the choice (and the dark class) across it.
        document.addEventListener('livewire:navigated', function () {
            api.apply(
                guest ? (read('theme') || html.dataset.theme) : html.dataset.theme,
                guest ? (read('appearance') || html.dataset.appearance) : html.dataset.appearance
            );
        });
    })();
</script>
