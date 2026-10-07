<?php

declare(strict_types=1);

test('the login screen shows the legal sentence to a logged out visitor', function () {
    visit(route('login'))
        ->assertVisible('@legal-links')
        ->assertSeeLink('Terms of Service')
        ->assertSeeLink('Privacy Policy')
        ->assertNoJavaScriptErrors();
});

test('the register screen shows the legal sentence to a logged out visitor', function () {
    config(['trypost.self_hosted' => false]);

    visit(route('register'))
        ->assertVisible('@legal-links')
        ->assertSeeLink('Terms of Service')
        ->assertSeeLink('Privacy Policy')
        ->assertNoJavaScriptErrors();
});

test('the register screen shows the email form open next to the social buttons', function () {
    config(['trypost.self_hosted' => false, 'trypost.google_auth_enabled' => true]);

    $page = visit(route('register'));
    $page->script('new Promise((resolve) => { const check = () => document.querySelector("[data-testid=register-name]") ? resolve(true) : requestAnimationFrame(check); check(); })');

    $page->assertVisible('@register-name')
        ->assertVisible('@register-email')
        ->assertMissing('@register-email-toggle')
        ->assertNoJavaScriptErrors();
});
