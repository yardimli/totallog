<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_pages_share_a_layout_and_canonical_urls(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([
            'profile.edit' => '/settings/profile',
            'settings.edit' => '/settings',
            'settings.screensaver' => '/settings/screensaver',
            'sensors.index' => '/settings/sensors',
            'api-usage.index' => '/settings/api-usage',
            'admin.users' => '/settings/admin/users',
        ] as $name => $path) {
            $this->assertSame($path, route($name, [], false));
            $this->get($path)->assertOk()->assertSee('id="settings-shell"', false)
                ->assertSee('aria-label="Settings"', false);
        }
        foreach (['profile', 'sensors', 'api-usage', 'admin/users'] as $path) {
            $this->get('/'.$path)->assertRedirect('/settings/'.$path);
        }
    }

    public function test_admin_and_guest_access_remain_restricted(): void
    {
        $this->get('/settings/sensors')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/settings/admin/users')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_guest' => true]));
        $this->post(route('sensors.github.sync'))->assertForbidden();
    }
}
