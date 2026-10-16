<?php

use App\Services\Conversation\ConversationStore;

beforeEach(function (): void {
    config()->set('conversation.enabled', true);
    config()->set('cache.default', 'array');
});

it('creates a conversation and authenticates its host', function (): void {
    $response = $this->postJson(route('conversation.create'));

    $response->assertCreated()
        ->assertJsonStructure(['code', 'token']);

    $code = $response->json('code');
    $token = $response->json('token');

    expect($code)->toBeString()
        ->toHaveLength(8)
        ->and($token)->toBeString()
        ->toHaveLength(64);

    $participant = app(ConversationStore::class)
        ->authenticate($code, $token);

    expect($participant)->toMatchArray([
        'code' => $code,
        'role' => 'host',
        'language' => 'ru',
    ]);
});

it('allows one guest to join a conversation', function (): void {
    $host = app(ConversationStore::class)->create();

    $response = $this->postJson(
        route('conversation.join'),
        ['code' => strtolower($host['code'])],
    );

    $response->assertOk()
        ->assertJsonStructure(['code', 'token']);

    expect(
        app(ConversationStore::class)->authenticate(
            $response->json('code'),
            $response->json('token'),
        ),
    )->toMatchArray([
        'code' => $host['code'],
        'role' => 'guest',
        'language' => 'en',
    ]);
});

it('rejects a third participant', function (): void {
    $store = app(ConversationStore::class);

    $host = $store->create();
    $store->join($host['code']);

    $this->postJson(
        route('conversation.join'),
        ['code' => $host['code']],
    )
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Conversation is full.');
});

it('validates conversation codes', function (): void {
    $this->postJson(
        route('conversation.join'),
        ['code' => 'invalid!'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->postJson(
        route('conversation.join'),
        ['code' => 'ABCDEFGH'],
    )
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            'Conversation not found or expired.',
        );
});
