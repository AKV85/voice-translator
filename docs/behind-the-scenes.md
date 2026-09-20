# Voice Translator: Behind the Scenes

This is not the technical documentation.

The serious documentation lives elsewhere and contains respectable things
such as architecture decisions, APIs, tests, providers and benchmarks.

This file contains what actually happened.

It is a record of how Voice Translator was built, which ideas survived,
which assumptions did not, and how seemingly small problems occasionally
turned into research projects.

---

## Chapter 1: How hard can voice translation be?

The original idea sounded suspiciously simple.

I speak Russian.

The person on the other side hears English.

They speak English.

I hear Russian.

No typing.
No copy-paste.
No stopping a conversation to use a separate translator.

The initial use case was something like a Microsoft Teams conversation
between two people who do not speak the same language.

The first version of the idea was deliberately strict:

1. One person presses Start.
2. They speak.
3. They press Stop.
4. The application translates the speech.
5. The other person does the same in the opposite direction.

No simultaneous talking.
No magical real-time interpretation.
No attempt to solve every speech problem invented since the Tower of Babel.

Just push-to-talk translation.

That sounded manageable.

Naturally, things escalated.

---

## Chapter 2: Building the foundation

Before connecting any AI provider, the project was designed around
provider-neutral contracts.

The application should not care whether speech recognition comes from
Google, OpenAI, Deepgram, or something that does not exist yet.

The basic pipeline became:

```text
Speech
  ↓
Speech-to-Text
  ↓
Translation
  ↓
Text-to-Speech
```

With separate contracts for each stage:

```text
SpeechToTextProvider
TranslationProvider
TextToSpeechProvider
```

The orchestration layer was kept separate from provider implementations,
so the application logic would not become married to one vendor after
the first successful API call.

This decision looked slightly over-engineered when the project still
could not record a microphone.

Later, it became one of the most useful decisions in the project.

At that point, however, the immediate goal was less ambitious:

> Make the browser record audio successfully.

Using the browser MediaRecorder API, the application learned to record
microphone input into WebM/Opus audio.

Start worked.

Stop worked.

Playback worked.

Repeated recordings worked.

For a brief moment, everything looked suspiciously easy.

Then Google Cloud entered the story.

---

## Chapter 3: The Google Cloud initiation ceremony

The first real speech recognition provider was Google Speech-to-Text V2.

Installing the SDK was the easy part.

Then came:

- Google Cloud projects;
- billing accounts;
- budgets;
- enabling APIs;
- Application Default Credentials;
- OAuth consent;
- Docker volume mounts;
- environment variables;
- and several opportunities to question whether recognizing a sentence
  should really require this much administrative infrastructure.

Eventually the application had this path:

```text
Browser audio
    ↓
Laravel
    ↓
AudioInput DTO
    ↓
SpeechToTextProvider
    ↓
GoogleSpeechToTextProvider
    ↓
Google Speech-to-Text V2
```

Tests used a fake provider, so CI never needed real Google credentials
and never spent real money.

The real provider was only used during actual application requests.

A sensible architecture had survived contact with reality.

Mostly.

---

## Chapter 4: The credentials that existed everywhere except where they were needed

Application Default Credentials were created successfully.

The credentials file existed.

Docker could see it.

The shell could see it.

PHP CLI could see it.

Google's authentication library could load it.

Everything was correct.

Except the HTTP request still failed with:

```text
Could not construct ApplicationDefaultCredentials
Your default credentials were not found.
```

Eventually the problem turned out to be delightfully specific.

Laravel Sail was running:

```text
artisan serve
```

which started another PHP process:

```text
php -S 0.0.0.0:80
```

The credentials environment variable existed in the container,
but the actual process serving HTTP requests did not have it.

So the credentials were:

```text
inside Docker        ✓
readable             ✓
valid                ✓
usable from CLI      ✓
available to HTTP    ✗
```

The application was updated to explicitly configure the credentials path
before constructing the Google client.

Speech recognition finally worked.

There was only one problem left.

It had to understand speech.

---

## Chapter 5: The first Russian sentence

One of the first test phrases was:

```text
Я свободна или не свободна?
```

Google returned:

```text
Я свободна единица свободна
```

Technically, this was excellent news.

The entire pipeline worked:

```text
Microphone
→ WebM
→ Laravel
→ Google
→ transcript
→ browser
```

Semantically, it was less impressive.

The phrase had successfully travelled through several layers of modern
cloud infrastructure only to emerge slightly confused.

It was the first hint that connecting a speech API and building a reliable
voice translator were two very different problems.

---

## Chapter 6: The cat that started the R&D department

Then came Persik.

The test phrase was:

```text
Кот Персик рыжий хулиган.
```

Google heard:

```text
Код Персик рыжий хулиган.
```

In Russian, the words "кот" (cat) and "код" (code) can sound nearly
identical because the final consonant is devoiced.

Without enough context, the recognizer made a perfectly plausible choice.

Unfortunately, it turned the cat into software.

A second test added context:

```text
У меня есть кот Персик, он рыжий хулиган.
```

This time Google correctly understood that Persik was a cat
and not a software artifact.

That small experiment changed the direction of the project.

The question was no longer:

> Can we connect speech recognition?

We already could.

The new question became:

> Which speech technology is actually good enough for near real-time
> Russian-English conversation?

And that question could not be answered by reading marketing pages.

---

## Chapter 7: From feature development to R&D

At this point, the project stopped being only a straightforward
implementation exercise.

A real product question had appeared.

The goal was not to build an editable speech transcription tool.

The goal was still the original one:

```text
Russian speech
    ↓
English speech
```

and in the opposite direction:

```text
English speech
    ↓
Russian speech
```

with as little delay as realistically possible.

That immediately raised several questions:

- How accurate is each speech recognizer on Russian?
- How accurate is it on English?
- How quickly does partial text appear?
- How quickly does the recognizer decide that a phrase is final?
- Can it detect the end of a speaker's turn reliably?
- How much does one hour of conversation cost?
- Is a classic STT → Translation → TTS pipeline still the best design?
- Or is direct realtime speech translation better?

The project had accidentally acquired an R&D phase.

Not because research sounded impressive.

Because choosing the wrong speech technology first and discovering the
problem after building the entire realtime pipeline would be considerably
less impressive.

---

## Chapter 8: Four models enter, one architecture leaves

Instead of selecting a provider because its website contains the greatest
number of words such as "AI", "realtime", "next-generation" and
"state-of-the-art", the project will run a reproducible benchmark.

The current candidates are:

- Google Chirp 3
- Deepgram Nova-3
- Deepgram Flux
- OpenAI Realtime Translate

These candidates do not all solve exactly the same problem.

Google Chirp 3, Deepgram Nova-3 and Deepgram Flux are primarily candidates
for speech recognition.

OpenAI Realtime Translate is interesting for a different reason:
it may replace several stages of the traditional pipeline by translating
speech directly in realtime.

That means the research will answer two related questions.

First:

> Which provider gives us the best speech recognition for Russian and English?

Second:

> Which complete architecture gives us the best realtime translation experience?

Those are not necessarily the same answer.

---

## Chapter 9: One recording, many models

The benchmark will not rely on speaking the same sentence four times.

Humans are impressively bad at reproducing exactly the same speech twice.

The speed changes.

The pauses change.

The microphone position changes.

The intonation changes.

Then everyone looks at the results and pretends the comparison was scientific.

So each benchmark phrase will be recorded once.

The exact same audio file will then be sent to every compatible provider.

The benchmark dataset will contain both Russian and English speech.

It will include:

- simple sentences;
- longer sentences;
- numbers;
- times;
- names;
- logistics terminology;
- similar-sounding words;
- negations;
- phrases with pauses;
- phrases spoken more quickly;
- phrases where context matters.

The now-famous Persik test will stay.

A benchmark that cannot distinguish a cat from source code clearly still
has unfinished business.

---

## Chapter 10: What will be measured

Accuracy alone is not enough.

A model that produces perfect text ten seconds later is not particularly
useful in a live conversation.

A model that responds instantly but invents half the sentence is also
unlikely to improve international communication.

The benchmark will therefore measure several dimensions.

### Recognition quality

Where possible, objective metrics such as Word Error Rate will be used.

The expected transcript will be stored with every test phrase.

The provider output will be compared against it.

Semantic errors will also be recorded separately because not all word
errors are equally important.

For example:

```text
"fifteen" → "fifty"
```

may be much more dangerous than:

```text
"the warehouse" → "warehouse"
```

even if both count as a small textual difference.

### Latency

For streaming providers, the benchmark should record:

```text
time_to_first_text_ms
time_to_final_text_ms
```

For end-to-end realtime translation, additional measurements may include:

```text
time_to_first_translation_ms
time_to_first_audio_ms
```

The final user experience depends heavily on these values.

### Cost

Approximate cost per minute and per hour will be recorded.

The cheapest model is not automatically the best choice.

But a model that is three times more expensive should probably provide
a reason for the privilege.

---

## Chapter 11: The benchmark should be automatic

The benchmark itself should be reproducible.

The goal is eventually to run something similar to:

```bash
sail artisan benchmark:speech
```

and let the application process the entire fixture set.

Conceptually:

```text
ru-001.wav
    ├── Google Chirp 3
    ├── Deepgram Nova-3
    ├── Deepgram Flux
    └── OpenAI Realtime Translate
```

with results written automatically into machine-readable files and a
human-readable report.

The repository will keep:

```text
docs/
├── benchmarks/
│   └── speech/
│       ├── methodology.md
│       ├── phrases.json
│       ├── audio/
│       └── results/
│           ├── results.json
│           ├── results.csv
│           └── report.md
│
├── adr/
│   └── 001-speech-provider.md
│
└── behind-the-scenes.md
```

The benchmark report will answer:

> What happened?

The ADR will answer:

> What did we decide because of it?

And this file will answer:

> How did we somehow end up doing all this in the first place?

---

## Chapter 12: Where the project is now

Voice Translator started as a small push-to-talk translation experiment.

It now has:

- browser microphone recording;
- WebM/Opus audio capture;
- a provider-neutral speech architecture;
- Google Speech-to-Text integration;
- Russian and English recognition;
- an HTTP transcription endpoint;
- automated feature and unit tests;
- safe provider error handling;
- local Google ADC integration through Laravel Sail;
- a working end-to-end audio pipeline;
- and, unexpectedly, an R&D phase.

The next step is not blindly adding more features.

The next step is preparing the benchmark dataset, recording the fixtures
and measuring the competing technologies.

Only after that will the realtime translation architecture be selected.

The plan is simple:

```text
Hypothesis
    ↓
Benchmark
    ↓
Measurements
    ↓
Decision
    ↓
Architecture
    ↓
Implementation
```

Which is a much healthier process than:

```text
Cool API
    ↓
Integrate everything
    ↓
Discover problem
    ↓
Regret
```

The project is still aiming at the same original goal:

> Speak naturally in one language and let the other person hear the translation
> with as little friction and delay as possible.

The route to that goal simply became more interesting than expected.

All because one ginger hooligan was briefly recognized as code.

Thanks, Persik.
