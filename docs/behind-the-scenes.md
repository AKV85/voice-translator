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

Once the project had a working speech pipeline, there was a tempting next step:

> Pick a provider and continue building.

That would have been faster.

It would also have been a wonderfully efficient way to discover six weeks later
that the chosen provider was excellent at demos and less excellent at understanding
a truck number spoken with an accent.

So the project took the less exciting but considerably more useful route.

It built a benchmark.

The main speech candidates became:

- Google Chirp 3;
- Deepgram Nova-3;
- Deepgram Flux;
- OpenAI Realtime Translate.

These technologies did not all solve exactly the same problem.

Chirp 3, Nova-3 and Flux were primarily speech-recognition candidates.

OpenAI Realtime was more ambitious.

It could receive speech and start producing translated speech while the speaker
was still talking.

That meant the benchmark was actually asking two different questions:

> Which technology recognizes Russian and English most reliably?

and:

> Which complete architecture produces the best conversation experience?

Those questions turned out to have different answers.

Naturally.

A single answer would have been far too convenient.

---

## Chapter 9: One recording, many models

The first rule of the benchmark was simple:

> Do not compare providers using different recordings.

Speaking the same sentence twice sounds easy until a human actually tries it.

The speed changes.

The pause changes.

The microphone moves three centimetres.

One version sounds confident.

The next sounds like the speaker has just remembered they left the oven on.

So the benchmark dataset used controlled recordings that could be replayed
against different providers.

The translation benchmark contained:

```text
15 Russian phrases
15 English phrases
3 runs per phrase
```

The phrases were deliberately not limited to friendly textbook sentences.

They included things a logistics-oriented translator should probably not destroy:

- names;
- truck identifiers;
- trailer identifiers;
- destinations;
- loading and unloading actions;
- times;
- numbers;
- temperatures;
- negation;
- operational instructions;
- similar-sounding words.

And Persik remained in the dataset.

Obviously.

If an entire R&D phase starts because a cat becomes code, the cat earns tenure.

The important change was that quality was no longer judged by asking:

> Does this translation look nice?

Instead, each phrase contained critical elements that had to survive the complete
pipeline.

For example, a translation could use different grammar and still be correct.

But if:

```text
15
```

became:

```text
50
```

the sentence had failed regardless of how elegant the surrounding prose was.

That distinction became increasingly important once the project moved into
logistics-oriented phrases.

---

## Chapter 10: Measuring what actually matters

The benchmark originally considered familiar speech metrics such as Word Error Rate.

Those are useful.

They are not enough.

For this product, some errors are much more dangerous than others.

These two mistakes are not equivalent:

```text
"the warehouse" → "warehouse"
```

and:

```text
"unloaded" → "allowed"
```

One loses an article.

The other may send a truck somewhere it should not go.

So the main end-to-end quality metric became:

```text
critical-element preservation
```

The latency metric also became more specific.

The product is push-to-talk.

The user speaks.

The user presses STOP.

Then the user waits.

So the primary user-facing latency metric became:

> STOP speaking → first audible translated audio available

That metric crosses several systems:

```text
STOP
  ↓
Speech recognition completes
  ↓
Translation completes
  ↓
TTS produces audible PCM
  ↓
Translated audio becomes available
```

This was more useful than measuring a provider request in isolation.

A speech recognizer can be fast while TTS takes several seconds.

A translation provider can be fast while STT is still deciding whether the user
said "truck" or "track".

The benchmark therefore recorded individual stages as well as the complete
STOP-to-audio result.

The evaluator itself also became part of the experiment.

For example, legitimate variants had to be accepted:

```text
8 PM
```

and:

```text
20:00
```

may represent the same critical time.

The normalizer also had to learn that this character:

```text
°
```

is not decorative.

Removing it made valid temperatures fail evaluation.

Apparently even punctuation can become a production incident if given enough
opportunity.

---

## Chapter 11: The benchmark became a real system

The benchmark did not remain a spreadsheet with numbers copied by hand.

The project grew dedicated commands for:

```text
speech benchmarks
streaming speech benchmarks
translation benchmarks
realtime translation benchmarks
full pipeline benchmarks
evaluation
```

Typical commands became things such as:

```bash
sail artisan benchmark:speech
sail artisan benchmark:speech:stream
sail artisan benchmark:translation
sail artisan benchmark:translation:realtime
sail artisan benchmark:translation:pipeline
```

Results were written into structured JSON and could be evaluated repeatedly
without rerunning every external API request.

That mattered because benchmark logic also changed over time.

Accepted critical-element variants improved.

Normalization improved.

Audibility detection improved.

Error classification improved.

Being able to rerun evaluation on existing results made those improvements much
less expensive.

The provider-neutral architecture from Chapter 2 also finally justified the
suspicious amount of abstraction created before the microphone even worked.

Providers could be swapped while the surrounding benchmark machinery stayed
mostly unchanged.

So the early decision that looked slightly over-engineered became infrastructure
for the entire R&D process.

Annoyingly responsible architecture had won.

---

## Chapter 12: What the first full pipelines showed

The first major pipeline comparison produced four interesting approaches.

```text
OpenAI Realtime
Flux + OpenAI translation + OpenAI TTS
Flux + DeepL + OpenAI TTS
Chirp 3 + DeepL + OpenAI TTS
```

The final critical-element preservation results were:

| Pipeline | RU → EN | EN → RU |
|---|---:|---:|
| OpenAI Realtime | 80.23% | 71.28% |
| Flux + DeepL + OpenAI TTS | 86.67% | 78.13% |
| Flux + OpenAI translation + OpenAI TTS | 93.33% | 80.00%* |
| Chirp 3 + DeepL + OpenAI TTS | 93.33% | 90.63% |

One EN → RU OpenAI translation request failed in the OpenAI translation pipeline,
so its quality number needs that context.

The latency comparison told a different story.

Median STOP → audible translated audio was approximately:

| Pipeline | RU → EN | EN → RU |
|---|---:|---:|
| OpenAI Realtime | ~0 ms | ~0 ms |
| Flux + DeepL + OpenAI TTS | 1236 ms | 1279 ms |
| Flux + OpenAI translation + OpenAI TTS | 1809 ms | 2028 ms |
| Chirp 3 + DeepL + OpenAI TTS | 2104 ms | 1982 ms |

At first glance, OpenAI Realtime appeared to have discovered a minor violation
of physics.

It had approximately zero milliseconds of STOP-relative latency.

The explanation was less revolutionary.

It could begin translating and producing audio before the user pressed STOP.

That made STOP-relative latency close to zero because part of the work had already
happened.

It was a genuinely useful responsiveness advantage.

But quality was lower.

The observed failures affected exactly the information the project cared about:

- names;
- identifiers;
- numbers;
- destinations;
- logistics terminology;
- incomplete content;
- occasional hallucinated content.

For casual conversation, those trade-offs might be acceptable.

For operational communication, translating the wrong truck very quickly is still
translating the wrong truck.

Flux showed another clear trade-off.

With DeepL and OpenAI TTS it produced roughly:

```text
RU → EN: 1.24 s
EN → RU: 1.28 s
```

after STOP.

But critical-element preservation was:

```text
RU → EN: 86.67%
EN → RU: 78.13%
```

Batch Chirp 3 was slower:

```text
RU → EN: 2.10 s
EN → RU: 1.98 s
```

but quality improved to:

```text
RU → EN: 93.33%
EN → RU: 90.63%
```

The project therefore had its first real engineering trade-off:

> Is roughly 0.7–0.9 seconds of additional latency worth substantially better
> preservation of operationally important information?

For the push-to-talk MVP, the answer was yes.

But batch recognition was not the end of the story.

---

## Chapter 13: Batch was not enough

Batch Chirp 3 produced the strongest quality baseline.

Its problem was timing.

In the batch implementation, recognition started only after the complete recording
had been received.

That meant useful work waited politely for the user to finish.

Computers are very good at waiting.

Users are less enthusiastic.

So Chirp 3 was tested again using streaming recognition.

Two Google streaming configurations became particularly interesting:

```text
STANDARD
SHORT
```

The same DeepL translation and OpenAI streaming TTS stages were kept behind them.

That made the STT trade-off easier to see.

The results were:

| Profile | RU → EN quality | RU → EN STOP→STT | RU → EN STOP→audio | EN → RU quality | EN → RU STOP→STT | EN → RU STOP→audio |
|---|---:|---:|---:|---:|---:|---:|
| Batch Chirp 3 | 93.33% | ~981 ms | ~2104 ms | 90.63% | ~876 ms | ~1982 ms |
| Streaming STANDARD | 93.33% | ~718 ms | ~1701 ms | 90.63% | ~813 ms | ~1850 ms |
| Streaming SHORT | 90.00% | ~247 ms | ~1315 ms | 90.63% | ~676 ms | ~1677 ms |
| Deepgram Flux | 86.67% | ~258 ms | ~1236 ms | 78.13% | ~291 ms | ~1279 ms |

This produced a pleasantly non-symmetrical answer.

For Russian:

```text
STANDARD
```

kept the 93.33% quality baseline.

SHORT reduced STOP → STT dramatically:

```text
~718 ms → ~247 ms
```

but quality dropped:

```text
93.33% → 90.00%
```

The complete STOP → audio improvement was approximately:

```text
1701 ms → 1315 ms
```

That was useful.

But the project preferred preserving the higher measured Russian quality.

For English, SHORT behaved differently.

Quality remained:

```text
90.63%
```

while STOP → STT improved from approximately:

```text
813 ms → 676 ms
```

and STOP → audio improved from approximately:

```text
1850 ms → 1677 ms
```

There was no measured quality penalty in this dataset.

So the eventual public profiles became:

```text
Russian input → Chirp 3 Streaming STANDARD
English input → Chirp 3 Streaming SHORT
```

A single global "best model" would have been simpler.

The data declined to cooperate.

---

## Chapter 14: Translation joined the benchmark

Speech recognition had received most of the early attention because its mistakes
were highly visible.

Then the project encountered this:

```text
Персик
```

Chirp 3 recognized it correctly.

DeepL translated it as:

```text
Peach
```

The speech recognizer was innocent.

The translator had simply decided that the cat was a fruit.

This became an important reminder:

```text
STT quality
    ≠
translation quality
    ≠
end-to-end pipeline quality
```

A correct transcript can still produce the wrong translation.

An incorrect transcript can be translated perfectly and still produce the wrong
meaning.

The translation stage therefore received its own benchmarks and comparisons,
including Google translation, DeepL and OpenAI-based translation approaches.

For the selected sequential pipeline, DeepL's latency-optimized mode became a
strong practical choice.

In the final Chirp streaming runs, median translation latency was generally around
a few hundred milliseconds.

That made translation a relatively small part of the overall STOP-to-audio path.

The larger latency sources were often STT finalization and TTS.

Especially TTS.

Because apparently after spending weeks optimizing speech recognition, another
speech system was waiting patiently downstream with a stopwatch and bad intentions.

---

## Chapter 15: The laboratory escaped into the browser

Command-line benchmarks were useful.

They were also increasingly poor at answering one important question:

> What does this actually feel like?

So the project grew a browser-based Live Pipeline Lab.

The Lab could run several complete profiles, including:

```text
Chirp 3 Batch + DeepL + OpenAI TTS

Chirp 3 Streaming STANDARD + DeepL + OpenAI TTS

Chirp 3 Streaming SHORT + DeepL + OpenAI TTS

Deepgram Flux + DeepL + OpenAI TTS
```

A recording could be captured once and replayed through multiple profiles.

This was important.

Otherwise the comparison would once again suffer from the classic benchmark
methodology known as:

> I think I said roughly the same thing.

The Lab persisted runs and made it possible to inspect:

- transcript;
- translation;
- quality labels;
- individual stage timings;
- total response timing;
- historical runs;
- profile comparisons.

The same audio could therefore be compared across different pipelines.

That turned the browser into something closer to a small speech R&D workstation
than the original translator page.

Which was not exactly the original plan.

The original plan had one button.

Software projects are very respectful of original plans.

---

## Chapter 16: Measuring the thing the user actually feels

Server-side timing was still not enough.

Suppose the server reports:

```text
translated audio ready
```

at 1500 ms after STOP.

That sounds useful.

But the user does not listen to a server variable.

The user listens to a browser.

So the Lab also measured browser-side playback timing.

The timing chain became closer to:

```text
Speaker presses STOP
    ↓
STT final result
    ↓
Translation
    ↓
First TTS audio
    ↓
Audio available
    ↓
Browser playback actually starts
```

Useful metrics included:

```text
input duration
STOP → STT
translation latency
TTS → first audio
STOP → audio ready
STOP → browser playback
```

That final number mattered because it represented the delay the person could
actually perceive.

This distinction sounds obvious after it has been implemented.

Before implementation, infrastructure has an impressive ability to convince
everyone that its own timestamp is the centre of human experience.

It is not.

The user's ear wins.

---

## Chapter 17: From laboratory to public demo

The Lab was built for experimentation.

A public demo needed the opposite philosophy.

The Lab should allow comparison.

The public demo should allow translation.

So the public version deliberately removed most choices.

The browser does not select an arbitrary speech provider.

It does not select an arbitrary profile.

It does not decide which source language the WebSocket server should trust.

The server owns that configuration.

The public defaults became:

```text
RU → Chirp 3 Streaming STANDARD
EN → Chirp 3 Streaming SHORT
```

with:

```text
DeepL translation
OpenAI streaming TTS
```

The browser first requests a short-lived demo session.

The backend:

1. validates the requested source language;
2. selects the configured server-side profile;
3. reserves quota;
4. issues a short-lived one-time token.

The WebSocket connection then consumes that token.

The browser cannot simply announce:

```text
Hello, I would like the expensive experimental profile today.
```

and expect the server to admire its confidence.

The server also enforces limits such as:

```text
maximum audio duration
maximum audio bytes
visitor quotas
IP quotas
global quotas
```

The public recording duration is limited to 15 seconds.

A kill switch can disable the public demo completely.

The Live Lab is separately protected and can remain unavailable in production.

This produced an important architecture split:

```text
Lab
    flexible
    experimental
    comparison-oriented

Public Demo
    fixed
    limited
    server-controlled
    abuse-resistant
```

The same underlying pipeline infrastructure serves both.

The level of trust does not.

As it turns out, publishing a button on the internet requires slightly more
planning than putting the same button on localhost.

Humanity remains unpredictable.

---

## Chapter 18: Production is a different species

Local development eventually reached the dangerous stage where everything worked.

So the project was deployed.

Production runs on Railway with separate services for:

```text
web application
WebSocket pipeline server
MySQL
```

The web service handles the Laravel application and public demo session creation.

The WebSocket service handles the live audio pipeline.

The production container required more than a generic PHP image.

The runtime includes PHP 8.4 together with extensions required by the speech stack,
including:

```text
grpc
protobuf
intl
pcntl
pdo_mysql
zip
```

Frontend assets are built with Vite.

Laravel runs in production mode.

Provider credentials are supplied through environment configuration rather than
being committed to the repository, an architectural innovation known since the
early days of computing as:

> Please do not upload the secret key to GitHub.

Google Speech required another production-specific adjustment.

Local development could use a mounted credentials file.

A cloud deployment is less enthusiastic about files living on a developer's
Windows machine.

So production gained support for Google service-account credentials supplied as
JSON through the environment.

There were also the usual deployment details:

- trusted proxies;
- HTTPS awareness;
- shared application configuration;
- WebSocket URLs;
- database migrations;
- separate web and WebSocket process commands.

Eventually the public application reached:

```text
https://voice.kotov.lt
```

The public demo successfully translated both directions in production.

At that point the project had crossed an important line.

It was no longer only a local experiment with several impressive terminal windows.

It was a working deployed system.

---

## Chapter 19: The repository remembered too much

The benchmark dataset created one more problem.

Real microphone recordings are useful for local speech experiments.

They are less useful as permanent residents of public Git history.

The current application does not require raw benchmark microphone recordings to
run.

So raw WebM fixtures were removed from the tracked project and ignored for future
commits.

That immediately exposed another problem.

Some tests assumed those files always existed.

CI disagreed.

Quite reasonably.

The benchmark tests were updated so that normal application CI no longer depends
on private/local raw microphone recordings.

Synthetic fixture audio can be created where a test needs file input.

The repository was now correct going forward.

Git, however, is a historian.

An obsessive one.

Deleting a file in the latest commit does not mean the file never existed.

The old WebM recordings were still present in earlier history.

So the repository history was rewritten with `git-filter-repo`.

Before doing that, the project created:

```text
a complete Git bundle backup
a working-tree patch backup
```

Then the raw benchmark audio paths were removed from branch history and the
rewritten branches were pushed.

Fresh verification confirmed that the published branch heads no longer referenced
those raw audio files.

The practical result was:

> Raw benchmark recordings were removed from normal published branch history,
> and the application no longer depends on them for CI or production use.

The exercise also produced a useful release lesson:

```text
"git status is clean"
```

does not mean:

```text
"repository history is clean"
```

Those are separate things and should be checked separately.

Git has an excellent memory.

Occasionally too excellent.

---

## Chapter 20: Where the project is now

Voice Translator started with a very small idea:

```text
Press Start
Speak Russian
Press Stop
Hear English
```

Then do the same in the opposite direction.

The project now contains considerably more machinery behind that interaction.

It has:

- browser microphone recording;
- push-to-talk RU ↔ EN translation;
- batch and streaming STT implementations;
- provider-neutral speech, translation and TTS contracts;
- Google Chirp 3 integration;
- Deepgram integrations;
- DeepL translation;
- OpenAI translation experiments;
- OpenAI streaming TTS;
- OpenAI Realtime experiments;
- reproducible speech benchmarks;
- reproducible translation benchmarks;
- full pipeline benchmarks;
- critical-element evaluation;
- audibility detection;
- a browser Live Pipeline Lab;
- persistent benchmark runs and comparisons;
- public demo quotas;
- short-lived one-time WebSocket tokens;
- server-side profile enforcement;
- Docker production images;
- separate Railway web and WebSocket services;
- a production deployment at `voice.kotov.lt`.

The current public pipeline is intentionally simple:

```text
Russian input
    ↓
Chirp 3 Streaming STANDARD
    ↓
DeepL
    ↓
OpenAI streaming TTS
```

and:

```text
English input
    ↓
Chirp 3 Streaming SHORT
    ↓
DeepL
    ↓
OpenAI streaming TTS
```

Those profiles were not selected because their names sounded impressive.

They were selected because measured quality and latency produced different
trade-offs for Russian and English.

That is probably the most important change in the project.

The architecture is no longer based on:

```text
This API looks good.
```

It is based on:

```text
Record
    ↓
Measure
    ↓
Compare
    ↓
Find the failure
    ↓
Change one variable
    ↓
Measure again
```

There are still clear limitations.

The benchmark dataset is small and controlled.

Real calls contain worse microphones, background noise, compression, accents,
interruptions and unstable networks.

The public product is deliberately push-to-talk.

It does not yet inject translated speech directly into another computer's Teams
session.

It does not yet provide simultaneous interpretation.

It does not yet reproduce the speaker's own voice on the remote side.

Those are future product problems.

And, given the history of this project, each one is perfectly capable of becoming
its own research department.

But the original question has already changed.

At the beginning it was:

> Can I build a Russian-English voice translator?

Now it is:

> How far can the latency be reduced without sacrificing the information that
> actually matters?

That is a much better question.

It is measurable.

It produces engineering decisions.

And it is considerably harder to answer with a marketing page.

The project began with a Start button.

Then came providers.

Then benchmarks.

Then streaming.

Then a browser laboratory.

Then public security.

Then production.

Then Git archaeology.

All because the first recognizer once heard:

```text
Кот Персик
```

and decided:

```text
Код Персик
```

The cat is still a cat.

The project is no longer small.

Thanks again, Persik.
