<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Speech Benchmark Recorder</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-gray-950 text-gray-100">
<div
    class="mx-auto max-w-5xl px-6 py-10"
    x-data="benchmarkRecorder({
        phrases: @js($phrases),
        storeUrl: @js(route('benchmark.recorder.audio.store')),
        csrfToken: @js(csrf_token()),
    })"
>
    <div class="mb-8">
        <h1 class="text-3xl font-semibold">
            Speech Benchmark Recorder
        </h1>

        <p class="mt-2 text-gray-400">
            Canonical speech fixtures for provider benchmarking.
        </p>
    </div>

    <template x-if="currentPhrase">
        <div class="grid gap-8 lg:grid-cols-[1fr_280px]">
            <main class="rounded-xl border border-gray-800 bg-gray-900 p-8">
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <span
                            class="font-mono text-sm text-gray-400"
                            x-text="currentPhrase.id"
                        ></span>

                        <span
                            class="ml-3 rounded bg-gray-800 px-2 py-1 text-xs uppercase text-gray-300"
                            x-text="currentPhrase.category"
                        ></span>
                    </div>

                    <div class="text-sm text-gray-400">
                        <span x-text="currentIndex + 1"></span>
                        /
                        <span x-text="totalCount"></span>
                    </div>
                </div>

                <div
                    class="mb-10 text-2xl leading-relaxed"
                    x-text="currentPhrase.expected"
                ></div>

                <template x-if="currentPhrase.recording_instruction">
                    <div
                        class="mb-8 rounded-lg border border-amber-800 bg-amber-950/30 p-4 text-sm text-amber-200"
                        x-text="currentPhrase.recording_instruction"
                    ></div>
                </template>

                <div class="flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="rounded-lg bg-white px-5 py-3 font-medium text-black disabled:opacity-40"
                        x-show="!isRecording && !audioUrl"
                        x-on:click="startRecording"
                    >
                        Start recording
                    </button>

                    <button
                        type="button"
                        class="rounded-lg bg-red-600 px-5 py-3 font-medium text-white"
                        x-show="isRecording"
                        x-on:click="stopRecording"
                    >
                        Stop
                    </button>
                </div>

                <div
                    class="mt-8"
                    x-show="audioUrl"
                >
                    <audio
                        class="w-full"
                        controls
                        x-bind:src="audioUrl"
                    ></audio>

                    <div class="mt-5 flex gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-700 px-5 py-3"
                            x-on:click="rerecord"
                            x-bind:disabled="isSaving"
                        >
                            Re-record
                        </button>

                        <button
                            type="button"
                            class="rounded-lg bg-green-600 px-5 py-3 font-medium text-white disabled:opacity-40"
                            x-on:click="acceptRecording"
                            x-bind:disabled="isSaving"
                        >
                            <span x-show="!isSaving">
                                Accept
                            </span>

                            <span x-show="isSaving">
                                Saving...
                            </span>
                        </button>
                    </div>
                </div>

                <div
                    class="mt-6 rounded-lg border border-red-900 bg-red-950/30 p-4 text-red-300"
                    x-show="error"
                    x-text="error"
                ></div>
            </main>

            <aside class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="mb-5">
                    <div class="text-sm text-gray-400">
                        Completed
                    </div>

                    <div class="mt-1 text-2xl font-semibold">
                        <span x-text="completedCount"></span>
                        /
                        <span x-text="totalCount"></span>
                    </div>
                </div>

                <div class="max-h-[600px] space-y-1 overflow-y-auto">
                    <template
                        x-for="(phrase, index) in phrases"
                        x-bind:key="phrase.id"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between rounded px-3 py-2 text-left text-sm hover:bg-gray-800"
                            x-bind:class="{
                                'bg-gray-800': index === currentIndex,
                            }"
                            x-on:click="goToPhrase(index)"
                        >
                            <span
                                class="font-mono"
                                x-text="phrase.id"
                            ></span>

                            <span
                                x-show="phrase.completed"
                                class="text-green-400"
                            >
                                ✓
                            </span>

                            <span
                                x-show="!phrase.completed"
                                class="text-gray-600"
                            >
                                ○
                            </span>
                        </button>
                    </template>
                </div>
            </aside>
        </div>
    </template>

    <div
        x-show="allCompleted"
        class="mt-8 rounded-xl border border-green-900 bg-green-950/30 p-5 text-green-300"
    >
        All benchmark recordings are complete.
    </div>
</div>
</body>
</html>