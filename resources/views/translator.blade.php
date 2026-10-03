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

    <title>Voice Translator Demo</title>

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
    <main
        class="mx-auto flex min-h-screen max-w-4xl items-center px-6 py-12">

        <section
            x-data="publicDemoRecorder({
                sessionUrl: @js(route('demo.session.store')),
                wsUrl: @js(config('public_demo.websocket_url')),
            })"
            class="w-full">

            <header class="mb-10">
                <p class="mb-3 text-sm font-medium uppercase tracking-[0.2em] text-gray-500">
                    Public demo
                </p>

                <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
                    Voice Translator
                </h1>

                <p class="mt-4 max-w-2xl text-lg leading-8 text-gray-400">
                    Speak in Russian or English. Your speech is recognized,
                    translated and played back in the other language.
                </p>
            </header>

            <div class="rounded-2xl border border-gray-800 bg-gray-900 p-6 sm:p-8">

                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        type="button"
                        x-on:click="setSourceLanguage('ru')"
                        x-bind:disabled="isBusy"
                        x-bind:class="
                            sourceLanguage === 'ru'
                                ? 'border-white bg-white text-black'
                                : 'border-gray-700 bg-gray-950 text-gray-300'
                        "
                        class="rounded-xl border px-5 py-4 text-left transition disabled:cursor-not-allowed disabled:opacity-50">

                        <span class="block text-xs uppercase tracking-wider opacity-60">
                            Speak
                        </span>

                        <span class="mt-1 block text-lg font-medium">
                            Russian → English
                        </span>
                    </button>

                    <button
                        type="button"
                        x-on:click="setSourceLanguage('en')"
                        x-bind:disabled="isBusy"
                        x-bind:class="
                            sourceLanguage === 'en'
                                ? 'border-white bg-white text-black'
                                : 'border-gray-700 bg-gray-950 text-gray-300'
                        "
                        class="rounded-xl border px-5 py-4 text-left transition disabled:cursor-not-allowed disabled:opacity-50">

                        <span class="block text-xs uppercase tracking-wider opacity-60">
                            Speak
                        </span>

                        <span class="mt-1 block text-lg font-medium">
                            English → Russian
                        </span>
                    </button>
                </div>

                <div class="mt-8 rounded-xl border border-gray-800 bg-gray-950 p-5">
                    <div class="flex items-start justify-between gap-6">
                        <div>
                            <p class="text-xs uppercase tracking-wider text-gray-500">
                                Status
                            </p>

                            <p
                                class="mt-2 text-lg font-medium"
                                x-text="status"></p>
                        </div>

                        <div
                            x-show="isRecording"
                            x-cloak
                            class="text-right">

                            <p class="text-xs uppercase tracking-wider text-gray-500">
                                Time left
                            </p>

                            <p class="mt-2 font-mono text-lg">
                                <span x-text="remainingSeconds"></span>s
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <button
                        type="button"
                        x-on:click="startRecording"
                        x-bind:disabled="isBusy"
                        class="rounded-lg bg-white px-6 py-3 font-medium text-black transition hover:bg-gray-200 disabled:cursor-not-allowed disabled:opacity-40">

                        Start speaking
                    </button>

                    <button
                        type="button"
                        x-on:click="stopRecording"
                        x-bind:disabled="!isRecording"
                        class="rounded-lg border border-gray-700 px-6 py-3 font-medium text-white transition hover:border-gray-500 disabled:cursor-not-allowed disabled:opacity-40">

                        Stop
                    </button>
                </div>

                <p class="mt-4 text-sm text-gray-500">
                    Maximum recording length:
                    <span x-text="maxAudioSeconds"></span>
                    seconds. The translated voice plays automatically.
                </p>

                <div
                    x-show="transcription"
                    x-cloak
                    class="mt-8">

                    <p class="mb-2 text-xs uppercase tracking-wider text-gray-500">
                        <span x-text="sourceLanguageLabel"></span>
                    </p>

                    <div class="rounded-xl border border-gray-800 bg-gray-950 p-5">
                        <p
                            class="whitespace-pre-wrap text-lg leading-relaxed text-gray-200"
                            x-text="transcription"></p>
                    </div>
                </div>

                <div
                    x-show="translatedText"
                    x-cloak
                    class="mt-5">

                    <p class="mb-2 text-xs uppercase tracking-wider text-gray-500">
                        <span x-text="targetLanguageLabel"></span>
                    </p>

                    <div class="rounded-xl border border-gray-700 bg-gray-950 p-5">
                        <p
                            class="whitespace-pre-wrap text-xl font-medium leading-relaxed text-white"
                            x-text="translatedText"></p>
                    </div>
                </div>

                <div
                    x-show="error"
                    x-cloak
                    role="alert"
                    class="mt-6 rounded-xl border border-red-900/60 bg-red-950/30 p-4 text-sm text-red-300">

                    <p x-text="error"></p>
                </div>

                <div
                    x-show="
                        hourlyRemaining !== null
                        || dailyRemaining !== null
                    "
                    x-cloak
                    class="mt-6 border-t border-gray-800 pt-5 text-xs text-gray-500">

                    <span x-show="hourlyRemaining !== null">
                        Hourly attempts remaining:
                        <span
                            class="text-gray-300"
                            x-text="hourlyRemaining"></span>
                    </span>

                    <span
                        x-show="
                            hourlyRemaining !== null
                            && dailyRemaining !== null
                        "
                        class="mx-2">
                        ·
                    </span>

                    <span x-show="dailyRemaining !== null">
                        Daily attempts remaining:
                        <span
                            class="text-gray-300"
                            x-text="dailyRemaining"></span>
                    </span>
                </div>
            </div>

            <footer class="mt-6 text-sm leading-6 text-gray-600">
                Public portfolio demo with server-side usage limits.
                Audio is streamed only for the active translation session.
            </footer>
        </section>
    </main>
</body>

</html>
