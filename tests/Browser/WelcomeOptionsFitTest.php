<?php

declare(strict_types=1);

use App\Enums\User\Goal;
use App\Enums\User\Locale;
use App\Enums\User\Persona;
use App\Models\User;
use App\Models\Workspace;

function waitForWelcomeOptionsTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="{$testId}"]';
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    waitForWebFonts($page);
}

test('every welcome option label fits on one line in every language', function (string $route, array $attributes, string $testIdPrefix, string $langPrefix, array $values) {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create($attributes);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user->fresh());

    $page = visit(route($route))->resize(1280, 900);
    waitForWelcomeOptionsTestId($page, "{$testIdPrefix}-{$values[0]}-label");

    $translations = collect($values)
        ->mapWithKeys(fn (string $value): array => [$value => collect(Locale::cases())
            ->mapWithKeys(fn (Locale $locale): array => [$locale->value => __("{$langPrefix}.{$value}", [], $locale->value)])
            ->all()])
        ->all();

    $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

    $wrapped = $page->script(<<<JS
        (() => {
            const translations = {$json};
            const wrapped = [];
            for (const [value, texts] of Object.entries(translations)) {
                const element = document.querySelector('[data-testid="{$testIdPrefix}-' + value + '-label"]');
                const lineHeight = parseFloat(getComputedStyle(element).lineHeight);
                for (const [locale, text] of Object.entries(texts)) {
                    element.textContent = text;
                    if (Math.round(element.getBoundingClientRect().height / lineHeight) > 1) {
                        wrapped.push(locale + ': ' + value + ' — ' + text);
                    }
                }
            }
            return wrapped;
        })()
    JS);

    expect($wrapped)->toBe([]);
})->with([
    'persona' => ['app.welcome.persona', [], 'welcome-persona', 'welcome.personas', array_column(Persona::cases(), 'value')],
    'goals' => ['app.welcome.goals', ['persona' => Persona::Agency->value], 'welcome-goal', 'welcome.goals', array_column(Goal::cases(), 'value')],
]);
