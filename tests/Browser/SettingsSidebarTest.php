<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForSettingsSidebarTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function settingsSidebarUser(string $role): User
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);

    if ($role === 'admin') {
        return $owner->fresh();
    }

    $member = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace->members()->attach($member->id, membershipPivot($role));
    $member->update(['current_workspace_id' => $workspace->id]);

    return $member->fresh();
}

test('settings pages swap the app sidebar for the settings sidebar', function () {
    $this->actingAs(settingsSidebarUser('admin'));

    $page = visit(route('app.profile.edit'));
    waitForSettingsSidebarTestId($page, 'settings-sidebar');

    $page->assertVisible('@settings-sidebar')
        ->assertVisible('@settings-nav-profile')
        ->assertVisible('@settings-nav-preferences')
        ->assertVisible('@settings-nav-channels')
        ->assertPresent('[data-testid="settings-nav-channels"] svg.tabler-icon-layout-grid')
        ->assertVisible('@settings-nav-signatures')
        ->assertVisible('@settings-nav-webhooks')
        ->assertMissing('@sidebar-new')
        ->assertMissing('@settings-tab-profile')
        ->assertNoJavaScriptErrors();
});

test('members only see the settings they may use', function () {
    $this->actingAs(settingsSidebarUser('member'));

    $page = visit(route('app.profile.edit'));
    waitForSettingsSidebarTestId($page, 'settings-sidebar');

    $page->assertVisible('@settings-nav-profile')
        ->assertVisible('@settings-nav-signatures')
        ->assertVisible('@settings-nav-mcp')
        ->assertMissing('@settings-nav-channels')
        ->assertMissing('@settings-nav-general')
        ->assertMissing('@settings-nav-webhooks')
        ->assertNoJavaScriptErrors();
});

test('a user without a workspace sees only personal settings', function () {
    $this->actingAs(User::factory()->create(['current_workspace_id' => null]));

    $page = visit(route('app.profile.edit'));
    waitForSettingsSidebarTestId($page, 'settings-sidebar');

    $page->assertVisible('@settings-nav-profile')
        ->assertMissing('@settings-nav-general')
        ->assertMissing('@settings-nav-mcp')
        ->assertNoJavaScriptErrors();
});

test('the back link returns to the app', function () {
    $this->actingAs(settingsSidebarUser('admin'));

    $page = visit(route('app.labels.index'));
    waitForSettingsSidebarTestId($page, 'settings-back');
    $page->click('@settings-back');
    waitForSettingsSidebarTestId($page, 'sidebar-new');

    $page->assertVisible('@sidebar-new')->assertNoJavaScriptErrors();
});

test('the back to app link in the settings sidebar highlights on hover', function () {
    $this->actingAs(settingsSidebarUser('admin'));

    $page = visit(route('app.profile.edit'));
    waitForSettingsSidebarTestId($page, 'settings-back');

    $background = fn (): string => $page->script('getComputedStyle(document.querySelector(\'[data-testid="settings-back"]\')).backgroundColor');

    expect($background())->toBe('rgba(0, 0, 0, 0)');

    $page->hover('@settings-back');
    $page->script('new Promise((resolve) => setTimeout(resolve, 300))');

    expect($background())->not->toBe('rgba(0, 0, 0, 0)');

    $page->assertNoJavaScriptErrors();
});

test('the channels item shows how many channels the workspace has connected', function () {
    $user = settingsSidebarUser('admin');
    SocialAccount::factory()->count(3)->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.profile.edit'));
    waitForSettingsSidebarTestId($page, 'settings-nav-channels-count');

    $page->assertSeeIn('@settings-nav-channels-count', '3')
        ->assertNoJavaScriptErrors();
});

test('with the sidebar collapsed the settings nav stays as icons and the footer toggle expands it', function () {
    $this->actingAs(settingsSidebarUser('admin'));

    $state = "document.querySelector('[data-slot=\"sidebar\"][data-state]')?.dataset.state";
    $layout = <<<'JS'
        (() => {
            const profile = document.querySelector('[data-testid="settings-nav-profile"]');
            const box = profile.getBoundingClientRect();
            return {
                state: document.querySelector('[data-slot="sidebar"][data-state]').dataset.state,
                width: Math.round(box.width),
                active: profile.dataset.active,
                icon: profile.querySelector('svg').getBoundingClientRect().width > 0,
                back: document.querySelector('[data-testid="settings-back"]').getBoundingClientRect().width > 0,
                trigger: (document.querySelector('[data-testid="app-sidebar-trigger"]')?.getBoundingClientRect().width ?? 0) > 0,
            };
        })()
    JS;

    $page = visit(route('app.profile.edit'))->resize(1280, 900);
    waitForSettingsSidebarTestId($page, 'sidebar-footer-toggle');
    $page->click('@sidebar-footer-toggle');
    waitForSettingsSidebarScript($page, "{$state} === 'collapsed'");
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    expect($page->script($layout))->toBe([
        'state' => 'collapsed',
        'width' => 32,
        'active' => 'true',
        'icon' => true,
        'back' => true,
        'trigger' => false,
    ]);
    $page->assertMissing('@settings-nav-channels-count');

    $page->script('location.reload()');
    waitForSettingsSidebarTestId($page, 'settings-nav-profile');
    waitForSettingsSidebarScript($page, "{$state} === 'collapsed'");
    $page->assertVisible('@settings-nav-profile');

    $page->click('@sidebar-footer-toggle');
    waitForSettingsSidebarScript($page, "{$state} === 'expanded'");
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    $expanded = $page->script($layout);

    expect($expanded['state'])->toBe('expanded')
        ->and($expanded['width'])->toBeGreaterThan(150)
        ->and($expanded['active'])->toBe('true')
        ->and($expanded['trigger'])->toBeFalse();

    $page->assertSeeIn('@settings-nav-profile', 'Profile')
        ->assertNoJavaScriptErrors();
});

test('the settings sidebar footer shows the user menu, expanded and collapsed', function () {
    $user = settingsSidebarUser('admin');
    $this->actingAs($user);

    $state = "document.querySelector('[data-slot=\"sidebar\"][data-state]')?.dataset.state";

    $page = visit(route('app.profile.edit'))->resize(1280, 900);
    waitForSettingsSidebarTestId($page, 'sidebar-workspace-menu');

    $page->assertSeeIn('@sidebar-workspace-menu', $user->name)
        ->click('@sidebar-workspace-menu');

    waitForSettingsSidebarTestId($page, 'logout-button');
    $page->assertVisible('@logout-button');
    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");

    $page->click('@sidebar-footer-toggle');
    waitForSettingsSidebarScript($page, "{$state} === 'collapsed'");
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    $page->assertVisible('@sidebar-workspace-menu')
        ->assertVisible('[data-testid="sidebar-workspace-menu"] [data-slot="avatar"]')
        ->assertNoJavaScriptErrors();
});

function waitForSettingsSidebarScript(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if ({$condition}) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}
