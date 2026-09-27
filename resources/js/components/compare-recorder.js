function defaultWebSocketUrl() {
    const scheme = window.location.protocol === "https:" ? "wss" : "ws";

    return `${scheme}://${window.location.hostname}:8081`;
}

const defaultProfiles = [
    {
        name: "chirp3-deepl-openai",

        label: "Chirp 3 Batch",

        selected: true,
    },

    {
        name: "chirp3-streaming-standard-deepl-openai",

        label: "Chirp 3 Streaming STANDARD",

        selected: true,
    },

    {
        name: "chirp3-streaming-short-deepl-openai",

        label: "Chirp 3 Streaming SHORT",

        selected: true,
    },

    {
        name: "flux-deepl-openai",

        label: "Deepgram Flux",

        selected: true,
    },
];

export default (config = {}) => ({
    wsUrl: config.wsUrl ?? defaultWebSocketUrl(),

    storeUrl: config.storeUrl ?? "/live/runs",

    profiles: (config.profiles ?? defaultProfiles).map((profile) => ({
        ...profile,

        selected: profile.selected ?? true,
    })),

    runOptions: [1, 3, 5, 10],

    runCount: 5,

    sourceLanguage: "ru",

    status: "Idle",

    error: null,

    isPreparing: false,

    isRecording: false,

    isComparing: false,

    mediaRecorder: null,

    mediaStream: null,

    mimeType: null,

    recordingStartedAt: null,

    recordedDurationMs: null,

    comparisonId: null,

    /*

     * Each item contains the exact MediaRecorder Blob together

     * with the time at which it arrived during the real recording.

     * Every comparison run receives the same blobs with the same cadence.

     */

    recordedChunks: [],

    comparisonRuns: [],

    comparisonResults: [],

    currentRoundNumber: null,

    currentProfilePosition: null,

    currentProfileLabel: null,

    completedRunCount: 0,

    audibleRmsThreshold: 328,

    get isBusy() {
        return this.isPreparing || this.isRecording || this.isComparing;
    },

    get targetLanguage() {
        return this.sourceLanguage === "ru" ? "en" : "ru";
    },

    get selectedProfiles() {
        return this.profiles.filter((profile) => profile.selected);
    },

    get selectedProfileCount() {
        return this.selectedProfiles.length;
    },

    get plannedRunCount() {
        return this.selectedProfileCount * this.runCount;
    },

    get progressText() {
        if (
            !this.isComparing ||
            this.currentRoundNumber === null ||
            this.currentProfilePosition === null
        ) {
            return "";
        }

        return `Round ${this.currentRoundNumber}/${this.runCount} · ${this.currentProfilePosition}/${this.selectedProfileCount}: ${this.currentProfileLabel}`;
    },

    selectAllProfiles() {
        if (this.isBusy) {
            return;
        }

        this.profiles.forEach((profile) => {
            profile.selected = true;
        });
    },

    clearProfiles() {
        if (this.isBusy) {
            return;
        }

        this.profiles.forEach((profile) => {
            profile.selected = false;
        });
    },

    async startRecording() {
        if (this.isBusy) {
            return;
        }

        this.resetComparison();

        if (this.selectedProfileCount === 0) {
            this.setError("Select at least one pipeline to compare.");

            return;
        }

        this.comparisonId = window.crypto.randomUUID();

        if (!navigator.mediaDevices?.getUserMedia) {
            this.setError(
                "Microphone access is not supported by this browser.",
            );

            return;
        }

        if (!window.MediaRecorder) {
            this.setError("Audio recording is not supported by this browser.");

            return;
        }

        const mimeType = this.getSupportedMimeType();

        if (!mimeType) {
            this.setError(
                "WebM audio recording is not supported by this browser.",
            );

            return;
        }

        this.isPreparing = true;

        this.status = "Requesting microphone";

        try {
            this.mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });

            this.mimeType = mimeType;

            this.mediaRecorder = new MediaRecorder(
                this.mediaStream,

                {
                    mimeType,
                },
            );

            this.mediaRecorder.addEventListener(
                "dataavailable",

                (event) => {
                    this.captureChunk(event);
                },
            );

            this.mediaRecorder.addEventListener(
                "stop",

                () => {
                    this.handleRecordingStopped();
                },

                {
                    once: true,
                },
            );

            this.mediaRecorder.addEventListener(
                "error",

                (event) => {
                    this.setError(
                        event.error?.message ?? "Audio recording failed.",
                    );
                },
            );

            this.recordingStartedAt = performance.now();

            this.mediaRecorder.start(80);

            this.isPreparing = false;

            this.isRecording = true;

            this.status = "Recording";
        } catch (error) {
            this.setError(
                error instanceof Error
                    ? error.message
                    : "Could not start comparison recording.",
            );
        }
    },

    stopRecording() {
        if (!this.mediaRecorder || this.mediaRecorder.state !== "recording") {
            return;
        }

        this.isRecording = false;

        this.status = "Finishing recording";

        this.mediaRecorder.stop();
    },

    captureChunk(event) {
        if (event.data.size === 0 || this.recordingStartedAt === null) {
            return;
        }

        this.recordedChunks.push({
            blob: event.data,

            atMs: performance.now() - this.recordingStartedAt,
        });
    },

    handleRecordingStopped() {
        if (this.recordingStartedAt !== null) {
            this.recordedDurationMs = this.roundMilliseconds(
                performance.now() - this.recordingStartedAt,
            );
        }

        this.stopMediaStream();

        this.mediaRecorder = null;

        if (this.recordedChunks.length === 0) {
            this.setError("The comparison recording is empty.");

            return;
        }

        this.isComparing = true;

        this.status = "Starting comparison";

        this.runComparison()

            .catch((error) => {
                this.setError(
                    error instanceof Error
                        ? error.message
                        : "Pipeline comparison failed.",
                );
            });
    },

    async runComparison() {
        this.comparisonRuns = [];

        this.completedRunCount = 0;

        this.refreshComparisonResults();

        for (let roundIndex = 0; roundIndex < this.runCount; roundIndex += 1) {
            const roundProfiles = this.profilesForRound(roundIndex);

            for (
                let profileIndex = 0;
                profileIndex < roundProfiles.length;
                profileIndex += 1
            ) {
                const profile = roundProfiles[profileIndex];

                const runNumber = roundIndex + 1;

                this.currentRoundNumber = runNumber;

                this.currentProfilePosition = profileIndex + 1;

                this.currentProfileLabel = profile.label;

                this.status = `Comparing ${this.completedRunCount + 1}/${this.plannedRunCount}`;

                let run;

                try {
                    const completed = await this.runProfile(profile);

                    run = {
                        ...completed,

                        runNumber,

                        status: "completed",
                    };
                } catch (error) {
                    run = {
                        ...this.emptyRun(
                            profile,

                            runNumber,
                        ),

                        status: "error",
                        error:
                            error instanceof Error
                                ? error.message
                                : "Pipeline failed.",
                    };
                }

                try {
                    await this.persistRun(run);
                } catch (error) {
                    run.persistenceError =
                        error instanceof Error
                            ? error.message
                            : "Could not persist pipeline run.";
                }

                this.comparisonRuns.push(run);

                this.completedRunCount += 1;
                this.refreshComparisonResults();
                await this.sleep(100);
            }
        }

        this.currentRoundNumber = null;
        this.currentProfilePosition = null;
        this.currentProfileLabel = null;
        this.isComparing = false;

        const hasErrors = this.comparisonRuns.some(
            (run) => run.status === "error" || run.persistenceError !== null,
        );

        this.status = hasErrors
            ? "Comparison completed with errors"
            : "Comparison completed";
    },

    profilesForRound(roundIndex) {
        const profiles = this.selectedProfiles;

        if (profiles.length <= 1) {
            return profiles;
        }

        /*

         * Rotate execution order between rounds so one provider is not

         * permanently advantaged by always running first or last.

         */

        const offset = roundIndex % profiles.length;

        return [...profiles.slice(offset), ...profiles.slice(0, offset)];
    },

    refreshComparisonResults() {
        this.comparisonResults = this.selectedProfiles.map((profile) => {
            const runs = this.comparisonRuns.filter(
                (run) => run.profile === profile.name,
            );

            const successfulRuns = runs.filter(
                (run) => run.status === "completed",
            );

            return {
                profile: profile.name,

                label: profile.label,

                successCount: successfulRuns.length,

                totalRuns: this.runCount,

                sttMedianMs: this.median(
                    this.metricValues(
                        successfulRuns,

                        "serverStopToSttMs",
                    ),
                ),

                translatedMedianMs: this.median(
                    this.metricValues(
                        successfulRuns,

                        "serverStopToTranslationMs",
                    ),
                ),

                audibleMedianMs: this.median(
                    this.metricValues(
                        successfulRuns,

                        "browserInputEndToFirstAudibleAudioReceivedMs",
                    ),
                ),

                audibleMinMs: this.minimum(
                    this.metricValues(
                        successfulRuns,

                        "browserInputEndToFirstAudibleAudioReceivedMs",
                    ),
                ),

                audibleMaxMs: this.maximum(
                    this.metricValues(
                        successfulRuns,

                        "browserInputEndToFirstAudibleAudioReceivedMs",
                    ),
                ),

                transcriptConsistency: this.consistencyText(
                    successfulRuns.map((run) => run.transcript),
                ),
            };
        });
    },

    emptyRun(
        profile,

        runNumber,
    ) {
        return {
            runNumber,

            profile: profile.name,

            label: profile.label,

            error: null,

            persistenceError: null,

            persistedRunId: null,

            qualityRating: null,

            qualityIssues: [],

            isSavingReview: false,

            reviewError: null,

            reviewSaved: false,

            transcript: "",

            translatedText: "",

            serverStopToSttMs: null,

            translationMs: null,

            serverStopToTranslationMs: null,

            browserInputEndToTranslatedMs: null,

            ttsFirstAudioMs: null,

            serverStopToFirstTtsAudioMs: null,

            browserInputEndToFirstAudioReceivedMs: null,

            browserInputEndToFirstAudibleAudioReceivedMs: null,

            ttsDurationMs: null,

            serverStopToTtsCompletedMs: null,

            browserInputEndToCompletedMs: null,

            translatedAudioDurationMs: null,
        };
    },

    runProfile(profile) {
        return new Promise((resolve, reject) => {
            const result = this.emptyRun(
                profile,

                null,
            );

            let settled = false;

            let replayStarted = false;

            let inputEndedAt = null;

            const audioState = {
                sampleRate: 24000,

                channels: 1,

                bytesPerSample: 2,

                remainder: new Uint8Array(0),
            };

            const socket = new WebSocket(this.wsUrl);

            socket.binaryType = "arraybuffer";

            const timeout = window.setTimeout(
                () => {
                    fail(new Error(`Pipeline timed out: ${profile.label}`));
                },

                120000,
            );

            const closeSocket = () => {
                if (
                    socket.readyState === WebSocket.OPEN ||
                    socket.readyState === WebSocket.CONNECTING
                ) {
                    socket.close();
                }
            };

            const succeed = () => {
                if (settled) {
                    return;
                }

                settled = true;

                window.clearTimeout(timeout);

                closeSocket();

                resolve(result);
            };

            const fail = (error) => {
                if (settled) {
                    return;
                }

                settled = true;

                window.clearTimeout(timeout);

                closeSocket();

                reject(error);
            };

            socket.addEventListener(
                "open",

                () => {
                    socket.send(
                        JSON.stringify({
                            type: "start",

                            profile: profile.name,

                            source_language: this.sourceLanguage,

                            mime_type: this.mimeType,
                        }),
                    );
                },
            );

            socket.addEventListener(
                "message",

                (event) => {
                    if (event.data instanceof ArrayBuffer) {
                        this.handleComparisonAudio(
                            event.data,

                            result,

                            audioState,

                            inputEndedAt,
                        );

                        return;
                    }

                    if (typeof event.data !== "string") {
                        return;
                    }

                    let payload;

                    try {
                        payload = JSON.parse(event.data);
                    } catch {
                        fail(new Error("Live pipeline returned invalid JSON."));

                        return;
                    }

                    if (!payload || typeof payload !== "object") {
                        return;
                    }

                    if (payload.type === "session_started") {
                        if (replayStarted) {
                            return;
                        }

                        replayStarted = true;

                        this.replayRecording(
                            socket,

                            (endedAt) => {
                                inputEndedAt = endedAt;
                            },
                        ).catch(fail);

                        return;
                    }

                    if (payload.type === "transcript") {
                        if (typeof payload.text === "string") {
                            result.transcript = payload.text;
                        }

                        return;
                    }

                    if (payload.type === "translation_completed") {
                        this.applyTranslationResult(
                            result,

                            payload,
                        );

                        if (inputEndedAt !== null) {
                            result.browserInputEndToTranslatedMs =
                                this.roundMilliseconds(
                                    performance.now() - inputEndedAt,
                                );
                        }

                        return;
                    }

                    if (payload.type === "tts_started") {
                        if (typeof payload.sample_rate_hz === "number") {
                            audioState.sampleRate = payload.sample_rate_hz;
                        }

                        if (typeof payload.channels === "number") {
                            audioState.channels = payload.channels;
                        }

                        if (typeof payload.bytes_per_sample === "number") {
                            audioState.bytesPerSample =
                                payload.bytes_per_sample;
                        }

                        return;
                    }

                    if (payload.type === "completed") {
                        this.applyCompletedResult(
                            result,

                            payload,
                        );

                        if (inputEndedAt !== null) {
                            result.browserInputEndToCompletedMs =
                                this.roundMilliseconds(
                                    performance.now() - inputEndedAt,
                                );
                        }

                        succeed();

                        return;
                    }

                    if (payload.type === "error") {
                        fail(
                            new Error(
                                typeof payload.message === "string"
                                    ? payload.message
                                    : "Live pipeline failed.",
                            ),
                        );
                    }
                },
            );

            socket.addEventListener(
                "error",

                () => {
                    fail(new Error(`WebSocket failed: ${profile.label}`));
                },
            );

            socket.addEventListener(
                "close",

                () => {
                    if (!settled) {
                        fail(
                            new Error(
                                `WebSocket closed unexpectedly: ${profile.label}`,
                            ),
                        );
                    }
                },
            );
        });
    },

    async replayRecording(
        socket,

        onInputEnded,
    ) {
        const replayStartedAt = performance.now();

        for (const chunk of this.recordedChunks) {
            const targetAt = replayStartedAt + chunk.atMs;

            const delay = targetAt - performance.now();

            if (delay > 0) {
                await this.sleep(delay);
            }

            if (socket.readyState !== WebSocket.OPEN) {
                throw new Error("WebSocket closed during audio replay.");
            }

            socket.send(chunk.blob);
        }

        const inputEndedAt = performance.now();

        onInputEnded(inputEndedAt);

        socket.send(
            JSON.stringify({
                type: "stop",
            }),
        );
    },

    handleComparisonAudio(
        arrayBuffer,

        result,

        audioState,

        inputEndedAt,
    ) {
        if (inputEndedAt === null) {
            return;
        }

        const receivedAt = performance.now();

        if (result.browserInputEndToFirstAudioReceivedMs === null) {
            result.browserInputEndToFirstAudioReceivedMs =
                this.roundMilliseconds(receivedAt - inputEndedAt);
        }

        if (result.browserInputEndToFirstAudibleAudioReceivedMs !== null) {
            return;
        }

        const audible = this.consumeAudiblePcm(
            audioState,

            arrayBuffer,
        );

        if (audible) {
            result.browserInputEndToFirstAudibleAudioReceivedMs =
                this.roundMilliseconds(receivedAt - inputEndedAt);
        }
    },

    consumeAudiblePcm(
        state,

        arrayBuffer,
    ) {
        if (state.channels !== 1 || state.bytesPerSample !== 2) {
            return false;
        }

        const incoming = new Uint8Array(arrayBuffer);

        if (incoming.length === 0) {
            return false;
        }

        let bytes;

        if (state.remainder.length > 0) {
            bytes = new Uint8Array(state.remainder.length + incoming.length);

            bytes.set(
                state.remainder,

                0,
            );

            bytes.set(
                incoming,

                state.remainder.length,
            );
        } else {
            bytes = incoming;
        }

        const samplesPerFrame = Math.round(state.sampleRate * 0.01);

        const frameBytes = samplesPerFrame * state.bytesPerSample;

        let offset = 0;

        while (offset + frameBytes <= bytes.length) {
            const view = new DataView(
                bytes.buffer,

                bytes.byteOffset + offset,

                frameBytes,
            );

            let sumSquares = 0;

            for (
                let sampleIndex = 0;
                sampleIndex < samplesPerFrame;
                sampleIndex += 1
            ) {
                const sample = view.getInt16(
                    sampleIndex * 2,

                    true,
                );

                sumSquares += sample * sample;
            }

            const rms = Math.sqrt(sumSquares / samplesPerFrame);

            offset += frameBytes;

            if (rms >= this.audibleRmsThreshold) {
                state.remainder = new Uint8Array(0);

                return true;
            }
        }

        state.remainder = bytes.slice(offset);

        return false;
    },

    applyTranslationResult(
        result,

        payload,
    ) {
        if (typeof payload.text === "string") {
            result.transcript = payload.text;
        }

        if (typeof payload.translated_text === "string") {
            result.translatedText = payload.translated_text;
        }

        if (typeof payload.server_stop_to_stt_ms === "number") {
            result.serverStopToSttMs = payload.server_stop_to_stt_ms;
        }

        if (typeof payload.translation_ms === "number") {
            result.translationMs = payload.translation_ms;
        }

        if (typeof payload.server_stop_to_translation_ms === "number") {
            result.serverStopToTranslationMs =
                payload.server_stop_to_translation_ms;
        }
    },

    applyCompletedResult(
        result,

        payload,
    ) {
        this.applyTranslationResult(
            result,

            payload,
        );

        if (typeof payload.tts_first_audio_ms === "number") {
            result.ttsFirstAudioMs = payload.tts_first_audio_ms;
        }

        if (typeof payload.server_stop_to_first_tts_audio_ms === "number") {
            result.serverStopToFirstTtsAudioMs =
                payload.server_stop_to_first_tts_audio_ms;
        }

        if (typeof payload.tts_duration_ms === "number") {
            result.ttsDurationMs = payload.tts_duration_ms;
        }

        if (typeof payload.server_stop_to_tts_completed_ms === "number") {
            result.serverStopToTtsCompletedMs =
                payload.server_stop_to_tts_completed_ms;
        }

        if (typeof payload.translated_audio_duration_ms === "number") {
            result.translatedAudioDurationMs =
                payload.translated_audio_duration_ms;
        }
    },

    async persistRun(run) {
        if (!this.comparisonId) {
            throw new Error("Comparison ID is missing.");
        }

        const csrfToken = document

            .querySelector('meta[name="csrf-token"]')

            ?.getAttribute("content");

        if (!csrfToken) {
            throw new Error("CSRF token is missing.");
        }

        const response = await fetch(
            this.storeUrl,

            {
                method: "POST",

                headers: {
                    Accept: "application/json",

                    "Content-Type": "application/json",

                    "X-CSRF-TOKEN": csrfToken,
                },

                body: JSON.stringify({
                    comparison_id: this.comparisonId,

                    comparison_round: run.runNumber,

                    pipeline_profile: run.profile,

                    source_language: this.sourceLanguage,

                    input_audio_duration_ms: this.recordedDurationMs,

                    stt_transcript: run.transcript || null,

                    translated_text: run.translatedText || null,

                    success: run.status === "completed",

                    failed_stage: this.failedStage(run),

                    error_message: run.error,

                    metrics: {
                        server_stop_to_stt_ms: run.serverStopToSttMs,

                        translation_ms: run.translationMs,

                        server_stop_to_translation_ms:
                            run.serverStopToTranslationMs,

                        browser_input_end_to_translated_ms:
                            run.browserInputEndToTranslatedMs,

                        tts_first_audio_ms: run.ttsFirstAudioMs,

                        server_stop_to_first_tts_audio_ms:
                            run.serverStopToFirstTtsAudioMs,

                        browser_input_end_to_first_audio_received_ms:
                            run.browserInputEndToFirstAudioReceivedMs,

                        browser_input_end_to_first_audible_audio_received_ms:
                            run.browserInputEndToFirstAudibleAudioReceivedMs,

                        tts_duration_ms: run.ttsDurationMs,

                        server_stop_to_tts_completed_ms:
                            run.serverStopToTtsCompletedMs,

                        browser_input_end_to_completed_ms:
                            run.browserInputEndToCompletedMs,

                        translated_audio_duration_ms:
                            run.translatedAudioDurationMs,
                    },
                }),
            },
        );

        const data = await response

            .json()

            .catch(() => null);

        if (!response.ok) {
            throw new Error(
                data?.message ?? "Could not save live pipeline run.",
            );
        }

        if (typeof data?.id !== "number") {
            throw new Error("Saved run response does not contain an ID.");
        }

        run.persistedRunId = data.id;
    },

    selectRunQualityRating(run, rating) {
        run.qualityRating = rating;

        if (rating !== "wrong") {
            run.qualityIssues = [];
        }

        run.reviewError = null;
        run.reviewSaved = false;
    },

    async saveRunQualityReview(run) {
        if (
            run.persistedRunId === null ||
            !run.qualityRating ||
            run.isSavingReview
        ) {
            return;
        }

        const csrfToken = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content");

        if (!csrfToken) {
            run.reviewError = "CSRF token is missing.";

            return;
        }

        run.isSavingReview = true;
        run.reviewError = null;
        run.reviewSaved = false;

        try {
            const response = await fetch(
                `${this.storeUrl}/${run.persistedRunId}/quality`,
                {
                    method: "PATCH",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken,
                    },
                    body: JSON.stringify({
                        quality_rating: run.qualityRating,
                        quality_issues:
                            run.qualityRating === "wrong"
                                ? run.qualityIssues
                                : [],
                    }),
                },
            );

            const data = await response.json().catch(() => null);

            if (!response.ok) {
                throw new Error(
                    data?.message ?? "Could not save quality review.",
                );
            }

            run.qualityRating = data?.quality_rating ?? run.qualityRating;

            run.qualityIssues = Array.isArray(data?.quality_issues)
                ? data.quality_issues
                : [];

            run.reviewSaved = true;
        } catch (error) {
            run.reviewError =
                error instanceof Error
                    ? error.message
                    : "Could not save quality review.";
        } finally {
            run.isSavingReview = false;
        }
    },

    failedStage(run) {
        if (run.status !== "error") {
            return null;
        }

        if (run.serverStopToSttMs === null) {
            return "stt";
        }

        if (run.serverStopToTranslationMs === null) {
            return "translation";
        }

        return "tts";
    },

    metricValues(
        runs,

        key,
    ) {
        return runs

            .map((run) => run[key])

            .filter((value) => typeof value === "number");
    },

    median(values) {
        if (values.length === 0) {
            return null;
        }

        const sorted = [...values].sort((left, right) => left - right);

        const middle = Math.floor(sorted.length / 2);

        const value =
            sorted.length % 2 === 1
                ? sorted[middle]
                : (sorted[middle - 1] + sorted[middle]) / 2;

        return this.roundMilliseconds(value);
    },

    minimum(values) {
        if (values.length === 0) {
            return null;
        }

        return this.roundMilliseconds(Math.min(...values));
    },

    maximum(values) {
        if (values.length === 0) {
            return null;
        }

        return this.roundMilliseconds(Math.max(...values));
    },

    consistencyText(values) {
        const normalized = values.filter(
            (value) => typeof value === "string" && value.trim() !== "",
        );

        if (normalized.length === 0) {
            return "—";
        }

        const counts = new Map();

        normalized.forEach((value) => {
            counts.set(
                value,

                (counts.get(value) ?? 0) + 1,
            );
        });

        const highest = Math.max(...counts.values());

        return `${highest}/${normalized.length} same`;
    },

    getSupportedMimeType() {
        const types = ["audio/webm;codecs=opus", "audio/webm"];

        return (
            types.find((type) => MediaRecorder.isTypeSupported(type)) ?? null
        );
    },

    stopMediaStream() {
        if (!this.mediaStream) {
            return;
        }

        this.mediaStream

            .getTracks()

            .forEach((track) => track.stop());

        this.mediaStream = null;
    },

    resetComparison() {
        this.stopMediaStream();

        this.status = "Idle";

        this.error = null;

        this.isPreparing = false;

        this.isRecording = false;

        this.isComparing = false;

        this.mediaRecorder = null;

        this.mimeType = null;

        this.recordingStartedAt = null;

        this.recordedDurationMs = null;

        this.comparisonId = null;

        this.recordedChunks = [];

        this.comparisonRuns = [];

        this.comparisonResults = [];

        this.currentRoundNumber = null;

        this.currentProfilePosition = null;

        this.currentProfileLabel = null;

        this.completedRunCount = 0;
    },

    setError(message) {
        this.error = message;

        this.status = "Error";

        this.isPreparing = false;

        this.isRecording = false;

        this.isComparing = false;

        if (this.mediaRecorder && this.mediaRecorder.state === "recording") {
            this.mediaRecorder.stop();
        }

        this.stopMediaStream();
    },

    formatMs(value) {
        if (typeof value !== "number") {
            return "—";
        }

        return `${value} ms`;
    },

    formatDuration(value) {
        if (typeof value !== "number") {
            return "—";
        }

        return `${(value / 1000).toFixed(2)} s`;
    },

    roundMilliseconds(value) {
        return Math.round(value * 100) / 100;
    },

    sleep(milliseconds) {
        return new Promise((resolve) => {
            window.setTimeout(
                resolve,

                milliseconds,
            );
        });
    },
});
