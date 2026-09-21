<?php

use Illuminate\Support\Facades\File;

it('has a canonical audio fixture for every benchmark phrase', function () {
    $datasetPath = base_path('docs/benchmarks/speech/phrases.json');

    $this->assertFileExists($datasetPath);

    $dataset = json_decode(
        File::get($datasetPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $phrases = $dataset['phrases'] ?? [];

    expect($phrases)->toHaveCount(30);

    $ids = collect($phrases)->pluck('id');
    $audioPaths = collect($phrases)->pluck('audio');

    expect($ids->unique())->toHaveCount($ids->count());
    expect($audioPaths->unique())->toHaveCount($audioPaths->count());

    foreach ($phrases as $phrase) {
        expect($phrase)->toHaveKeys([
            'id',
            'language',
            'category',
            'expected',
            'audio',
        ]);

        $audioPath = base_path(
            'docs/benchmarks/speech/'.$phrase['audio']
        );

        $this->assertFileExists(
            $audioPath,
            "Missing benchmark audio fixture for {$phrase['id']}.",
        );

        $this->assertGreaterThan(
            0,
            File::size($audioPath),
            "Benchmark audio fixture {$phrase['id']} is empty.",
        );
    }
});

it('has benchmark recording metadata', function () {
    $metadataPath = base_path(
        'docs/benchmarks/speech/audio/metadata.json'
    );

    $this->assertFileExists($metadataPath);

    $metadata = json_decode(
        File::get($metadataPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($metadata)
        ->toHaveKey('dataset_version', 1)
        ->toHaveKey('fixture_count', 30)
        ->toHaveKey('source_format', 'audio/webm')
        ->toHaveKey('codec', 'opus')
        ->toHaveKey('recording_method');
});
