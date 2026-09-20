<?php

test('voice translator page is accessible', function () {
    $response = $this->get(route('translator'));

    $response
        ->assertOk()
        ->assertSee('Voice Translator')
        ->assertSee('Start recording')
        ->assertSee('Stop recording')
        ->assertSee('Recording status');
});
