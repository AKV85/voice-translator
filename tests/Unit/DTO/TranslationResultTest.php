<?php

use App\DTO\TranslationResult;

test('it stores translated text', function () {
    $result = new TranslationResult('Мне нужен грузовик');

    expect($result->text)->toBe('Мне нужен грузовик');
});
