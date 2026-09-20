<?php

test('voice translator page is accessible', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Voice Translator')
        ->assertSee('Source language')
        ->assertSee('English')
        ->assertSee('Russian')
        ->assertSee('Status')
        ->assertSee('Start recording')
        ->assertSee('Stop recording')
        ->assertSee('Recorded audio')
        ->assertSee('Recognized text');
});
