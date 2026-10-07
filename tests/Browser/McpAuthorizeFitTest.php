<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

function waitForMcpAuthorizeTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector('[data-testid="{$testId}"]');
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('the mcp authorize buttons and workspace picker stay on one line in every language', function (int $width) {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $clientId = mcpOauthClient('Claude');
    DB::table('oauth_clients')->where('id', $clientId)->update(['redirect_uris' => json_encode(['https://client.example/callback'])]);

    $this->actingAs($user->fresh());

    $page = visit(route('passport.authorizations.authorize', oauthAuthorizeQuery($clientId)))->resize($width, 900);
    waitForMcpAuthorizeTestId($page, 'mcp-authorize-approve');

    $slots = [
        'mcp-authorize-approve' => 'mcp.authorize.approve',
        'mcp-authorize-cancel' => 'mcp.authorize.cancel',
        'mcp-authorize-workspace' => 'mcp.authorize.select_workspace',
    ];

    $translations = collect($slots)->map(fn (string $key): array => collect(Locale::cases())
        ->mapWithKeys(fn (Locale $locale): array => [$locale->value => __($key, [], $locale->value)])
        ->all())->all();

    $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

    $wrapped = $page->script(<<<JS
        (() => {
            const slots = {$json};
            const failures = [];

            for (const [testId, texts] of Object.entries(slots)) {
                const element = document.querySelector('[data-testid="' + testId + '"]');
                const label = element.querySelector('span') ?? element;
                const original = label.innerHTML;
                const height = element.getBoundingClientRect().height;

                for (const [locale, text] of Object.entries(texts)) {
                    label.textContent = text;
                    const fits = element.scrollWidth <= element.clientWidth + 1
                        && element.getBoundingClientRect().height <= height + 1
                        && document.documentElement.scrollWidth <= window.innerWidth;
                    if (!fits) failures.push(testId + ' ' + locale + ': ' + text);
                }

                label.innerHTML = original;
            }

            return failures;
        })()
    JS);

    expect($wrapped)->toBe([]);
    $page->assertNoJavaScriptErrors();
})->with(['phone' => 390, 'desktop' => 1280]);
