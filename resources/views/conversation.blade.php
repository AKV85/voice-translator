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

    <title>Conversation | Voice Translator</title>

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
    <main class="mx-auto max-w-3xl px-6 py-12">

        <header class="mb-10">
            <p class="mb-3 text-sm font-medium uppercase tracking-wider text-gray-500">
                VT-21 · Experimental
            </p>

            <h1 class="text-4xl font-semibold">
                Live Conversation
            </h1>

            <p class="mt-4 text-gray-400">
                Connect two participants for a Russian ↔ English
                conversation with speech translation.
            </p>
        </header>

        <section
            x-data="conversationRecorder({
                latencyDebug: @js((bool) config('conversation.latency_debug_enabled', false)),
                createUrl: @js(route('conversation.create')),
                joinUrl: @js(route('conversation.join')),
            })"
            class="rounded-2xl border border-gray-800 bg-gray-900 p-6 sm:p-8">

            <div
                x-show="!code"
                class="grid gap-6 md:grid-cols-2">

                <div class="rounded-xl border border-gray-700 bg-gray-950 p-5">
                    <h2 class="text-lg font-medium">
                        Start a conversation
                    </h2>

                    <p class="mt-2 text-sm text-gray-400">
                        Create a room and share its code with the other participant.
                    </p>

                    <button
                        type="button"
                        x-on:click="createRoom()"
                        x-bind:disabled="busy"
                        class="mt-6 w-full rounded-lg bg-white px-4 py-3 font-medium text-black disabled:opacity-50">
                        Create room
                    </button>
                </div>

                <div class="rounded-xl border border-gray-700 bg-gray-950 p-5">
                    <h2 class="text-lg font-medium">
                        Join a conversation
                    </h2>

                    <p class="mt-2 text-sm text-gray-400">
                        Enter the room code received from the host.
                    </p>

                    <label
                        for="conversation-code"
                        class="mt-5 block text-sm text-gray-400">
                        Room code
                    </label>

                    <input
                        id="conversation-code"
                        type="text"
                        maxlength="8"
                        autocomplete="off"
                        x-model="joinCode"
                        x-on:input="joinCode = joinCode.toUpperCase()"
                        class="mt-2 w-full rounded-lg border border-gray-700 bg-gray-900 px-4 py-3 font-mono uppercase tracking-widest text-white outline-none focus:border-gray-400">

                    <button
                        type="button"
                        x-on:click="joinRoom()"
                        x-bind:disabled="busy || joinCode.trim().length !== 8"
                        class="mt-4 w-full rounded-lg border border-gray-600 px-4 py-3 font-medium text-white disabled:opacity-50">
                        Join room
                    </button>
                </div>
            </div>

            <div x-show="code" x-cloak>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-gray-400">
                            Room code
                        </p>

                        <p
                            class="mt-2 font-mono text-3xl font-semibold tracking-widest"
                            x-text="code"></p>
                    </div>

                    <div class="text-right">
                        <p class="text-sm text-gray-400">
                            Your role
                        </p>

                        <p
                            class="mt-2 font-medium capitalize"
                            x-text="role || 'Connecting'"></p>
                    </div>
                </div>

                <div class="mt-8 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-gray-700 bg-gray-950 p-5">
                        <p class="text-sm text-gray-400">
                            WebSocket
                        </p>

                        <p
                            class="mt-2 font-medium"
                            x-text="connected ? 'Connected' : 'Disconnected'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-700 bg-gray-950 p-5">
                        <p class="text-sm text-gray-400">
                            Other participant
                        </p>

                        <p
                            class="mt-2 font-medium"
                            x-text="peerConnected ? 'Online' : 'Waiting'"></p>
                    </div>
                </div>

                <div class="mt-8 rounded-xl border border-gray-700 bg-gray-950 p-5">
                    <p class="text-sm text-gray-400">
                        Your language
                    </p>

                    <p
                        class="mt-1 text-lg font-medium"
                        x-text="role === 'host'
                            ? 'Russian → English'
                            : 'English → Russian'"></p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <button
                            type="button"
                            x-on:click="startSpeaking()"
                            x-bind:disabled="!canSpeak"
                            class="rounded-lg bg-white px-6 py-3 font-medium text-black disabled:cursor-not-allowed disabled:opacity-40">
                            Start speaking
                        </button>

                        <button
                            type="button"
                            x-on:click="stopSpeaking()"
                            x-bind:disabled="!isRecording || isStopping"
                            class="rounded-lg border border-red-700 px-6 py-3 font-medium text-red-300 disabled:cursor-not-allowed disabled:opacity-40">
                            Stop speaking
                        </button>
                    </div>

                    <p
                        class="mt-4 text-sm text-gray-400"
                        x-text="isRecording
                            ? 'Microphone is recording'
                            : turnBusy
                                ? 'Conversation is processing'
                                : 'Ready to speak'"></p>
                </div>

                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <button
                        type="button"
                        x-on:click="enablePlayback()"
                        x-bind:disabled="audioEnabled"
                        class="rounded-lg border border-gray-600 px-5 py-3 text-sm font-medium text-white disabled:opacity-50">
                        <span x-text="audioEnabled
                            ? 'Speaker enabled'
                            : 'Enable speaker'"></span>
                    </button>

                    <p class="text-sm text-gray-400">
                        Enable playback before receiving translated speech.
                    </p>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    <div class="rounded-xl border border-gray-700 bg-gray-950 p-5">
                        <h2 class="font-medium">
                            Original speech
                        </h2>

                        <p
                            class="mt-3 min-h-20 whitespace-pre-wrap text-sm text-gray-300"
                            x-text="transcription || 'Waiting for speech...'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-700 bg-gray-950 p-5">
                        <h2 class="font-medium">
                            Translation
                        </h2>

                        <p
                            class="mt-3 min-h-20 whitespace-pre-wrap text-sm text-gray-300"
                            x-text="translatedText || 'Waiting for translation...'"></p>

                        <p
                            class="mt-3 text-xs text-gray-500"
                            x-text="audioStatus"></p>
                    </div>
                </div>

                <button
                    type="button"
                    x-on:click="disconnect()"
                    x-bind:disabled="!connected"
                    class="mt-6 rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 disabled:opacity-50">
                    Disconnect
                </button>
            </div>

            <p
                class="mt-6 text-sm text-gray-400"
                aria-live="polite"
                x-text="status"></p>

            <p
                x-show="error"
                x-cloak
                class="mt-4 rounded-lg border border-red-900 bg-red-950/40 p-4 text-sm text-red-300"
                role="alert"
                x-text="error"></p>
        </section>

        <a
            href="{{ route('live') }}"
            class="mt-8 inline-block text-sm text-gray-400 hover:text-white">
            ← Back to Live Lab
        </a>
    </main>
</body>

</html>
