<?php

declare(strict_types=1);

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForLegalTestId(mixed $page, string $testId): void
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

test('the login screen shows terms and privacy links to a logged out visitor', function () {
    $page = visit(route('login'));

    waitForLegalTestId($page, 'legal-terms');

    $page->assertVisible('@legal-terms')
        ->assertVisible('@legal-privacy')
        ->assertNoJavaScriptErrors();
});

test('the register screen shows terms and privacy links to a logged out visitor', function () {
    config(['trypost.self_hosted' => false]);

    $page = visit(route('register'));

    waitForLegalTestId($page, 'legal-terms');

    $page->assertVisible('@legal-terms')
        ->assertVisible('@legal-privacy')
        ->assertNoJavaScriptErrors();
});
