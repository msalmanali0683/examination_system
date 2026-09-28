<?php

namespace App\Support;

use App\Models\User;

/**
 * The colour themes the site can be switched between. The list itself
 * lives in resources/themes.json — shared with tailwind.config.js, which
 * turns each entry into the CSS variables the whole UI reads — so a theme
 * exists in exactly one place. This class is the PHP view of that file:
 * what the picker offers, and what a saved choice may be.
 */
class Themes
{
    /**
     * @return array{default: string, appearances: string[], themes: array<string, array{label: string, hue: string}>}
     */
    private static function config(): array
    {
        static $config = null;

        return $config ??= json_decode(file_get_contents(resource_path('themes.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array{label: string, hue: string}> theme key => details, in display order
     */
    public static function all(): array
    {
        return self::config()['themes'];
    }

    /**
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function default(): string
    {
        return self::config()['default'];
    }

    /**
     * Light / dark / follow-the-device.
     *
     * @return string[]
     */
    public static function appearances(): array
    {
        return self::config()['appearances'];
    }

    public static function defaultAppearance(): string
    {
        return 'system';
    }

    /**
     * The user's saved theme, or the default for guests and for anything
     * stored that isn't (or is no longer) a real theme.
     */
    public static function themeFor(?User $user): string
    {
        $theme = $user?->theme;

        return in_array($theme, self::keys(), true) ? $theme : self::default();
    }

    public static function appearanceFor(?User $user): string
    {
        $appearance = $user?->appearance;

        return in_array($appearance, self::appearances(), true) ? $appearance : self::defaultAppearance();
    }
}
