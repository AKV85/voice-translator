<?php

use App\Enums\Language;

test('english language has expected value', function () {
    expect(Language::English->value)->toBe('en');
});

test('russian language has expected value', function () {
    expect(Language::Russian->value)->toBe('ru');
});
