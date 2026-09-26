<?php

use App\Contracts\AudioDurationProbe;
use App\Contracts\TranslationProvider;
use App\DTO\TranslationResult;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use App\Exceptions\SpeechSynthesisException;
use App\Exceptions\TranslationException;
use App\Services\Benchmark\PipelineTranslationBenchmarkRunner;
use Tests\Fakes\FakeStreamingSpeechToTextProvider;
use Tests\Fakes\FakeStreamingTextToSpeechProvider;
use Tests\Fakes\FakeTranslationProvider;

function pipelineBenchmarkAudioDurationProbe(): AudioDurationProbe
{
    return new class implements AudioDurationProbe
    {
        public function durationMs(
            string $audioPath,
        ): float {
            return 4.0;
        }
    };
}

function pipelineBenchmarkPcmFrame(
    int $sampleValue,
): string {
    $value = $sampleValue;

    if ($value < 0) {
        $value += 0x10000;
    }

    $sample = pack(
        'v',
        $value,
    );

    return str_repeat(
        $sample,
        240,
    );
}

/**
 * @return array<string, mixed>
 */
function runPipelineBenchmark(
    FakeStreamingSpeechToTextProvider $speechToText,
    TranslationProvider $translator,
    FakeStreamingTextToSpeechProvider $textToSpeech,
): array {
    $runner = new PipelineTranslationBenchmarkRunner(
        audioDurationProbe: pipelineBenchmarkAudioDurationProbe(),
    );

    return $runner->run(
        speechToTextProvider: $speechToText,

        translationProvider: $translator,

        textToSpeechProvider: $textToSpeech,

        profileName: 'test-pipeline',

        speechProviderName: 'fake-stt',

        speechModelName: 'fake-stt-model',

        translationProviderName: 'fake-translation',

        translationModelName: 'fake-translation-model',

        textToSpeechProviderName: 'fake-tts',

        textToSpeechModelName: 'fake-tts-model',

        speechDatasetPath: base_path(
            'docs/benchmarks/speech/phrases.json',
        ),

        translationDatasetPath: base_path(
            'docs/benchmarks/translation/phrases.json',
        ),

        chunkDurationMs: 1,

        runsPerFixture: 1,

        languagePair: 'en-ru',

        phraseId: 'en-001',
    );
}

test('it benchmarks the complete streaming translation pipeline', function () {
    $speechToText =
        new FakeStreamingSpeechToTextProvider(
            transcription: 'I am ready to start the trip.',
        );

    $translator =
        new FakeTranslationProvider(
            translation: 'Я готов начать поездку.',
        );

    $pcm =
        pipelineBenchmarkPcmFrame(0)
        .pipelineBenchmarkPcmFrame(1000);

    $textToSpeech =
        new FakeStreamingTextToSpeechProvider(
            audio: $pcm,
            chunkSizeBytes: 317,
        );

    $benchmark =
        runPipelineBenchmark(
            speechToText: $speechToText,

            translator: $translator,

            textToSpeech: $textToSpeech,
        );

    expect($benchmark['benchmark_type'])
        ->toBe('pipeline_translation')
        ->and($benchmark['fixture_count'])
        ->toBe(1)
        ->and($benchmark['run_count'])
        ->toBe(1);

    $fixture =
        $benchmark['results'][0];

    $run =
        $fixture['runs'][0];

    expect($fixture['phrase_id'])
        ->toBe('en-001')
        ->and($fixture['translation_fixture_id'])
        ->toBe('en-ru-001')
        ->and($fixture['language_pair'])
        ->toBe('en-ru');

    expect($run['success'])
        ->toBeTrue()
        ->and($run['stt_success'])
        ->toBeTrue()
        ->and($run['translation_success'])
        ->toBeTrue()
        ->and($run['tts_success'])
        ->toBeTrue()
        ->and($run['audio_success'])
        ->toBeTrue()
        ->and($run['failed_stage'])
        ->toBeNull();

    expect($run['source_transcript'])
        ->toBe(
            'I am ready to start the trip.',
        )
        ->and($run['translated_text'])
        ->toBe(
            'Я готов начать поездку.',
        );

    expect($speechToText->receivedChunks)
        ->not->toBeEmpty()
        ->and($speechToText->receivedLanguage)
        ->toBe(Language::English)
        ->and($speechToText->receivedMimeType)
        ->toBeString();

    expect($translator->receivedText)
        ->toBe(
            'I am ready to start the trip.',
        )
        ->and(
            $translator
                ->receivedSourceLanguage
        )
        ->toBe(Language::English)
        ->and(
            $translator
                ->receivedTargetLanguage
        )
        ->toBe(Language::Russian);

    expect($textToSpeech->receivedText)
        ->toBe(
            'Я готов начать поездку.',
        )
        ->and(
            $textToSpeech
                ->receivedLanguage
        )
        ->toBe(Language::Russian);

    expect($run['input_finished_ms'])
        ->toBeFloat()
        ->and($run['stt_completed_ms'])
        ->toBeFloat()
        ->and($run['translation_latency_ms'])
        ->toBeFloat()
        ->and(
            $run[
                'first_tts_audio_chunk_available_ms'
            ]
        )
        ->toBeFloat()
        ->and(
            $run[
                'first_audible_audio_available_ms'
            ]
        )
        ->toBeFloat()
        ->and($run['tts_duration_ms'])
        ->toBeFloat();

    expect($run['leading_silence_ms'])
        ->toBe(10.0)
        ->and($run['translated_audio_bytes'])
        ->toBe(960)
        ->and($run['translated_audio_duration_ms'])
        ->toBe(20.0);

    expect(
        $run[
            'raw_playback_first_audible_audio_ms'
        ]
    )->toBeGreaterThan(
        $run[
            'first_tts_audio_chunk_available_ms'
        ],
    );

    expect(
        $run[
            'first_audible_audio_available_ms'
        ]
    )->toBeGreaterThanOrEqual(
        $run[
            'first_tts_audio_chunk_available_ms'
        ],
    );
});

test('it records speech to text failure stage', function () {
    $speechToText =
        new FakeStreamingSpeechToTextProvider(
            exception: new SpeechRecognitionException(
                'Fake STT failure.',
            ),
        );

    $translator =
        new FakeTranslationProvider;

    $textToSpeech =
        new FakeStreamingTextToSpeechProvider(
            audio: pipelineBenchmarkPcmFrame(
                1000,
            ),
        );

    $benchmark =
        runPipelineBenchmark(
            speechToText: $speechToText,

            translator: $translator,

            textToSpeech: $textToSpeech,
        );

    $run =
        $benchmark['results'][0]['runs'][0];

    expect($run['success'])
        ->toBeFalse()
        ->and($run['stt_success'])
        ->toBeFalse()
        ->and($run['translation_success'])
        ->toBeFalse()
        ->and($run['tts_success'])
        ->toBeFalse()
        ->and($run['audio_success'])
        ->toBeFalse()
        ->and($run['failed_stage'])
        ->toBe('stt')
        ->and($run['error'])
        ->toBe(
            SpeechRecognitionException::class,
        )
        ->and($run['error_message'])
        ->toBe('Fake STT failure.');
});

test('it records translation failure stage', function () {
    $speechToText =
        new FakeStreamingSpeechToTextProvider(
            transcription: 'I am ready to start the trip.',
        );

    $translator =
        new class implements TranslationProvider
        {
            public function translate(
                string $text,
                Language $sourceLanguage,
                Language $targetLanguage,
            ): TranslationResult {
                throw new TranslationException(
                    'Fake translation failure.',
                );
            }
        };

    $textToSpeech =
        new FakeStreamingTextToSpeechProvider(
            audio: pipelineBenchmarkPcmFrame(
                1000,
            ),
        );

    $benchmark =
        runPipelineBenchmark(
            speechToText: $speechToText,

            translator: $translator,

            textToSpeech: $textToSpeech,
        );

    $run =
        $benchmark['results'][0]['runs'][0];

    expect($run['success'])
        ->toBeFalse()
        ->and($run['stt_success'])
        ->toBeTrue()
        ->and($run['translation_success'])
        ->toBeFalse()
        ->and($run['tts_success'])
        ->toBeFalse()
        ->and($run['audio_success'])
        ->toBeFalse()
        ->and($run['failed_stage'])
        ->toBe('translation')
        ->and($run['error'])
        ->toBe(
            TranslationException::class,
        )
        ->and($run['error_message'])
        ->toBe(
            'Fake translation failure.',
        );
});

test('it records text to speech failure stage', function () {
    $speechToText =
        new FakeStreamingSpeechToTextProvider(
            transcription: 'I am ready to start the trip.',
        );

    $translator =
        new FakeTranslationProvider(
            translation: 'Я готов начать поездку.',
        );

    $textToSpeech =
        new FakeStreamingTextToSpeechProvider(
            audio: '',
            exception: new SpeechSynthesisException(
                'Fake TTS failure.',
            ),
        );

    $benchmark =
        runPipelineBenchmark(
            speechToText: $speechToText,

            translator: $translator,

            textToSpeech: $textToSpeech,
        );

    $run =
        $benchmark['results'][0]['runs'][0];

    expect($run['success'])
        ->toBeFalse()
        ->and($run['stt_success'])
        ->toBeTrue()
        ->and($run['translation_success'])
        ->toBeTrue()
        ->and($run['tts_success'])
        ->toBeFalse()
        ->and($run['audio_success'])
        ->toBeFalse()
        ->and($run['failed_stage'])
        ->toBe('tts')
        ->and($run['error'])
        ->toBe(
            SpeechSynthesisException::class,
        )
        ->and($run['error_message'])
        ->toBe('Fake TTS failure.');
});
