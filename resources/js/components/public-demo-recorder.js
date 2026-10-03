function defaultWebSocketUrl() {
    const scheme = window.location.protocol === "https:" ? "wss" : "ws";

    return `${scheme}://${window.location.hostname}:8081`;
}

export default (config = {}) => ({
    sessionUrl: config.sessionUrl ?? "/demo/session",
    wsUrl: config.wsUrl || defaultWebSocketUrl(),

    sourceLanguage: "ru",

    status: "Ready",
    error: null,

    transcription: "",
    translatedText: "",

    maxAudioSeconds: 15,
    elapsedSeconds: 0,

    hourlyRemaining: null,
    dailyRemaining: null,

    isConnecting: false,
    isRecording: false,
    isStopping: false,

    socket: null,
    socketCloseExpected: false,

    sessionStarted: false,
    sessionResolve: null,
    sessionReject: null,
    sessionTimeout: null,

    demoToken: null,

    mediaRecorder: null,
    mediaStream: null,
    mimeType: null,

    recordingStartedAt: null,
    recordingInterval: null,
    autoStopTimeout: null,

    audioContext: null,
    audioSources: [],
    nextPlaybackTime: 0,

    ttsSampleRate: 24000,
    ttsChannels: 1,
    ttsBytesPerSample: 2,
    pcmRemainder: new Uint8Array(0),

    runCompleted: false,
    aborted: false,

    get isBusy() {
        return this.isConnecting || this.isRecording || this.isStopping;
    },

    get targetLanguage() {
        return this.sourceLanguage === "ru" ? "en" : "ru";
    },

    get sourceLanguageLabel() {
        return this.sourceLanguage === "ru" ? "Russian" : "English";
    },

    get targetLanguageLabel() {
        return this.targetLanguage === "ru" ? "Russian" : "English";
    },

    get remainingSeconds() {
        return Math.max(0, this.maxAudioSeconds - this.elapsedSeconds).toFixed(
            1,
        );
    },

    setSourceLanguage(language) {
        if (this.isBusy || !["ru", "en"].includes(language)) {
            return;
        }

        this.sourceLanguage = language;

        this.transcription = "";
        this.translatedText = "";
        this.error = null;
        this.status = "Ready";
    },

    async startRecording() {
        if (this.isBusy) {
            return;
        }

        this.resetRun();

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

        this.isConnecting = true;
        this.status = "Preparing audio";

        try {
            await this.prepareAudioPlayback();

            this.status = "Requesting microphone access";

            this.mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });

            this.mimeType = mimeType;

            this.mediaRecorder = new MediaRecorder(this.mediaStream, {
                mimeType,
            });

            this.mediaRecorder.addEventListener("dataavailable", (event) => {
                this.sendAudioChunk(event.data);
            });

            this.mediaRecorder.addEventListener("stop", () => {
                this.finishRecording();
            });

            this.mediaRecorder.addEventListener("error", (event) => {
                this.setError(
                    event.error?.message ?? "Audio recording failed.",
                );
            });

            this.status = "Starting demo session";

            await this.requestDemoSession();

            this.status = "Connecting";

            await this.connectSession();

            this.recordingStartedAt = performance.now();

            this.mediaRecorder.start(80);

            this.isConnecting = false;
            this.isRecording = true;
            this.status = "Listening";

            this.startRecordingTimer();
        } catch (error) {
            this.setError(
                error instanceof Error
                    ? error.message
                    : "Could not start the voice demo.",
            );
        }
    },

    async requestDemoSession() {
        const csrfToken = this.csrfToken();

        if (!csrfToken) {
            throw new Error("Could not initialize the demo session.");
        }

        const response = await fetch(this.sessionUrl, {
            method: "POST",

            headers: {
                Accept: "application/json",

                "Content-Type": "application/json",

                "X-CSRF-TOKEN": csrfToken,
            },

            body: JSON.stringify({
                source_language: this.sourceLanguage,
            }),
        });

        const data = await response.json().catch(() => null);

        if (!response.ok) {
            let message = data?.message ?? "Could not start the public demo.";

            if (
                response.status === 429 &&
                typeof data?.retry_after === "number"
            ) {
                message += ` Try again in ${this.formatRetryAfter(
                    data.retry_after,
                )}.`;
            }

            throw new Error(message);
        }

        if (typeof data?.token !== "string" || data.token.trim() === "") {
            throw new Error("The demo server returned an invalid session.");
        }

        this.demoToken = data.token;

        if (
            typeof data.max_audio_seconds === "number" &&
            data.max_audio_seconds > 0
        ) {
            this.maxAudioSeconds = data.max_audio_seconds;
        }

        this.hourlyRemaining =
            typeof data?.usage?.hourly_remaining === "number"
                ? data.usage.hourly_remaining
                : null;

        this.dailyRemaining =
            typeof data?.usage?.daily_remaining === "number"
                ? data.usage.daily_remaining
                : null;
    },

    connectSession() {
        return new Promise((resolve, reject) => {
            this.sessionResolve = resolve;

            this.sessionReject = reject;

            this.sessionTimeout = window.setTimeout(() => {
                this.rejectSessionStart(
                    new Error("Timed out while connecting to the voice demo."),
                );
            }, 8000);

            const socket = new WebSocket(this.wsUrl);

            socket.binaryType = "arraybuffer";

            this.socket = socket;

            this.socketCloseExpected = false;

            socket.addEventListener("open", () => {
                if (this.socket !== socket) {
                    return;
                }

                socket.send(
                    JSON.stringify({
                        type: "start",

                        demo_token: this.demoToken,

                        mime_type: this.mimeType,
                    }),
                );
            });

            socket.addEventListener("message", (event) => {
                if (this.socket !== socket) {
                    return;
                }

                this.handleSocketMessage(event);
            });

            socket.addEventListener("error", () => {
                if (this.socket !== socket) {
                    return;
                }

                if (!this.sessionStarted) {
                    this.rejectSessionStart(
                        new Error("Could not connect to the voice demo."),
                    );

                    return;
                }

                this.setError("The voice demo connection failed.");
            });

            socket.addEventListener("close", () => {
                if (this.socket !== socket) {
                    return;
                }

                if (this.socketCloseExpected || this.runCompleted) {
                    return;
                }

                if (!this.sessionStarted) {
                    this.rejectSessionStart(
                        new Error(
                            "The voice demo connection closed before recording started.",
                        ),
                    );

                    return;
                }

                if (!this.error) {
                    this.setError(
                        "The voice demo connection closed unexpectedly.",
                    );
                }
            });
        });
    },

    stopRecording() {
        if (!this.mediaRecorder || this.mediaRecorder.state !== "recording") {
            return;
        }

        this.isRecording = false;
        this.isStopping = true;

        this.updateElapsedTime();
        this.clearRecordingTimer();

        this.status = "Processing speech";

        this.mediaRecorder.stop();
    },

    sendAudioChunk(blob) {
        if (this.aborted || blob.size === 0) {
            return;
        }

        if (
            !this.socket ||
            this.socket.readyState !== WebSocket.OPEN ||
            !this.sessionStarted
        ) {
            this.setError("The voice demo connection is not available.");

            return;
        }

        this.socket.send(blob);
    },

    finishRecording() {
        this.stopMediaStream();

        this.mediaRecorder = null;

        if (this.aborted) {
            return;
        }

        if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
            this.setError(
                "The connection closed before the recording was processed.",
            );

            return;
        }

        this.socket.send(
            JSON.stringify({
                type: "stop",
            }),
        );

        this.status = "Recognizing speech";
    },

    handleSocketMessage(event) {
        if (event.data instanceof ArrayBuffer) {
            this.handleTtsAudioChunk(event.data);

            return;
        }

        if (typeof event.data !== "string") {
            return;
        }

        let payload;

        try {
            payload = JSON.parse(event.data);
        } catch {
            this.setError("The voice demo returned an invalid response.");

            return;
        }

        if (!payload || typeof payload !== "object") {
            return;
        }

        if (payload.type === "session_started") {
            this.sessionStarted = true;

            this.resolveSessionStart();

            return;
        }

        if (payload.type === "transcript") {
            if (typeof payload.text === "string") {
                this.transcription = payload.text;
            }

            if (payload.is_final === true && this.isStopping) {
                this.status = "Translating";
            }

            return;
        }

        if (payload.type === "translation_completed") {
            if (typeof payload.text === "string") {
                this.transcription = payload.text;
            }

            if (typeof payload.translated_text === "string") {
                this.translatedText = payload.translated_text;
            }

            this.status = "Generating translated voice";

            return;
        }

        if (payload.type === "tts_started") {
            if (typeof payload.sample_rate_hz === "number") {
                this.ttsSampleRate = payload.sample_rate_hz;
            }

            if (typeof payload.channels === "number") {
                this.ttsChannels = payload.channels;
            }

            if (typeof payload.bytes_per_sample === "number") {
                this.ttsBytesPerSample = payload.bytes_per_sample;
            }

            this.status = "Playing translation";

            return;
        }

        if (payload.type === "completed") {
            if (typeof payload.text === "string") {
                this.transcription = payload.text;
            }

            if (typeof payload.translated_text === "string") {
                this.translatedText = payload.translated_text;
            }

            this.runCompleted = true;
            this.isStopping = false;
            this.status = "Completed";

            this.clearRecordingTimer();
            this.stopMediaStream();
            this.closeSocket();

            return;
        }

        if (payload.type === "error") {
            const message =
                typeof payload.message === "string"
                    ? payload.message
                    : "The voice demo failed.";

            if (!this.sessionStarted) {
                this.rejectSessionStart(new Error(message));

                return;
            }

            this.setError(message);
        }
    },

    handleTtsAudioChunk(arrayBuffer) {
        try {
            this.schedulePcm16Chunk(arrayBuffer);
        } catch (error) {
            this.setError(
                error instanceof Error
                    ? error.message
                    : "Could not play translated speech.",
            );
        }
    },

    schedulePcm16Chunk(arrayBuffer) {
        if (!this.audioContext) {
            throw new Error("Audio playback is not available.");
        }

        if (this.ttsChannels !== 1 || this.ttsBytesPerSample !== 2) {
            throw new Error("Unsupported translated audio format.");
        }

        if (arrayBuffer.byteLength === 0) {
            return;
        }

        const incoming = new Uint8Array(arrayBuffer);

        let bytes;

        if (this.pcmRemainder.length > 0) {
            bytes = new Uint8Array(this.pcmRemainder.length + incoming.length);

            bytes.set(this.pcmRemainder, 0);

            bytes.set(incoming, this.pcmRemainder.length);
        } else {
            bytes = incoming;
        }

        const completeByteLength = bytes.length - (bytes.length % 2);

        this.pcmRemainder = bytes.slice(completeByteLength);

        if (completeByteLength === 0) {
            return;
        }

        const sampleCount = completeByteLength / 2;

        const audioBuffer = this.audioContext.createBuffer(
            1,
            sampleCount,
            this.ttsSampleRate,
        );

        const channel = audioBuffer.getChannelData(0);

        const view = new DataView(
            bytes.buffer,
            bytes.byteOffset,
            completeByteLength,
        );

        for (let index = 0; index < sampleCount; index += 1) {
            channel[index] = view.getInt16(index * 2, true) / 32768;
        }

        const source = this.audioContext.createBufferSource();

        source.buffer = audioBuffer;

        source.connect(this.audioContext.destination);

        const startAt = Math.max(
            this.audioContext.currentTime,
            this.nextPlaybackTime,
        );

        source.start(startAt);

        this.nextPlaybackTime = startAt + audioBuffer.duration;

        this.audioSources.push(source);

        source.addEventListener("ended", () => {
            this.audioSources = this.audioSources.filter(
                (item) => item !== source,
            );
        });
    },

    async prepareAudioPlayback() {
        const AudioContextClass =
            window.AudioContext || window.webkitAudioContext;

        if (!AudioContextClass) {
            throw new Error(
                "Web Audio playback is not supported by this browser.",
            );
        }

        if (!this.audioContext) {
            this.audioContext = new AudioContextClass();
        }

        if (this.audioContext.state === "suspended") {
            await this.audioContext.resume();
        }

        this.nextPlaybackTime = this.audioContext.currentTime;
    },

    stopAudioPlayback() {
        this.audioSources.forEach((source) => {
            try {
                source.stop();
            } catch {
                //
            }

            try {
                source.disconnect();
            } catch {
                //
            }
        });

        this.audioSources = [];
        this.nextPlaybackTime = 0;
        this.pcmRemainder = new Uint8Array(0);
    },

    startRecordingTimer() {
        this.clearRecordingTimer();

        this.elapsedSeconds = 0;

        this.recordingInterval = window.setInterval(() => {
            this.updateElapsedTime();
        }, 100);

        const stopAfterMilliseconds = Math.max(
            500,
            this.maxAudioSeconds * 1000 - 150,
        );

        this.autoStopTimeout = window.setTimeout(() => {
            this.stopRecording();
        }, stopAfterMilliseconds);
    },

    updateElapsedTime() {
        if (this.recordingStartedAt === null) {
            return;
        }

        this.elapsedSeconds = Math.min(
            this.maxAudioSeconds,
            (performance.now() - this.recordingStartedAt) / 1000,
        );
    },

    clearRecordingTimer() {
        if (this.recordingInterval !== null) {
            window.clearInterval(this.recordingInterval);

            this.recordingInterval = null;
        }

        if (this.autoStopTimeout !== null) {
            window.clearTimeout(this.autoStopTimeout);

            this.autoStopTimeout = null;
        }
    },

    resolveSessionStart() {
        this.clearSessionTimeout();

        const resolve = this.sessionResolve;

        this.sessionResolve = null;

        this.sessionReject = null;

        if (resolve) {
            resolve();
        }
    },

    rejectSessionStart(error) {
        this.clearSessionTimeout();

        const reject = this.sessionReject;

        this.sessionResolve = null;

        this.sessionReject = null;

        if (reject) {
            reject(error);
        }
    },

    clearSessionTimeout() {
        if (this.sessionTimeout === null) {
            return;
        }

        window.clearTimeout(this.sessionTimeout);

        this.sessionTimeout = null;
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

        this.mediaStream.getTracks().forEach((track) => track.stop());

        this.mediaStream = null;
    },

    closeSocket() {
        if (!this.socket) {
            return;
        }

        const socket = this.socket;

        this.socketCloseExpected = true;

        this.socket = null;

        this.sessionStarted = false;

        if (
            socket.readyState === WebSocket.OPEN ||
            socket.readyState === WebSocket.CONNECTING
        ) {
            socket.close();
        }
    },

    resetRun() {
        this.clearSessionTimeout();
        this.clearRecordingTimer();

        this.aborted = false;
        this.runCompleted = false;

        this.closeSocket();
        this.stopMediaStream();
        this.stopAudioPlayback();

        this.demoToken = null;

        this.status = "Ready";
        this.error = null;

        this.transcription = "";
        this.translatedText = "";

        this.elapsedSeconds = 0;
        this.recordingStartedAt = null;

        this.isConnecting = false;
        this.isRecording = false;
        this.isStopping = false;

        this.mediaRecorder = null;
        this.mimeType = null;

        this.ttsSampleRate = 24000;
        this.ttsChannels = 1;
        this.ttsBytesPerSample = 2;
    },

    setError(message) {
        this.error = message;
        this.status = "Unable to complete demo";

        this.aborted = true;

        this.isConnecting = false;
        this.isRecording = false;
        this.isStopping = false;

        this.clearSessionTimeout();
        this.clearRecordingTimer();

        if (this.mediaRecorder && this.mediaRecorder.state === "recording") {
            try {
                this.mediaRecorder.stop();
            } catch {
                //
            }
        }

        this.stopMediaStream();
        this.closeSocket();
    },

    csrfToken() {
        return document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content");
    },

    formatRetryAfter(seconds) {
        if (seconds < 60) {
            return `${seconds} seconds`;
        }

        const minutes = Math.ceil(seconds / 60);

        return `${minutes} minute${minutes === 1 ? "" : "s"}`;
    },
});
