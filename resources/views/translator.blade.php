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

    <title>Voice Translator</title>

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
        class="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-12">
        <section class="w-full">
            <header class="mb-10">
                <h1 class="text-4xl font-semibold">
                    Voice Translator
                </h1>

                <p class="mt-3 text-gray-400">
                    Record your speech and convert it to text.
                </p>
            </header>

            <div
                x-data="recorder({
                    transcribeUrl: @js(route('transcribe')),
                })"
                class="rounded-2xl border border-gray-800 bg-gray-900 p-6">
                <div class="mb-6">
                    <label
                        for="source-language"
                        class="mb-2 block text-sm text-gray-400">
                        Source language
                    </label>

                    <select
                        id="source-language"
                        x-model="sourceLanguage"
                        x-bind:disabled="isRecording || isTranscribing"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-4 py-3 text-white outline-none disabled:cursor-not-allowed disabled:opacity-50">
                        <option value="en">
                            English
                        </option>

                        <option value="ru">
                            Russian
                        </option>
                    </select>
                </div>

                <div class="mb-6">
                    <p class="text-sm text-gray-400">
                        Status
                    </p>

                    <p
                        class="mt-1 text-lg font-medium"
                        x-text="status"></p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="rounded-lg bg-white px-5 py-3 font-medium text-black disabled:cursor-not-allowed disabled:opacity-50"
                        x-on:click="startRecording"
                        x-bind:disabled="isRecording || isTranscribing">
                        Start recording
                    </button>

                    <button
                        type="button"
                        class="rounded-lg border border-gray-700 px-5 py-3 font-medium disabled:cursor-not-allowed disabled:opacity-50"
                        x-on:click="stopRecording"
                        x-bind:disabled="!isRecording">
                        Stop recording
                    </button>
                </div>

                <div
                    class="mt-6"
                    x-show="audioUrl"
                    x-cloak>
                    <p class="mb-2 text-sm text-gray-400">
                        Recorded audio
                    </p>

                    <audio
                        class="w-full"
                        controls
                        x-bind:src="audioUrl"></audio>

                    <p
                        class="mt-2 text-xs text-gray-500"
                        x-show="mimeType"
                        x-text="mimeType"></p>
                </div>

                <div
                    class="mt-6 rounded-xl border border-gray-800 bg-gray-950 p-4"
                    x-show="isTranscribing"
                    x-cloak>
                    <p class="text-sm text-gray-400">
                        Sending audio to speech recognition...
                    </p>
                </div>

                <div
                    class="mt-6 rounded-xl border border-gray-800 bg-gray-950 p-4"
                    x-show="transcription"
                    x-cloak>
                    <p class="mb-2 text-sm text-gray-400">
                        Recognized text
                    </p>

                    <p
                        class="whitespace-pre-wrap text-lg leading-relaxed"
                        x-text="transcription"></p>
                </div>

                <div
                    class="mt-6 rounded-xl border border-red-900/50 bg-red-950/30 p-4 text-sm text-red-400"
                    role="alert"
                    x-show="error"
                    x-cloak>
                    <p x-text="error"></p>
                </div>
            </div>
        </section>
    </main>
</body>

</html>