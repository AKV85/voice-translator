<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

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
        <header class="mb-10">
            <p class="mb-2 text-sm font-medium uppercase tracking-wider text-gray-500">
                VT-19
            </p>

            <h1 class="text-4xl font-semibold">
                Live Pipeline Lab
            </h1>

            <p class="mt-3 text-gray-400">
                Stream microphone audio through Chirp 3, DeepL and OpenAI TTS and measure real post-STOP latency.
            </p>
        </header>

        <section
            x-data="liveRecorder({
                profile: @js('chirp3-streaming-standard-deepl-openai'),
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
                <p class="mb-2 text-sm text-gray-400">
                    Pipeline
                </p>

                <div class="rounded-lg border border-gray-700 bg-gray-950 px-4 py-3 text-sm">
                    Chirp 3 Streaming STANDARD → DeepL → OpenAI TTS
                </div>
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