<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <meta
        name="csrf-token"
        content="{{ csrf_token() }}">

    <title>Live Pipeline Lab</title>

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    @vite([
    'resources/css/app.css',
    'resources/js/app.js',
    ])
</head>

<body class="min-h-screen bg-gray-950 text-white">
    <main class="mx-auto max-w-5xl px-6 py-12">
        <header class="mb-10 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="mb-2 text-sm font-medium uppercase tracking-wider text-gray-500">
                    VT-19
                </p>

                <h1 class="text-4xl font-semibold">
                    Live Pipeline Lab
                </h1>

                <p class="mt-3 text-gray-400">
                    Stream microphone audio through Chirp 3, DeepL and OpenAI TTS and measure real post-STOP latency.
                </p>
            </div>

            <a
                href="{{ route('live.history') }}"
                class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:border-gray-500 hover:text-white">
                History
            </a>
        </header>

        <section
            x-data="liveRecorder({
                profile: @js('chirp3-streaming-standard-deepl-openai'),
                storeUrl: @js(route('live.runs.store')),
            })"
            class="rounded-2xl border border-gray-800 bg-gray-900 p-6">

            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <label
                        for="live-source-language"
                        class="mb-2 block text-sm text-gray-400">
                        Source language
                    </label>

                    <select
                        id="live-source-language"
                        x-model="sourceLanguage"
                        x-bind:disabled="isBusy"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-4 py-3 text-white outline-none disabled:cursor-not-allowed disabled:opacity-50">

                        <option value="ru">
                            Russian
                        </option>

                        <option value="en">
                            English
                        </option>
                    </select>
                </div>

                <div>
                    <p class="mb-2 text-sm text-gray-400">
                        Direction
                    </p>

                    <div class="rounded-lg border border-gray-700 bg-gray-950 px-4 py-3 text-white">
                        <span
                            x-text="sourceLanguage.toUpperCase()"></span>

                        <span class="mx-2 text-gray-500">
                            →
                        </span>

                        <span
                            x-text="targetLanguage.toUpperCase()"></span>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <label
                    for="live-profile"
                    class="mb-2 block text-sm text-gray-400">
                    Pipeline
                </label>

                <select
                    id="live-profile"
                    x-model="profile"
                    x-bind:disabled="isBusy"
                    class="w-full rounded-lg border border-gray-700 bg-gray-950 px-4 py-3 text-white outline-none disabled:cursor-not-allowed disabled:opacity-50">

                    <option value="chirp3-deepl-openai">
                        Chirp 3 Batch → DeepL → OpenAI TTS
                    </option>

                    <option value="chirp3-streaming-standard-deepl-openai">
                        Chirp 3 Streaming STANDARD → DeepL → OpenAI TTS
                    </option>

                    <option value="chirp3-streaming-short-deepl-openai">
                        Chirp 3 Streaming SHORT → DeepL → OpenAI TTS
                    </option>

                    <option value="flux-deepl-openai">
                        Deepgram Flux → DeepL → OpenAI TTS
                    </option>
                </select>
            </div>

            <div class="mt-6 rounded-xl border border-gray-800 bg-gray-950 p-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <p class="text-xs uppercase tracking-wider text-gray-500">
                            Status
                        </p>

                        <p
                            class="mt-1 font-medium"
                            x-text="status"></p>
                    </div>

                    <div>
                        <p class="text-xs uppercase tracking-wider text-gray-500">
                            WebSocket
                        </p>

                        <p
                            class="mt-1 break-all font-mono text-sm text-gray-300"
                            x-text="wsUrl"></p>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <button
                    type="button"
                    x-on:click="startRecording"
                    x-bind:disabled="isBusy"
                    class="rounded-lg bg-white px-5 py-3 font-medium text-black disabled:cursor-not-allowed disabled:opacity-50">
                    START
                </button>

                <button
                    type="button"
                    x-on:click="stopRecording"
                    x-bind:disabled="!isRecording"
                    class="rounded-lg border border-gray-700 px-5 py-3 font-medium disabled:cursor-not-allowed disabled:opacity-50">
                    STOP
                </button>
            </div>

            <div
                class="mt-6"
                x-show="transcription"
                x-cloak>

                <p class="mb-2 text-sm text-gray-400">
                    Source transcript
                </p>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p
                        class="whitespace-pre-wrap text-lg leading-relaxed"
                        x-text="transcription"></p>
                </div>
            </div>

            <div
                class="mt-6"
                x-show="translatedText"
                x-cloak>

                <p class="mb-2 text-sm text-gray-400">
                    Translated text
                </p>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p
                        class="whitespace-pre-wrap text-lg leading-relaxed"
                        x-text="translatedText"></p>
                </div>
            </div>

            <div
                class="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3"
                x-show="
                    serverStopToSttMs !== null
                    || browserStopToFirstAudiblePlaybackMs !== null
                "
                x-cloak>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">
                        Server STOP → STT
                    </p>

                    <p class="mt-2 text-2xl font-semibold">
                        <span x-text="serverStopToSttMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">
                        STT → translation
                    </p>

                    <p class="mt-2 text-2xl font-semibold">
                        <span x-text="translationMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">
                        Browser STOP → translated
                    </p>

                    <p class="mt-2 text-2xl font-semibold">
                        <span x-text="browserStopToTranslatedMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">
                        TTS → first audio
                    </p>

                    <p class="mt-2 text-2xl font-semibold">
                        <span x-text="ttsFirstAudioMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">
                        Server STOP → first TTS audio
                    </p>

                    <p class="mt-2 text-2xl font-semibold">
                        <span x-text="serverStopToFirstTtsAudioMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">
                        Browser STOP → first audio received
                    </p>

                    <p class="mt-2 text-2xl font-semibold">
                        <span x-text="browserStopToFirstAudioReceivedMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border border-emerald-800 bg-emerald-950/30 p-4 lg:col-span-2">
                    <p class="text-xs uppercase tracking-wider text-emerald-500">
                        Browser STOP → first audible playback
                    </p>

                    <p class="mt-2 text-3xl font-semibold">
                        <span x-text="browserStopToFirstAudiblePlaybackMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">
                        TTS generation
                    </p>

                    <p class="mt-2 text-2xl font-semibold">
                        <span x-text="ttsDurationMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">
                        Browser STOP → server completed
                    </p>

                    <p class="mt-2 text-2xl font-semibold">
                        <span x-text="browserStopToCompletedMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">
                        Generated speech duration
                    </p>

                    <p class="mt-2 text-2xl font-semibold">
                        <span x-text="translatedAudioDurationMs"></span>

                        <span class="text-sm font-normal text-gray-500">
                            ms
                        </span>
                    </p>
                </div>
            </div>

            <div
                class="mt-6 rounded-xl border border-gray-800 bg-gray-950 p-4"
                x-show="runId !== null"
                x-cloak>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-medium">
                            Manual quality review
                        </p>

                        <p class="mt-1 text-xs text-gray-500">
                            Rate the saved run after checking the transcript and translation.
                        </p>
                    </div>

                    <p class="text-xs text-gray-500">
                        Run #<span x-text="runId"></span>
                    </p>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <template
                        x-for="rating in ['correct', 'acceptable', 'wrong']"
                        x-bind:key="rating">

                        <button
                            type="button"
                            x-on:click="selectQualityRating(rating)"
                            x-bind:disabled="isSavingReview"
                            x-bind:class="qualityRating === rating
                                ? 'border-white bg-white text-black'
                                : 'border-gray-700 bg-gray-900 text-white'"
                            class="rounded-lg border px-4 py-2 text-sm font-medium capitalize disabled:cursor-not-allowed disabled:opacity-50"
                            x-text="rating"></button>
                    </template>
                </div>

                <div
                    class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                    x-show="qualityRating === 'wrong'"
                    x-cloak>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" value="stt_wrong" x-model="qualityIssues">
                        STT wrong
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" value="translation_wrong" x-model="qualityIssues">
                        Translation wrong
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" value="meaning_changed" x-model="qualityIssues">
                        Meaning changed
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" value="name_place_corrupted" x-model="qualityIssues">
                        Name/place corrupted
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" value="number_time_wrong" x-model="qualityIssues">
                        Number/time wrong
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" value="other" x-model="qualityIssues">
                        Other
                    </label>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        x-on:click="saveQualityReview"
                        x-bind:disabled="!qualityRating || isSavingReview"
                        class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50">
                        <span x-show="!isSavingReview">Save review</span>
                        <span x-show="isSavingReview">Saving…</span>
                    </button>

                    <span
                        class="text-sm text-emerald-400"
                        x-show="reviewSaved"
                        x-cloak>
                        Saved
                    </span>

                    <span
                        class="text-sm text-red-400"
                        x-show="reviewError"
                        x-text="reviewError"
                        x-cloak></span>
                </div>
            </div>

            <div
                class="mt-6 rounded-xl border border-red-900/50 bg-red-950/30 p-4 text-sm text-red-400"
                role="alert"
                x-show="error"
                x-cloak>

                <p x-text="error"></p>
            </div>
        </section>
        <section
            x-data="compareRecorder({
                storeUrl: @js(route('live.runs.store')),
            })"
            class="mt-10 rounded-2xl border border-gray-800 bg-gray-900 p-6">

            <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-medium uppercase tracking-wider text-gray-500">
                        Compare mode
                    </p>

                    <h2 class="mt-2 text-2xl font-semibold">
                        Compare pipelines
                    </h2>

                    <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-400">
                        Record once. The exact same WebM chunks are replayed through the selected pipelines for every run.
                    </p>
                </div>

                <div
                    class="text-sm text-gray-400"
                    x-show="recordedDurationMs !== null"
                    x-cloak>

                    Recorded audio:

                    <span
                        class="font-medium text-white"
                        x-text="formatDuration(recordedDurationMs)"></span>
                </div>
            </div>

            <div class="mt-6 grid gap-6 md:grid-cols-2">
                <div>
                    <label
                        for="compare-source-language"
                        class="mb-2 block text-sm text-gray-400">
                        Source language
                    </label>

                    <select
                        id="compare-source-language"
                        x-model="sourceLanguage"
                        x-bind:disabled="isBusy"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-4 py-3 text-white outline-none disabled:cursor-not-allowed disabled:opacity-50">

                        <option value="ru">
                            Russian
                        </option>

                        <option value="en">
                            English
                        </option>
                    </select>
                </div>

                <div>
                    <p class="mb-2 text-sm text-gray-400">
                        Runs per pipeline
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <template x-for="option in runOptions" x-bind:key="option">
                            <button
                                type="button"
                                x-on:click="runCount = option"
                                x-bind:disabled="isBusy"
                                x-bind:class="runCount === option
                                    ? 'border-white bg-white text-black'
                                    : 'border-gray-700 bg-gray-950 text-white'"
                                class="rounded-lg border px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50"
                                x-text="option"></button>
                        </template>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm text-gray-400">
                            Pipelines
                        </p>

                        <p class="mt-1 text-xs text-gray-500">
                            <span x-text="selectedProfileCount"></span>
                            selected ·
                            <span x-text="plannedRunCount"></span>
                            total pipeline runs
                        </p>
                    </div>

                    <div class="flex gap-2">
                        <button
                            type="button"
                            x-on:click="selectAllProfiles"
                            x-bind:disabled="isBusy"
                            class="rounded-lg border border-gray-700 px-3 py-2 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50">
                            Select all
                        </button>

                        <button
                            type="button"
                            x-on:click="clearProfiles"
                            x-bind:disabled="isBusy"
                            class="rounded-lg border border-gray-700 px-3 py-2 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50">
                            Clear
                        </button>
                    </div>
                </div>

                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <template x-for="profile in profiles" x-bind:key="profile.name">
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-700 bg-gray-950 px-4 py-3">
                            <input
                                type="checkbox"
                                x-model="profile.selected"
                                x-bind:disabled="isBusy"
                                class="h-4 w-4 disabled:cursor-not-allowed disabled:opacity-50">

                            <span class="text-sm" x-text="profile.label"></span>
                        </label>
                    </template>
                </div>
            </div>

            <div class="mt-6 rounded-xl border border-gray-800 bg-gray-950 p-4">
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <p class="text-xs uppercase tracking-wider text-gray-500">
                            Status
                        </p>

                        <p
                            class="mt-1 font-medium"
                            x-text="status"></p>
                    </div>

                    <div>
                        <p class="text-xs uppercase tracking-wider text-gray-500">
                            Current pipeline
                        </p>

                        <p
                            class="mt-1 font-medium"
                            x-text="progressText || '—'"></p>
                    </div>

                    <div>
                        <p class="text-xs uppercase tracking-wider text-gray-500">
                            Progress
                        </p>

                        <p class="mt-1 font-medium">
                            <span x-text="completedRunCount"></span>
                            /
                            <span x-text="plannedRunCount"></span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <button
                    type="button"
                    x-on:click="startRecording"
                    x-bind:disabled="isBusy || selectedProfileCount === 0"
                    class="rounded-lg bg-white px-5 py-3 font-medium text-black disabled:cursor-not-allowed disabled:opacity-50">
                    START RECORDING
                </button>

                <button
                    type="button"
                    x-on:click="stopRecording"
                    x-bind:disabled="!isRecording"
                    class="rounded-lg border border-gray-700 px-5 py-3 font-medium disabled:cursor-not-allowed disabled:opacity-50">
                    STOP & COMPARE
                </button>
            </div>

            <div
                class="mt-8 overflow-x-auto"
                x-show="comparisonResults.length > 0"
                x-cloak>

                <h3 class="mb-3 text-lg font-semibold">
                    Summary
                </h3>

                <table class="min-w-[1050px] border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-700 text-xs uppercase tracking-wider text-gray-500">
                            <th class="px-3 py-3">Pipeline</th>
                            <th class="px-3 py-3">Success</th>
                            <th class="px-3 py-3">STT median</th>
                            <th class="px-3 py-3">Input end → translated median</th>
                            <th class="px-3 py-3">Audible received median</th>
                            <th class="px-3 py-3">Audible min</th>
                            <th class="px-3 py-3">Audible max</th>
                            <th class="px-3 py-3">Transcript consistency</th>
                        </tr>
                    </thead>

                    <tbody>
                        <template
                            x-for="result in comparisonResults"
                            x-bind:key="result.profile">

                            <tr class="border-b border-gray-800">
                                <td
                                    class="whitespace-nowrap px-3 py-4 font-medium"
                                    x-text="result.label"></td>

                                <td
                                    class="whitespace-nowrap px-3 py-4"
                                    x-text="`${result.successCount}/${result.totalRuns}`"></td>

                                <td
                                    class="whitespace-nowrap px-3 py-4"
                                    x-text="formatMs(result.sttMedianMs)"></td>

                                <td
                                    class="whitespace-nowrap px-3 py-4"
                                    x-text="formatMs(result.translatedMedianMs)"></td>

                                <td
                                    class="whitespace-nowrap px-3 py-4 font-semibold text-emerald-400"
                                    x-text="formatMs(result.audibleMedianMs)"></td>

                                <td
                                    class="whitespace-nowrap px-3 py-4"
                                    x-text="formatMs(result.audibleMinMs)"></td>

                                <td
                                    class="whitespace-nowrap px-3 py-4"
                                    x-text="formatMs(result.audibleMaxMs)"></td>

                                <td
                                    class="whitespace-nowrap px-3 py-4"
                                    x-text="result.transcriptConsistency"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <details
                class="mt-8 rounded-xl border border-gray-800 bg-gray-950"
                x-show="comparisonRuns.length > 0"
                x-cloak>

                <summary class="cursor-pointer px-4 py-3 font-medium">
                    Individual runs
                </summary>

                <div class="overflow-x-auto border-t border-gray-800">
                    <table class="min-w-[2050px] border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-800 text-xs uppercase tracking-wider text-gray-500">
                                <th class="px-3 py-3">Run</th>
                                <th class="px-3 py-3">Pipeline</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="min-w-72 px-3 py-3">Transcript</th>
                                <th class="min-w-72 px-3 py-3">Translation</th>
                                <th class="px-3 py-3">Input end → STT</th>
                                <th class="px-3 py-3">Input end → translated</th>
                                <th class="px-3 py-3">Audible received</th>
                                <th class="px-3 py-3">TTS generation</th>
                                <th class="min-w-80 px-3 py-3">Quality review</th>
                            </tr>
                        </thead>

                        <tbody>
                            <template
                                x-for="(run, index) in comparisonRuns"
                                x-bind:key="`${run.runNumber}-${run.profile}-${index}`">

                                <tr class="border-b border-gray-800 align-top">
                                    <td
                                        class="px-3 py-4"
                                        x-text="run.runNumber"></td>

                                    <td class="px-3 py-4 font-medium">
                                        <p x-text="run.label"></p>

                                        <p
                                            class="mt-2 max-w-64 text-xs text-red-400"
                                            x-show="run.error"
                                            x-text="run.error"></p>
                                    </td>

                                    <td class="px-3 py-4">
                                        <span
                                            x-bind:class="run.status === 'completed'
                                                ? 'text-emerald-400'
                                                : 'text-red-400'"
                                            x-text="run.status"></span>
                                    </td>

                                    <td
                                        class="px-3 py-4 leading-relaxed text-gray-300"
                                        x-text="run.transcript || '—'"></td>

                                    <td
                                        class="px-3 py-4 leading-relaxed text-gray-300"
                                        x-text="run.translatedText || '—'"></td>

                                    <td
                                        class="whitespace-nowrap px-3 py-4"
                                        x-text="formatMs(run.serverStopToSttMs)"></td>

                                    <td
                                        class="whitespace-nowrap px-3 py-4"
                                        x-text="formatMs(run.serverStopToTranslationMs)"></td>

                                    <td
                                        class="whitespace-nowrap px-3 py-4 font-semibold text-emerald-400"
                                        x-text="formatMs(run.browserInputEndToFirstAudibleAudioReceivedMs)"></td>

                                    <td
                                        class="whitespace-nowrap px-3 py-4"
                                        x-text="formatMs(run.ttsDurationMs)"></td>
                                    <td class="px-3 py-4">
                                        <div
                                            x-show="run.persistedRunId !== null"
                                            x-cloak>

                                            <div class="flex flex-wrap gap-1">
                                                <template
                                                    x-for="rating in ['correct', 'acceptable', 'wrong']"
                                                    x-bind:key="`${run.persistedRunId}-${rating}`">

                                                    <button
                                                        type="button"
                                                        x-on:click="selectRunQualityRating(run, rating)"
                                                        x-bind:disabled="isComparing || run.isSavingReview"
                                                        x-bind:class="run.qualityRating === rating
                                                            ? 'border-white bg-white text-black'
                                                            : 'border-gray-700 bg-gray-900 text-white'"
                                                        class="rounded border px-2 py-1 text-xs font-medium capitalize disabled:cursor-not-allowed disabled:opacity-50"
                                                        x-text="rating"></button>
                                                </template>
                                            </div>

                                            <div
                                                class="mt-3 grid gap-1"
                                                x-show="run.qualityRating === 'wrong'"
                                                x-cloak>

                                                <label class="flex items-center gap-2 text-xs text-gray-300">
                                                    <input type="checkbox" value="stt_wrong" x-model="run.qualityIssues">
                                                    STT wrong
                                                </label>

                                                <label class="flex items-center gap-2 text-xs text-gray-300">
                                                    <input type="checkbox" value="translation_wrong" x-model="run.qualityIssues">
                                                    Translation wrong
                                                </label>

                                                <label class="flex items-center gap-2 text-xs text-gray-300">
                                                    <input type="checkbox" value="meaning_changed" x-model="run.qualityIssues">
                                                    Meaning changed
                                                </label>

                                                <label class="flex items-center gap-2 text-xs text-gray-300">
                                                    <input type="checkbox" value="name_place_corrupted" x-model="run.qualityIssues">
                                                    Name/place corrupted
                                                </label>

                                                <label class="flex items-center gap-2 text-xs text-gray-300">
                                                    <input type="checkbox" value="number_time_wrong" x-model="run.qualityIssues">
                                                    Number/time wrong
                                                </label>

                                                <label class="flex items-center gap-2 text-xs text-gray-300">
                                                    <input type="checkbox" value="other" x-model="run.qualityIssues">
                                                    Other
                                                </label>
                                            </div>

                                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                                <button
                                                    type="button"
                                                    x-on:click="saveRunQualityReview(run)"
                                                    x-bind:disabled="!run.qualityRating || isComparing || run.isSavingReview"
                                                    class="rounded border border-gray-700 px-2 py-1 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50">
                                                    <span x-show="!run.isSavingReview">Save</span>
                                                    <span x-show="run.isSavingReview">Saving…</span>
                                                </button>

                                                <span
                                                    class="text-xs text-emerald-400"
                                                    x-show="run.reviewSaved"
                                                    x-cloak>
                                                    Saved
                                                </span>
                                            </div>

                                            <p
                                                class="mt-2 max-w-72 text-xs text-red-400"
                                                x-show="run.reviewError"
                                                x-text="run.reviewError"
                                                x-cloak></p>
                                        </div>

                                        <p
                                            class="text-xs text-gray-500"
                                            x-show="run.persistedRunId === null">
                                            Not persisted
                                        </p>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </details>

            <div
                class="mt-6 rounded-xl border border-red-900/50 bg-red-950/30 p-4 text-sm text-red-400"
                role="alert"
                x-show="error"
                x-cloak>

                <p x-text="error"></p>
            </div>
        </section>
    </main>
</body>

</html>