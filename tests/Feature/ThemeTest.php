<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Reports\ReportCatalog;
use App\Support\Themes;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'staff', ...$attributes]);
    }

    /**
     * The opening <html ...> tag of a response — where the theme attributes live
     * (the inline script mentions the same attribute names, so page-wide checks
     * would match it too).
     */
    private function htmlTag(TestResponse $response): string
    {
        preg_match('/<html[^>]*>/', $response->getContent(), $match);

        return $match[0] ?? '';
    }

    public function test_the_theme_list_is_well_formed(): void
    {
        $config = json_decode(file_get_contents(resource_path('themes.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertContains($config['default'], Themes::keys());
        $this->assertGreaterThanOrEqual(6, count(Themes::keys()));
        $this->assertEqualsCanonicalizing(['system', 'light', 'dark'], Themes::appearances());

        $palettes = ['slate', 'gray', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];

        foreach (Themes::all() as $key => $theme) {
            $this->assertMatchesRegularExpression('/^[a-z]+$/', $key);
            $this->assertNotSame('', $theme['label'], $key);
            $this->assertContains($theme['hue'], $palettes, "{$key} names a Tailwind palette that exists");
            $this->assertGreaterThanOrEqual(0, $theme['grayTint']);
            $this->assertLessThanOrEqual(0.5, $theme['grayTint'], "{$key} keeps its neutrals neutral");
            $this->assertLessThanOrEqual(0.5, $theme['chromeTint']);
        }
    }

    public function test_new_accounts_start_on_the_default_look(): void
    {
        $user = $this->user()->fresh();

        $this->assertSame(Themes::default(), $user->theme);
        $this->assertSame('system', $user->appearance);
    }

    public function test_a_user_can_save_their_theme_and_appearance(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('appearance.update'), ['theme' => 'emerald', 'appearance' => 'dark'])
            ->assertOk()
            ->assertJson(['theme' => 'emerald', 'appearance' => 'dark']);

        $this->assertSame(['emerald', 'dark'], [$user->fresh()->theme, $user->fresh()->appearance]);
    }

    public function test_every_offered_theme_and_appearance_can_be_saved(): void
    {
        $user = $this->user();

        foreach (Themes::keys() as $theme) {
            foreach (Themes::appearances() as $appearance) {
                $this->actingAs($user)->postJson(route('appearance.update'), ['theme' => $theme, 'appearance' => $appearance])->assertOk();
            }
        }

        $this->assertNotNull($user->fresh()->theme);
    }

    public function test_made_up_values_are_rejected_and_change_nothing(): void
    {
        $user = $this->user(['theme' => 'rose', 'appearance' => 'light']);

        $this->actingAs($user)->postJson(route('appearance.update'), ['theme' => 'neon', 'appearance' => 'dark'])
            ->assertStatus(422)->assertJsonValidationErrors('theme');
        $this->actingAs($user)->postJson(route('appearance.update'), ['theme' => 'teal', 'appearance' => 'auto'])
            ->assertStatus(422)->assertJsonValidationErrors('appearance');
        $this->actingAs($user)->postJson(route('appearance.update'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['theme', 'appearance']);

        $this->assertSame(['rose', 'light'], [$user->fresh()->theme, $user->fresh()->appearance]);
    }

    public function test_guests_cannot_save_a_theme(): void
    {
        $this->postJson(route('appearance.update'), ['theme' => 'teal', 'appearance' => 'dark'])->assertUnauthorized();
    }

    public function test_one_users_choice_never_touches_another(): void
    {
        $mine = $this->user();
        $theirs = $this->user(['theme' => 'violet', 'appearance' => 'dark']);

        $this->actingAs($mine)->postJson(route('appearance.update'), ['theme' => 'sunset', 'appearance' => 'light'])->assertOk();

        $this->assertSame(['violet', 'dark'], [$theirs->fresh()->theme, $theirs->fresh()->appearance]);
    }

    public function test_signed_in_pages_render_with_the_users_own_theme(): void
    {
        $user = $this->user(['theme' => 'rose', 'appearance' => 'dark']);

        $tag = $this->htmlTag($this->actingAs($user)->get(route('dashboard'))->assertOk());

        $this->assertStringContainsString('data-theme="rose"', $tag);
        $this->assertStringContainsString('data-appearance="dark"', $tag);
        $this->assertStringContainsString('class="dark"', $tag);
        $this->assertStringNotContainsString('data-guest', $tag);
    }

    public function test_the_system_setting_leaves_dark_to_the_devices_own_preference(): void
    {
        $user = $this->user(['theme' => 'ocean', 'appearance' => 'system']);

        $tag = $this->htmlTag($this->actingAs($user)->get(route('dashboard'))->assertOk());

        $this->assertStringContainsString('data-theme="ocean"', $tag);
        $this->assertStringContainsString('data-appearance="system"', $tag);
        $this->assertStringNotContainsString('class="dark"', $tag);
    }

    public function test_a_saved_theme_that_no_longer_exists_falls_back_to_the_default(): void
    {
        $user = $this->user();
        $user->forceFill(['theme' => 'retired-theme', 'appearance' => 'sepia'])->save();

        $tag = $this->htmlTag($this->actingAs($user)->get(route('dashboard'))->assertOk());

        $this->assertStringContainsString('data-theme="'.Themes::default().'"', $tag);
        $this->assertStringContainsString('data-appearance="system"', $tag);
    }

    public function test_guest_pages_use_the_default_look_and_let_the_browser_remember_a_choice(): void
    {
        $response = $this->get('/login')->assertOk();
        $tag = $this->htmlTag($response);

        $this->assertStringContainsString('data-guest', $tag);
        $this->assertStringContainsString('data-theme="'.Themes::default().'"', $tag);
        $response->assertSee('window.appearance', false)
            // the login page has its own picker, since there's no account to save to
            ->assertSee('Color theme');
    }

    public function test_the_picker_offers_every_theme_in_the_sidebar_and_on_the_profile_page(): void
    {
        $user = $this->user();

        $dashboard = $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Color theme')->assertSee('Theme &amp; appearance', false);
        $profile = $this->actingAs($user)->get(route('profile'))->assertOk()->assertSee('Appearance')->assertSee('Color theme');

        foreach (Themes::all() as $key => $theme) {
            $dashboard->assertSee($theme['label']);
            $dashboard->assertSee("data-theme=\"{$key}\"", false);
            $profile->assertSee($theme['label']);
        }

        foreach (['Light', 'Dark', 'System'] as $mode) {
            $profile->assertSee($mode);
        }
    }

    /**
     * The whole point of themes: no view may hard-code an accent colour, or
     * that part of the site would stay indigo/blue/etc. whatever theme is
     * picked. Accent must be `primary-*`; neutrals `gray-*`/`slate-*`.
     * Status colours (green, red, yellow, amber) are meant to stay fixed.
     * Report views under views/reports are printed/exported documents with
     * their own fixed colours, so they're exempt.
     */
    public function test_no_view_hard_codes_an_accent_colour(): void
    {
        $pattern = '/(?<![a-z-])(?:bg|text|border|ring|from|to|via|divide|fill|stroke|outline|accent|shadow|placeholder|decoration)-'
            .'(?:indigo|blue|sky|teal|purple|violet|rose|pink|cyan|emerald|lime|fuchsia|orange)-\d/';

        $offenders = [];
        $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS));

        foreach ($views as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, '.blade.php') || str_contains($path, '/views/reports/')) {
                continue;
            }

            if (preg_match_all($pattern, file_get_contents($path), $matches)) {
                $offenders[] = basename($path).': '.implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame([], $offenders, "Hard-coded accent colours found (use primary-*):\n".implode("\n", $offenders));
    }

    public function test_the_report_catalog_no_longer_carries_per_report_colours(): void
    {
        foreach (ReportCatalog::TYPES as $type => $config) {
            $this->assertArrayNotHasKey('color', $config, $type);
        }
    }
}
