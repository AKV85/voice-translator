# VT-17: RU ↔ EN Translation Provider Benchmark

## Goal

Evaluate translation approaches for the Voice Translator MVP and compare them by:

- preservation of critical information;
- end-to-end reliability;
- latency after the speaker presses STOP;
- STT, translation, and TTS contribution to latency;
- failure modes relevant to logistics conversations.

The primary user-facing latency metric is:

> **STOP speaking → first audible translated audio available**

This is more relevant for the push-to-talk product than total processing duration.

---

## Compared approaches

Four approaches were evaluated.

### 1. OpenAI Realtime

Direct realtime speech-to-speech translation.

The provider receives audio while the speaker is still talking and may start producing translated audio before the input has finished.

### 2. Deepgram Flux + OpenAI GPT + OpenAI TTS

Sequential pipeline:

```text
Deepgram Flux STT
    ↓
OpenAI GPT-5.4-mini translation
    ↓
OpenAI streaming TTS
```

Benchmark profile:

```text
flux-openai-openai
```

### 3. Deepgram Flux + DeepL + OpenAI TTS

Sequential pipeline:

```text
Deepgram Flux STT
    ↓
DeepL latency_optimized
    ↓
OpenAI streaming TTS
```

Benchmark profile:

```text
flux-deepl-openai
```

### 4. Google Chirp 3 + DeepL + OpenAI TTS

Sequential pipeline:

```text
Google Chirp 3 STT
    ↓
DeepL latency_optimized
    ↓
OpenAI streaming TTS
```

Benchmark profile:

```text
chirp3-deepl-openai
```

Chirp 3 is a batch STT provider in this benchmark.

`BatchSpeechToTextStreamingAdapter` buffers the complete input and starts recognition only after the input has finished. Therefore this profile represents a classic push-to-talk sequential pipeline rather than streaming STT.

---

## Dataset

The benchmark contains:

- 15 Russian source phrases;
- 15 English source phrases;
- 3 runs per phrase;
- 45 runs per language direction for the sequential pipelines.

The phrases contain logistics-oriented critical information such as:

- names;
- truck and trailer identifiers;
- destinations;
- times;
- temperatures;
- negation;
- unloading/loading actions;
- operational instructions.

Quality is evaluated using **critical-element preservation**, not exact reference translation matching.

This is intentional because multiple translations may be semantically valid even when their wording differs.

---

# Final results

## Critical-element preservation

| Pipeline | RU → EN | EN → RU |
|---|---:|---:|
| OpenAI Realtime | 69/86 = **80.23%** | 67/94 = **71.28%** |
| Flux + DeepL + OpenAI TTS | 78/90 = **86.67%** | 75/96 = **78.13%** |
| Flux + OpenAI GPT + OpenAI TTS | 84/90 = **93.33%** | 76/95 = **80.00%*** |
| Chirp 3 + DeepL + OpenAI TTS | 84/90 = **93.33%** | 87/96 = **90.63%** |

\* One EN → RU OpenAI translation request failed. The evaluator excludes failed translations from the critical-element denominator. If that run is counted as an end-to-end failure, the result is:

```text
76/96 = 79.17%
```

---

## Median STOP → audible translated audio

| Pipeline | RU → EN | EN → RU |
|---|---:|---:|
| OpenAI Realtime | ~0 ms | ~0 ms |
| Flux + DeepL + OpenAI TTS | **1236 ms** | **1279 ms** |
| Flux + OpenAI GPT + OpenAI TTS | **1809 ms** | **2028 ms** |
| Chirp 3 + DeepL + OpenAI TTS | **2104 ms** | **1982 ms** |

The realtime result is not directly equivalent to the sequential pipelines.

OpenAI Realtime frequently starts generating translated audio **before the user finishes speaking**, so its STOP-relative latency can be zero even though the response has already been in progress for several seconds.

For the push-to-talk sequential pipelines, all recognition begins or completes around STOP and translation/TTS follow afterwards.

---

# Chirp 3 + DeepL final results

## RU → EN

### Reliability

```text
Successful runs:       45/45
STT successful:        45/45
Translation successful:45/45
TTS successful:        45/45
Audible audio:         45/45
```

### Quality

```text
Critical elements: 84/90 = 93.33%
Manual review:     6 runs
```

Remaining failures:

```text
ru-002 ×3: cat_name
ru-003 ×3: cat_name
```

These are not Chirp STT failures.

Chirp correctly preserves the Russian name:

```text
Персик
```

DeepL then translates the name as:

```text
Peach
```

Therefore the remaining RU → EN critical-element failures are attributed to the translation stage rather than STT.

### Latency

```text
STT after STOP
median: 980.88 ms
p95:   1225.00 ms
max:   3667.58 ms

Translation
median: 188.04 ms
p95:    399.48 ms

TTS → audible
median:   822.59 ms
p95:     4290.64 ms
max:    10757.14 ms

STOP → audio ready
median:  2103.64 ms
p95:     5808.53 ms
max:    11958.11 ms
```

The large RU → EN p95 latency is primarily caused by TTS tail latency rather than Chirp recognition latency.

---

## EN → RU

### Reliability

```text
Successful runs:       45/45
STT successful:        45/45
Translation successful:45/45
TTS successful:        45/45
Audible audio:         45/45
```

### Quality

```text
Critical elements: 87/96 = 90.63%
Manual review:     6 runs
```

Remaining failures:

```text
en-004 ×3:
unloaded → allowed

en-009 ×3:
truck → track
```

For `en-004`, Chirp produced:

```text
Do not send this truck because the driver has not allowed it yet.
```

instead of:

```text
Do not send this truck because the driver has not unloaded yet.
```

DeepL then correctly translated the incorrect transcript.

For `en-009`, Chirp consistently recognized:

```text
track
```

instead of:

```text
truck
```

Therefore the remaining EN → RU failures are attributed to STT.

### Latency

```text
STT after STOP
median: 875.81 ms
p95:   1152.04 ms
max:   3476.87 ms

Translation
median: 219.58 ms
p95:    394.28 ms

TTS → audible
median:  895.62 ms
p95:    1531.45 ms
max:    1931.04 ms

STOP → audio ready
median: 1982.45 ms
p95:    3035.90 ms
max:    5211.83 ms
```

---

# Flux vs Chirp 3

Using the same translation and TTS providers allows the STT trade-off to be compared more directly.

Both pipelines use:

```text
DeepL latency_optimized
OpenAI streaming TTS
```

The main variable is therefore:

```text
Deepgram Flux STT
vs
Google Chirp 3 STT
```

## RU → EN

```text
Flux + DeepL
critical preservation: 86.67%
STOP → audio median:    1236 ms

Chirp 3 + DeepL
critical preservation: 93.33%
STOP → audio median:    2104 ms
```

Difference:

```text
Quality: +6.66 percentage points
Latency: +868 ms
```

## EN → RU

```text
Flux + DeepL
critical preservation: 78.13%
STOP → audio median:    1279 ms

Chirp 3 + DeepL
critical preservation: 90.63%
STOP → audio median:    1982 ms
```

Difference:

```text
Quality: +12.50 percentage points
Latency: +703 ms
```

The Chirp 3 pipeline therefore trades approximately **0.7–0.9 seconds of additional median response latency** for substantially better preservation of important information.

---

# STT error analysis

Earlier Flux runs showed several systematic transcription problems.

Examples included:

```text
Клайпеда → айпед
Klaipeda → Clebra
ZZ 546 → distorted trailer identifier
leave → live
unloading → loading
truck → track
```

Targeted Chirp 3 tests corrected most of these.

Chirp correctly handled previously problematic examples involving:

- Klaipėda;
- Russian `Клайпеда`;
- trailer identifier `ZZ 546`;
- `leave`;
- `unloading`.

The remaining repeated Chirp STT errors were:

```text
unloaded → allowed
truck → track
```

`truck → track` was also observed with Flux, indicating that this phrase is difficult for more than one STT provider.

---

# Translation error analysis

The benchmark also demonstrates why end-to-end quality cannot be attributed only to the translation provider.

A sequential pipeline contains at least:

```text
speech recognition
    ↓
text translation
    ↓
speech synthesis
```

An incorrect STT transcript may result in a perfectly valid translation of the wrong text.

Conversely, correct STT does not guarantee correct translation.

The clearest example is:

```text
Персик
```

Chirp recognizes the name correctly, but DeepL translates it as:

```text
Peach
```

This is a genuine translation-stage error.

Therefore raw pipeline critical-element scores should be treated as **end-to-end quality metrics**, not as isolated translator rankings.

---

# Critical-element evaluator improvements

During analysis, several correct translations were initially reported as failures because accepted variants were incomplete.

Legitimate variants were added for cases such as:

```text
8 PM → 20:00
degrees → °
```

The benchmark text normalizer was also updated to preserve the degree symbol:

```text
°
```

Previously it removed this symbol during normalization, causing valid outputs such as:

```text
2–6°
```

to fail `temperature_unit` checks.

Critical-element matching was also updated so accepted values beginning or ending with non-alphanumeric characters can be matched safely while preserving word boundaries for ordinary text values.

These changes corrected evaluator false positives without relaxing actual semantic requirements.

---

# TTS observations

OpenAI streaming TTS successfully produced audible audio for all final Chirp pipeline runs.

However, TTS showed noticeable tail latency in RU → EN.

Example:

```text
TTS → audible

median:   822.59 ms
p95:     4290.64 ms
max:    10757.14 ms
```

This means that after improving STT quality, TTS can become the dominant latency source in slower requests.

There was also one earlier targeted test where the TTS request failed after a long network timeout. Later full benchmark runs completed successfully, so that isolated failure is not part of the final 45-run dataset.

---

# Realtime observations

OpenAI Realtime has a fundamentally different latency model.

Translated speech can begin while source speech is still being received.

This produces extremely low STOP-relative latency:

```text
median STOP → audio ready ≈ 0 ms
```

but this should not be interpreted as zero end-to-end processing latency.

The model is predicting and translating incrementally before the speaker has finished.

Realtime therefore provides a significant responsiveness advantage but showed lower critical-element preservation in this benchmark:

```text
RU → EN: 80.23%
EN → RU: 71.28%
```

The observed failures included names, identifiers, numbers, destinations, logistics terminology, and occasional hallucinated or incomplete content.

For logistics communication, these errors are more important than stylistic translation differences.

---

# Reliability

Final sequential pipeline reliability:

| Pipeline | Direction | Successful runs |
|---|---|---:|
| Flux + DeepL + OpenAI TTS | RU → EN | 45/45 |
| Flux + DeepL + OpenAI TTS | EN → RU | 45/45 |
| Flux + OpenAI GPT + OpenAI TTS | RU → EN | 45/45 |
| Flux + OpenAI GPT + OpenAI TTS | EN → RU | 44/45 |
| Chirp 3 + DeepL + OpenAI TTS | RU → EN | 45/45 |
| Chirp 3 + DeepL + OpenAI TTS | EN → RU | 45/45 |

The Flux + OpenAI GPT EN → RU pipeline had one translation provider failure.

The final Chirp + DeepL runs completed all STT, translation, and TTS stages successfully.

---

# Interpretation for the MVP

The benchmark shows three distinct product trade-offs.

### Realtime

Strength:

```text
lowest perceived latency
```

Weakness:

```text
lower critical-information preservation
```

Realtime may be attractive for natural conversation, but the observed semantic errors are significant for logistics communication where a wrong identifier, destination, number, or negation can change the operational meaning.

### Flux sequential pipeline

Strength:

```text
lowest latency among tested sequential pipelines
```

Typical median STOP → audible latency:

```text
~1.2–1.3 s with DeepL
```

Weakness:

```text
more STT errors on domain-specific phrases
```

### Chirp 3 sequential pipeline

Strength:

```text
substantially better critical-information preservation
```

Final quality:

```text
RU → EN: 93.33%
EN → RU: 90.63%
```

Cost:

```text
~2.0–2.1 s median STOP → audible latency
```

For a push-to-talk workflow, approximately two seconds after STOP is potentially acceptable if the additional delay significantly reduces operationally dangerous translation errors.

---

# Current engineering conclusion

The benchmark supports continuing MVP development with a **sequential push-to-talk architecture** rather than depending exclusively on direct realtime speech-to-speech translation.

Among the tested sequential configurations, Chirp 3 provides a strong quality baseline for speech recognition, while DeepL provides low translation latency.

The main remaining areas for improvement are:

1. domain/name handling in translation, especially proper names such as `Персик`;
2. STT ambiguity such as `truck/track` and `unloaded/allowed`;
3. TTS tail latency;
4. domain-specific vocabulary support and contextual hints;
5. testing with real conversation audio rather than only controlled benchmark fixtures.

The benchmark does not establish a permanent production provider choice. It establishes measurable baselines and identifies where quality and latency are currently lost.

---

# Benchmark limitations

The results should be interpreted with the following limitations.

### Small controlled dataset

The dataset contains 30 phrases and focuses on known logistics and conversational failure modes.

It is useful for regression testing but is not representative of every real-world conversation.

### Synthetic/controlled recordings

Recognition quality on real Teams audio may differ because of:

- microphones;
- background noise;
- compression;
- accents;
- interruptions;
- overlapping speech;
- unstable network conditions.

### Different pipeline semantics

Realtime and sequential pipelines do not process speech in the same way.

Their STOP-relative latency values therefore measure different interaction models.

### Batch Chirp adapter

The Chirp 3 implementation used for this benchmark is intentionally sequential.

It buffers the complete speech input and performs batch recognition after STOP.

### Critical-element metric

Critical-element preservation measures whether predefined important information survived the pipeline.

It does not measure:

- fluency;
- grammar quality;
- naturalness;
- tone;
- pronunciation quality.

### Audio audibility

The PCM audibility detector verifies that translated audio contains detectable signal energy.

It does **not** verify that pronunciation or spoken content is correct.

### Provider latency variability

External API latency is variable.

Cold starts, networking, provider load, and TTS tail latency can produce outliers.

Median and p95 values should therefore be considered together rather than relying on a single average.

---

# Artifacts

Benchmark configuration:

```text
config/benchmarks.php
```

Translation dataset:

```text
docs/benchmarks/translation/phrases.json
```

Final pipeline results:

```text
docs/benchmarks/translation/results/flux-openai-openai/
docs/benchmarks/translation/results/flux-deepl-openai/
docs/benchmarks/translation/results/chirp3-deepl-openai/
```

Pipeline benchmark implementation:

```text
app/Services/Benchmark/PipelineTranslationBenchmarkRunner.php
app/Services/Benchmark/PipelineTranslationBenchmarkEvaluator.php
app/Console/Commands/BenchmarkPipelineTranslationCommand.php
app/Console/Commands/EvaluatePipelineTranslationBenchmarkCommand.php
```

Audio evaluation:

```text
app/Services/Benchmark/Pcm16AudibilityDetector.php
```

Chirp batch adapter:

```text
app/Services/Speech/BatchSpeechToTextStreamingAdapter.php
```

OpenAI streaming TTS:

```text
app/Services/Speech/OpenAIStreamingTextToSpeechProvider.php
```