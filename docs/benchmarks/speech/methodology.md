# Speech Provider Benchmark Methodology

## Purpose

This document defines the methodology used to compare speech recognition
and realtime speech translation technologies for the Voice Translator project.

The benchmark must be reproducible and must use the same source audio wherever
providers support comparable input.

The purpose of the benchmark is not to determine which provider is universally
"best".

The purpose is to determine which technology is the best fit for the project's
primary use case:

> Near real-time Russian ↔ English voice communication with low latency,
> acceptable recognition accuracy and predictable operating cost.

---

## Research Questions

The benchmark should answer two separate questions.

### 1. Speech recognition

Which speech recognition provider gives the best combination of:

- Russian transcription accuracy;
- English transcription accuracy;
- low streaming latency;
- reliable finalization of speech;
- conversational turn detection where supported;
- acceptable usage cost?

Initial candidates:

- Google Chirp 3
- Deepgram Nova-3
- Deepgram Flux

### 2. End-to-end realtime translation

Which architecture gives the best user experience for realtime
Russian ↔ English voice translation?

Potential approaches include:

```text
Speech
  ↓
Speech-to-Text
  ↓
Translation
  ↓
Text-to-Speech
```

and direct realtime speech translation:

```text
Speech
  ↓
Realtime Translation Model
  ↓
Translated Speech
```

OpenAI Realtime Translate will therefore be evaluated as an
end-to-end translation candidate and must not be compared to pure
speech-to-text providers using a single combined score.

---

## Benchmark Principles

### Same source audio

Each benchmark phrase must be recorded once.

The same source recording must be reused for all compatible providers.

A phrase must not be spoken separately for each provider because differences in:

- pronunciation;
- speaking speed;
- pauses;
- microphone distance;
- intonation;
- background noise;

would make the comparison unreliable.

### No provider-specific improvement of source audio

The original benchmark recording must be preserved unchanged.

If a provider requires a different audio format, sample rate or encoding,
a derived input file may be generated.

The transformation must be documented.

Example:

```text
audio/ru-001.wav
    ↓
provider-input/google/ru-001.wav
provider-input/deepgram/ru-001.wav
```

Provider-specific preprocessing must never modify the linguistic content
of the recording.

### Same expected transcript

Every speech fixture must have one canonical expected transcript.

The expected transcript must be written before provider results are reviewed.

It must not be changed later to make a provider result appear more accurate.

---

## Benchmark Dataset

The benchmark will contain Russian and English speech.

Initial target:

```text
15 Russian phrases
15 English phrases
```

The exact number may change before the dataset is finalized.

Each phrase must have:

- unique ID;
- language;
- category;
- expected transcript;
- associated source audio file.

Example:

```json
{
    "id": "ru-001",
    "language": "ru",
    "category": "ambiguity",
    "expected": "Я свободна или не свободна?",
    "audio": "audio/ru-001.wav"
}
```

---

## Phrase Categories

The dataset should contain a mixture of easy and difficult speech.

Categories should include:

### Everyday speech

Simple conversational phrases.

### Context-dependent speech

Phrases where surrounding words help resolve ambiguity.

Example:

```text
Кот Персик рыжий хулиган.
```

versus:

```text
У меня есть кот Персик, он рыжий хулиган.
```

### Negation

Examples where losing the word "not" changes the meaning.

### Numbers

Include:

- integers;
- similar numbers;
- times;
- distances;
- order numbers.

Example:

```text
Ты сказал пятнадцать или пятьдесят?
```

### Names

Personal names, place names and other proper nouns.

### Logistics terminology

Include terminology relevant to the intended product domain, such as:

- truck;
- trailer;
- warehouse;
- loading;
- unloading;
- driver;
- delivery;
- distance;
- parking;
- dispatcher.

### Longer sentences

Sentences requiring the recognizer to maintain context across multiple clauses.

### Pauses

Natural pauses inside a phrase.

### Similar-sounding words

Known cases where acoustic similarity may cause recognition errors.

---

## Recording Methodology

All benchmark source recordings should be created under consistent conditions.

The following metadata must be documented:

- recording device;
- microphone;
- browser or recording application;
- audio format;
- codec;
- sample rate where available;
- channel count where available;
- approximate microphone distance;
- recording environment.

The recordings should represent realistic usage rather than studio-quality audio.

The primary goal is to approximate how the Voice Translator will actually be used.

---

## File Naming

Audio fixtures should use predictable IDs.

```text
ru-001.wav
ru-002.wav
...
en-001.wav
en-002.wav
...
```

The phrase ID and audio filename must correspond.

---

## Speech Recognition Accuracy

### Expected transcript

Each fixture has a manually defined expected transcript.

### Actual transcript

Each provider result must preserve the original text returned by the provider.

Provider output must not be manually corrected before metrics are calculated.

---

## Text Normalization

Word Error Rate should not primarily measure punctuation or capitalization.

Before calculating WER, both expected and actual transcripts should be normalized.

Initial normalization rules:

- convert text to lowercase;
- remove surrounding whitespace;
- collapse repeated whitespace;
- ignore punctuation differences.

The original unnormalized transcript must still be stored.

Normalization must not:

- replace words;
- correct spelling;
- convert one number into another;
- remove negation;
- rewrite provider output.

Example:

```text
Expected:
У меня есть кот Персик, он рыжий хулиган.

Provider:
у меня есть кот персик он рыжий хулиган

Normalized comparison:
у меня есть кот персик он рыжий хулиган
```

This should not be treated as a recognition failure merely because punctuation
and capitalization differ.

---

## Word Error Rate

Word Error Rate (WER) will be used as the primary objective transcription metric.

WER is based on:

- substitutions;
- deletions;
- insertions;

relative to the expected transcript.

The benchmark runner should calculate WER automatically.

WER must not be treated as the only measure of quality.

---

## Semantic Errors

Some recognition errors are much more important than others.

For example:

```text
Expected:
fifteen

Recognized:
fifty
```

may have serious consequences despite being only one word error.

Results should therefore support semantic error annotations.

Suggested categories:

```text
none
minor
critical
```

### Minor

The wording differs but the intended meaning remains clear.

### Critical

The recognition changes important meaning.

Examples include errors involving:

- numbers;
- negation;
- names;
- locations;
- times;
- instructions;
- logistics identifiers.

Semantic classification may initially be reviewed manually.

It should not be silently generated by an LLM and treated as objective ground truth.

---

## Batch Latency

For non-streaming recognition, measure:

```text
request_started_at
response_received_at
total_processing_ms
```

The timer starts immediately before sending the provider request.

The timer ends when the final provider response has been received.

---

## Streaming Latency

Streaming providers must receive audio progressively.

A prerecorded fixture must not be sent instantly as one complete buffer when
the benchmark is intended to represent realtime microphone usage.

The benchmark runner should replay audio chunks according to the recording's
actual timing.

For example:

```text
audio timeline

0 ms      chunk
250 ms    chunk
500 ms    chunk
750 ms    chunk
...
```

This allows streaming latency to represent realistic use.

---

## Streaming Metrics

Where supported, collect:

```text
time_to_first_partial_ms
time_to_first_final_ms
time_to_end_of_turn_ms
```

### Time to first partial

Time between the beginning of audio transmission and the first useful
partial transcript.

### Time to first final

Time between the beginning of audio transmission and the first transcript
segment marked as final by the provider.

### Time to end of turn

Time between the actual end of spoken audio and the provider deciding that
the speaker has completed the turn.

This metric is especially important for conversational translation.

---

## Repeated Latency Runs

Network latency varies between requests.

Latency measurements should therefore be repeated.

Initial target:

```text
3 runs per provider per audio fixture
```

Accuracy results should normally be based on the provider's deterministic
or representative output.

Latency results should preserve individual runs and calculate at least:

- minimum;
- median;
- maximum.

Median latency should be preferred over a single run when comparing providers.

---

## Realtime Translation Metrics

Direct realtime translation providers require additional measurements.

Where supported, collect:

```text
time_to_first_translation_text_ms
time_to_first_translated_audio_ms
translation_completed_ms
```

The benchmark should also preserve:

- source transcript where available;
- translated text where available;
- translated audio metadata where available.

---

## Translation Quality

Speech recognition quality and translation quality are different measurements.

A perfect translation of an incorrectly recognized sentence is still a failed
end-to-end result.

Where translation is evaluated, the report should distinguish:

```text
source recognition error
translation error
speech output issue
```

These should not be collapsed into a single unexplained score.

---

## Turn Detection

Providers that support conversational turn detection should be evaluated for it.

Important behavior includes:

- detecting the real end of a phrase;
- not finalizing during natural short pauses;
- not waiting unnecessarily long after speech has ended;
- handling a speaker who resumes after a pause.

Turn detection results may initially include both measured latency and
manual observations.

---

## Cost Measurement

Provider pricing must be recorded separately from runtime metrics.

For each provider, document where applicable:

```text
price_per_minute
estimated_price_per_hour
pricing_mode
pricing_source
pricing_checked_at
```

Pricing must include the date on which it was verified because provider pricing
may change.

Cost calculations should use current official provider pricing.

---

## Benchmark Result Schema

A speech recognition result should contain information similar to:

```json
{
    "phrase_id": "ru-001",
    "provider": "google",
    "model": "chirp_3",
    "language": "ru",
    "expected": "Я свободна или не свободна?",
    "transcript": "Я свободна или не свободна?",
    "normalized_expected": "я свободна или не свободна",
    "normalized_transcript": "я свободна или не свободна",
    "wer": 0.0,
    "semantic_error": "none",
    "latency": {
        "first_partial_ms": null,
        "first_final_ms": 850,
        "end_of_turn_ms": null
    }
}
```

The final schema may evolve while the benchmark runner is implemented,
but changes must remain provider-neutral.

---

## Result Storage

Benchmark results should be stored in both machine-readable and
human-readable formats.

Planned structure:

```text
docs/benchmarks/speech/
├── methodology.md
├── phrases.json
├── audio/
└── results/
    ├── results.json
    ├── results.csv
    └── report.md
```

### JSON

Canonical structured benchmark data.

### CSV

Convenient analysis and spreadsheet comparison.

### Markdown

Human-readable summary and observations.

---

## Provider Comparison

Providers must not receive a single arbitrary overall score.

The final report should compare separate dimensions such as:

```text
Russian accuracy
English accuracy
streaming latency
turn detection
translation latency
cost
implementation complexity
```

This prevents a provider from appearing universally better simply because
several unrelated measurements were combined with arbitrary weights.

---

## Fair Comparison Rules

During the benchmark:

- all compatible providers receive the same source recordings;
- expected transcripts are fixed before results are collected;
- provider output is preserved unchanged;
- failures are recorded rather than removed;
- retries are documented;
- audio preprocessing is documented;
- pricing sources and dates are recorded;
- latency is measured consistently;
- STT and end-to-end translation are evaluated separately.

---

## Failure Handling

Provider failures are valid benchmark results.

Examples:

- timeout;
- unsupported format;
- unsupported language;
- connection failure;
- provider error;
- empty transcript.

A failed request must not simply disappear from the results.

The failure and reason should be recorded.

---

## Benchmark Environment

Every benchmark run should record relevant environment information.

Where applicable:

```text
benchmark date
application version / Git commit
PHP version
benchmark runner version
source audio fixture version
provider
provider model
provider region
network environment
```

This makes historical results easier to reproduce and compare.

---

## Benchmark Scope Boundaries

The benchmark is intentionally limited.

It is not intended to evaluate:

- every speech provider on the market;
- every language;
- every accent;
- studio recording quality;
- large-scale production throughput;
- every possible provider configuration.

The initial research focuses on the Voice Translator use case:

```text
Russian ↔ English
near realtime
conversational speech
```

The provider list may only be expanded when there is a clear technical reason.

The purpose of the research is to make a decision, not to create an eternal
collection of speech APIs.

---

## Deliverables

This research phase should eventually produce:

1. benchmark methodology;
2. fixed RU/EN phrase dataset;
3. prerecorded audio fixtures;
4. automated benchmark runner;
5. provider benchmark results;
6. comparison report;
7. Architecture Decision Record.

---

## Decision Output

The benchmark itself does not choose a winner.

It provides evidence.

The final technical choice will be documented separately in an ADR.

The ADR should explain:

- selected architecture;
- selected provider or providers;
- supporting benchmark evidence;
- important trade-offs;
- rejected alternatives;
- known limitations.

---

## Definition of Done

This methodology task is complete when:

- benchmark goals are documented;
- dataset rules are documented;
- recording rules are documented;
- accuracy metrics are defined;
- WER normalization rules are defined;
- semantic error handling is defined;
- batch latency measurement is defined;
- streaming latency measurement is defined;
- realtime translation metrics are defined;
- cost measurement is defined;
- result storage formats are defined;
- provider comparison rules are defined;
- STT evaluation is separated from end-to-end translation evaluation;
- the methodology can be reused against the same audio fixtures;
- the methodology is finalized before provider benchmark results are collected.
