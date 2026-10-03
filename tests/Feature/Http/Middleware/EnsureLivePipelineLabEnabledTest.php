<?php

it('hides live pipeline lab routes when the lab is disabled', function (): void {
    config([
        'live_pipeline.lab_enabled' => false,
    ]);

    $this->get(
        route('live'),
    )->assertNotFound();

    $this->get(
        route('live.history'),
    )->assertNotFound();

    $this->getJson(
        route('live.history.runs.index'),
    )->assertNotFound();

    $this->postJson(
        route('live.runs.store'),
        [],
    )->assertNotFound();

    $this->postJson(
        route('transcribe'),
        [],
    )->assertNotFound();

    $this->get(
        route('benchmark.recorder'),
    )->assertNotFound();

    $this->postJson(
        route('benchmark.recorder.audio.store'),
        [],
    )->assertNotFound();
});

it('allows live pipeline lab routes when the lab is enabled', function (): void {
    config([
        'live_pipeline.lab_enabled' => true,
    ]);

    $this->get(
        route('live'),
    )->assertOk();

    $this->get(
        route('live.history'),
    )->assertOk();
});

it('does not hide the public translator when the lab is disabled', function (): void {
    config([
        'live_pipeline.lab_enabled' => false,
    ]);

    $this->get(
        route('translator'),
    )->assertOk();
});
