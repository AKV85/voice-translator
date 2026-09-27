<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Live Pipeline History</title>

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
        x-data="liveHistory({
            endpoint: @js(route('live.history.runs.index')),
            qualityBaseUrl: @js(url('/live/runs')),
            csrfToken: @js(csrf_token()),
        })"
        class="mx-auto max-w-7xl px-6 py-12">

        <header class="mb-10 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="mb-2 text-sm font-medium uppercase tracking-wider text-gray-500">
                    VT-19
                </p>

                <h1 class="text-4xl font-semibold">
                    Live Pipeline History
                </h1>

                <p class="mt-3 text-gray-400">
                    Inspect saved Live and Compare runs, quality reviews and diagnostics.
                </p>
            </div>

            <a
                href="{{ route('live') }}"
                class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:border-gray-500 hover:text-white">
                Back to Live Lab
            </a>
        </header>

        <section class="rounded-2xl border border-gray-800 bg-gray-900 p-6">
            <form
                x-on:submit.prevent="applyFilters"
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">

                <div>
                    <label
                        for="history-direction"
                        class="mb-2 block text-sm text-gray-400">
                        Direction
                    </label>

                    <select
                        id="history-direction"
                        x-model="filters.direction"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-white">
                        <option value="">
                            All
                        </option>

                        <option value="ru-en">
                            RU → EN
                        </option>

                        <option value="en-ru">
                            EN → RU
                        </option>
                    </select>
                </div>

                <div>
                    <label
                        for="history-pipeline"
                        class="mb-2 block text-sm text-gray-400">
                        Pipeline
                    </label>

                    <select
                        id="history-pipeline"
                        x-model="filters.pipeline"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-white">
                        <option value="">
                            All
                        </option>

                        <option value="chirp3-deepl-openai">
                            Chirp 3 Batch
                        </option>

                        <option value="chirp3-streaming-standard-deepl-openai">
                            Chirp 3 STANDARD
                        </option>

                        <option value="chirp3-streaming-short-deepl-openai">
                            Chirp 3 SHORT
                        </option>

                        <option value="flux-deepl-openai">
                            Deepgram Flux
                        </option>
                    </select>
                </div>

                <div>
                    <label
                        for="history-quality"
                        class="mb-2 block text-sm text-gray-400">
                        Quality
                    </label>

                    <select
                        id="history-quality"
                        x-model="filters.quality"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-white">
                        <option value="">
                            All
                        </option>

                        <option value="correct">
                            Correct
                        </option>

                        <option value="acceptable">
                            Acceptable
                        </option>

                        <option value="wrong">
                            Wrong
                        </option>

                        <option value="unreviewed">
                            Unreviewed
                        </option>
                    </select>
                </div>

                <div>
                    <label
                        for="history-date-from"
                        class="mb-2 block text-sm text-gray-400">
                        From
                    </label>

                    <input
                        id="history-date-from"
                        type="date"
                        x-model="filters.date_from"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-white">
                </div>

                <div>
                    <label
                        for="history-date-to"
                        class="mb-2 block text-sm text-gray-400">
                        To
                    </label>

                    <input
                        id="history-date-to"
                        type="date"
                        x-model="filters.date_to"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-white">
                </div>

                <div class="flex gap-3 md:col-span-2 xl:col-span-5">
                    <button
                        type="submit"
                        x-bind:disabled="isLoading"
                        class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-950 disabled:opacity-50">
                        Apply filters
                    </button>

                    <button
                        type="button"
                        x-on:click="resetFilters"
                        x-bind:disabled="isLoading"
                        class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 disabled:opacity-50">
                        Reset
                    </button>
                </div>
            </form>
        </section>

        <div
            x-show="error"
            x-cloak
            class="mt-6 rounded-xl border border-red-900/50 bg-red-950/30 p-4 text-sm text-red-400">
            <p x-text="error"></p>
        </div>

        <section class="mt-6 overflow-hidden rounded-2xl border border-gray-800 bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-800 px-6 py-4">
                <div>
                    <h2 class="font-semibold">
                        Runs
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        <span x-text="total"></span>
                        saved runs
                    </p>
                </div>

                <p
                    x-show="isLoading"
                    x-cloak
                    class="text-sm text-gray-400">
                    Loading...
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-800 text-sm">
                    <thead class="bg-gray-950 text-left text-xs uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="px-4 py-3">
                                Run
                            </th>

                            <th class="px-4 py-3">
                                Created
                            </th>

                            <th class="px-4 py-3">
                                Direction
                            </th>

                            <th class="px-4 py-3">
                                Pipeline
                            </th>

                            <th class="px-4 py-3">
                                Status
                            </th>

                            <th class="px-4 py-3">
                                Transcript
                            </th>

                            <th class="px-4 py-3">
                                Translation
                            </th>

                            <th class="px-4 py-3">
                                STT
                            </th>

                            <th class="px-4 py-3">
                                Audible
                            </th>

                            <th class="px-4 py-3">
                                Quality
                            </th>

                            <th class="px-4 py-3">
                                Diagnostics
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-800">
                        <template
                            x-for="run in runs"
                            x-bind:key="run.id">

                            <tr class="align-top">
                                <td class="whitespace-nowrap px-4 py-4 font-mono text-gray-300">
                                    #<span x-text="run.id"></span>

                                    <div
                                        x-show="run.comparison_id"
                                        class="mt-2 text-xs text-gray-600">
                                        Compare round
                                        <span x-text="run.comparison_round ?? '—'"></span>
                                    </div>
                                </td>

                                <td
                                    class="whitespace-nowrap px-4 py-4 text-gray-400"
                                    x-text="formatDate(run.created_at)">
                                </td>

                                <td
                                    class="whitespace-nowrap px-4 py-4"
                                    x-text="directionLabel(run)">
                                </td>

                                <td
                                    class="min-w-44 px-4 py-4"
                                    x-text="profileLabel(run.pipeline_profile)">
                                </td>

                                <td class="px-4 py-4">
                                    <span
                                        x-bind:class="run.success
                                            ? 'text-green-400'
                                            : 'text-red-400'"
                                        x-text="run.success
                                            ? 'Success'
                                            : 'Failed'">
                                    </span>

                                    <div
                                        x-show="!run.success && run.failed_stage"
                                        class="mt-2 text-xs text-gray-500"
                                        x-text="run.failed_stage">
                                    </div>
                                </td>

                                <td
                                    class="min-w-64 max-w-sm px-4 py-4 text-gray-300"
                                    x-text="run.stt_transcript ?? '—'">
                                </td>

                                <td
                                    class="min-w-64 max-w-sm px-4 py-4 text-gray-300"
                                    x-text="run.translated_text ?? '—'">
                                </td>

                                <td
                                    class="whitespace-nowrap px-4 py-4 text-gray-400"
                                    x-text="formatMs(
                                        metric(
                                            run,
                                            'server_stop_to_stt_ms',
                                        ),
                                    )">
                                </td>

                                <td
                                    class="whitespace-nowrap px-4 py-4 text-gray-400"
                                    x-text="formatMs(
                                        metric(
                                            run,
                                            'browser_input_end_to_first_audible_audio_received_ms',
                                        ),
                                    )">
                                </td>

                                <td class="min-w-72 px-4 py-4">
                                    <div>
                                        <p
                                            class="font-medium"
                                            x-text="qualityLabel(run)">
                                        </p>

                                        <p
                                            x-show="run.quality_issues?.length"
                                            class="mt-1 text-xs text-gray-500"
                                            x-text="formatIssues(run)">
                                        </p>

                                        <p
                                            x-show="run.reviewed_at"
                                            class="mt-1 text-xs text-gray-600">
                                            Reviewed

                                            <span
                                                x-text="formatDate(run.reviewed_at)">
                                            </span>
                                        </p>
                                    </div>

                                    <details class="mt-3">
                                        <summary
                                            class="cursor-pointer text-xs text-gray-400 hover:text-white">
                                            Edit review
                                        </summary>

                                        <div class="mt-3 space-y-3">
                                            <div class="grid grid-cols-3 gap-2">
                                                <button
                                                    type="button"
                                                    x-on:click="
                        run.reviewRating = 'correct';
                        markReviewDirty(run);
                    "
                                                    x-bind:class="
                        run.reviewRating === 'correct'
                            ? 'border-green-500 bg-green-950/40 text-green-300'
                            : 'border-gray-700 text-gray-300'
                    "
                                                    class="rounded-lg border px-2 py-2 text-xs">
                                                    Correct
                                                </button>

                                                <button
                                                    type="button"
                                                    x-on:click="
                        run.reviewRating = 'acceptable';
                        markReviewDirty(run);
                    "
                                                    x-bind:class="
                        run.reviewRating === 'acceptable'
                            ? 'border-yellow-500 bg-yellow-950/40 text-yellow-300'
                            : 'border-gray-700 text-gray-300'
                    "
                                                    class="rounded-lg border px-2 py-2 text-xs">
                                                    Acceptable
                                                </button>

                                                <button
                                                    type="button"
                                                    x-on:click="
                        run.reviewRating = 'wrong';
                        markReviewDirty(run);
                    "
                                                    x-bind:class="
                        run.reviewRating === 'wrong'
                            ? 'border-red-500 bg-red-950/40 text-red-300'
                            : 'border-gray-700 text-gray-300'
                    "
                                                    class="rounded-lg border px-2 py-2 text-xs">
                                                    Wrong
                                                </button>
                                            </div>

                                            <div
                                                x-show="run.reviewRating === 'wrong'"
                                                x-cloak
                                                class="space-y-2 rounded-lg border border-gray-800 bg-gray-950 p-3">

                                                <template
                                                    x-for="issue in qualityIssues"
                                                    x-bind:key="issue.value">

                                                    <label class="flex cursor-pointer items-start gap-2 text-xs text-gray-300">
                                                        <input
                                                            type="checkbox"
                                                            x-model="run.reviewIssues"
                                                            x-bind:value="issue.value"
                                                            x-on:change="
                                run.reviewSaved = false;
                                run.reviewError = null;
                            "
                                                            class="mt-0.5">

                                                        <span x-text="issue.label"></span>
                                                    </label>
                                                </template>
                                            </div>

                                            <div class="flex items-center gap-3">
                                                <button
                                                    type="button"
                                                    x-on:click="saveReview(run)"
                                                    x-bind:disabled="
                        run.isSavingReview ||
                        !run.reviewRating
                    "
                                                    class="rounded-lg bg-white px-3 py-2 text-xs font-medium text-gray-950 disabled:cursor-not-allowed disabled:opacity-40">

                                                    <span
                                                        x-show="!run.isSavingReview">
                                                        Save review
                                                    </span>

                                                    <span
                                                        x-show="run.isSavingReview"
                                                        x-cloak>
                                                        Saving...
                                                    </span>
                                                </button>

                                                <span
                                                    x-show="run.reviewSaved"
                                                    x-cloak
                                                    class="text-xs text-green-400">
                                                    Saved
                                                </span>
                                            </div>

                                            <p
                                                x-show="run.reviewError"
                                                x-cloak
                                                class="text-xs text-red-400"
                                                x-text="run.reviewError">
                                            </p>
                                        </div>
                                    </details>
                                </td>

                                <td class="min-w-56 px-4 py-4">
                                    <div
                                        x-show="run.error_message"
                                        class="mb-3 text-sm text-red-400"
                                        x-text="run.error_message">
                                    </div>

                                    <details>
                                        <summary class="cursor-pointer text-gray-400 hover:text-white">
                                            Metrics
                                        </summary>

                                        <pre
                                            class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap text-xs text-gray-500"
                                            x-text="formatMetrics(run)"></pre>
                                    </details>
                                </td>
                            </tr>
                        </template>

                        <tr x-show="!isLoading && runs.length === 0">
                            <td
                                colspan="11"
                                class="px-6 py-12 text-center text-gray-500">
                                No runs found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-gray-800 px-6 py-4">
                <button
                    type="button"
                    x-on:click="previousPage"
                    x-bind:disabled="currentPage <= 1 || isLoading"
                    class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 disabled:cursor-not-allowed disabled:opacity-40">
                    Previous
                </button>

                <p class="text-sm text-gray-500">
                    Page

                    <span x-text="currentPage"></span>

                    of

                    <span x-text="lastPage"></span>
                </p>

                <button
                    type="button"
                    x-on:click="nextPage"
                    x-bind:disabled="currentPage >= lastPage || isLoading"
                    class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 disabled:cursor-not-allowed disabled:opacity-40">
                    Next
                </button>
            </div>
        </section>
    </main>
</body>

</html>