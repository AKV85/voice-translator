<?php

test('public voice translator demo page is accessible', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Voice Translator')
        ->assertSee('Public demo')
        ->assertSee('Russian → English')
        ->assertSee('English → Russian')
        ->assertSee('Status')
        ->assertSee('Start speaking')
        ->assertSee('Stop')
        ->assertSee('Maximum recording length:')
        ->assertSee('Hourly attempts remaining:')
        ->assertSee('Daily attempts remaining:');
});
