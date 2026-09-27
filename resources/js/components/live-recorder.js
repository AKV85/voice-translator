function defaultWebSocketUrl() {
    const scheme = window.location.protocol === 'https:'
        ? 'wss'
        : 'ws';

    return `${scheme}://${window.location.hostname}:8081`;
}

export default (config = {}) => ({
    profile:
        config.profile
        ?? 'chirp3-streaming-standard-deepl-openai',

    wsUrl:
        config.wsUrl
        ?? defaultWebSocketUrl(),

    status: 'Idle',
    error: null,

    sourceLanguage: 'ru',

    transcription: '',
    translatedText: '',

    serverStopToSttMs: null,
    translationMs: null,
    serverStopToTranslationMs: null,

    ttsFirstAudioMs: null,
    serverStopToFirstTtsAudioMs: null,
    ttsDurationMs: null,
    serverStopToTtsCompletedMs: null,

    browserStopToTranslatedMs: null,
    browserStopToFirstAudioReceivedMs: null,
    browserStopToFirstAudiblePlaybackMs: null,
    browserStopToCompletedMs: null,

    translatedAudioDurationMs: null,

    isConnecting: false,
    isRecording: false,
    isStopping: false,

    socket: null,
    mediaRecorder: null,
    mediaStream: null,
    mimeType: null,

    stopStartedAt: null,

    sessionStarted: false,
    sessionResolve: null,
    sessionReject: null,
    sessionTimeout: null,

    socketCloseExpected: false,
    runCompleted: false,
    aborted: false,

    audioContext: null,
    audioSources: [],
    nextPlaybackTime: 0,

    ttsSampleRate: 24000,
    ttsChannels: 1,
    ttsBytesPerSample: 2,

    audibleRmsThreshold: 328,

    firstAudioReceived: false,
    firstAudiblePlaybackScheduled: false,
    audiblePlaybackTimer: null,

    get isBusy() {
        return this.isConnecting
            || this.isRecording
            || this.isStopping;
    },

    get targetLanguage() {
        return this.sourceLanguage === 'ru'
            ? 'en'
            : 'ru';
    },

    async startRecording() {
        if (this.isBusy) {
            return;
        }

        this.resetRun();

        if (!navigator.mediaDevices?.getUserMedia) {
            this.setError(
                'Microphone access is not supported by this browser.',
            );

            return;
        }

        if (!window.MediaRecorder) {
            this.setError(
                'Audio recording is not supported by this browser.',
            );

            return;
        }

        const mimeType =
            this.getSupportedMimeType();

        if (!mimeType) {
            this.setError(
                'WebM audio recording is not supported by this browser.',
            );

            return;
        }

        this.isConnecting = true;
        this.status = 'Preparing audio';

        try {
            await this.prepareAudioPlayback();

            this.status =
                'Requesting microphone';

            this.mediaStream =
                await navigator.mediaDevices.getUserMedia({
                    audio: true,
                });

            this.mimeType =
                mimeType;

            this.mediaRecorder =
                new MediaRecorder(
                    this.mediaStream,
                    {
                        mimeType,
                    },
                );

            this.mediaRecorder.addEventListener(
                'dataavailable',
                (event) => {
                    this.sendAudioChunk(
                        event.data,
                    );
                },
            );

            this.mediaRecorder.addEventListener(
                'stop',
                () => {
                    this.finishRecording();
                },
            );

            this.mediaRecorder.addEventListener(
                'error',
                (event) => {
                    this.setError(
                        event.error?.message
                        ?? 'Audio recording failed.',
                    );
                },
            );

            this.status =
                'Connecting to live pipeline';

            await this.connectSession();

            this.mediaRecorder.start(
                80,
            );

            this.isConnecting = false;
            this.isRecording = true;
            this.status = 'Recording';
        } catch (error) {
            this.setError(
                error instanceof Error
                    ? error.message
                    : 'Could not start live recording.',
            );
        }
    },

    stopRecording() {
        if (
            !this.mediaRecorder
            || this.mediaRecorder.state
                !== 'recording'
        ) {
            return;
        }

        this.isRecording = false;
        this.isStopping = true;

        this.stopStartedAt =
            performance.now();

        this.status =
            'Stopping';

        this.mediaRecorder.stop();
    },

    sendAudioChunk(blob) {
        if (
            this.aborted
            || blob.size === 0
        ) {
            return;
        }

        if (
            !this.socket
            || this.socket.readyState
                !== WebSocket.OPEN
            || !this.sessionStarted
        ) {
            this.setError(
                'Live pipeline connection is not available.',
            );

            return;
        }

        this.socket.send(
            blob,
        );
    },

    finishRecording() {
        this.stopMediaStream();

        this.mediaRecorder = null;

        if (this.aborted) {
            return;
        }

        if (
            !this.socket
            || this.socket.readyState
                !== WebSocket.OPEN
        ) {
            this.setError(
                'Live pipeline connection closed before STOP.',
            );

            return;
        }

        this.socket.send(
            JSON.stringify({
                type: 'stop',
            }),
        );

        this.status =
            'Waiting for final transcript';
    },

    connectSession() {
        return new Promise(
            (resolve, reject) => {
                this.sessionResolve =
                    resolve;

                this.sessionReject =
                    reject;

                this.sessionTimeout =
                    window.setTimeout(
                        () => {
                            this.rejectSessionStart(
                                new Error(
                                    'Timed out waiting for the live pipeline.',
                                ),
                            );
                        },
                        5000,
                    );

                const socket =
                    new WebSocket(
                        this.wsUrl,
                    );

                socket.binaryType =
                    'arraybuffer';

                this.socket =
                    socket;

                socket.addEventListener(
                    'open',
                    () => {
                        if (
                            this.socket
                            !== socket
                        ) {
                            return;
                        }

                        socket.send(
                            JSON.stringify({
                                type: 'start',
                                profile: this.profile,
                                source_language:
                                    this.sourceLanguage,
                                mime_type:
                                    this.mimeType,
                            }),
                        );
                    },
                );

                socket.addEventListener(
                    'message',
                    (event) => {
                        this.handleSocketMessage(
                            event,
                        );
                    },
                );

                socket.addEventListener(
                    'error',
                    () => {
                        if (
                            !this.sessionStarted
                        ) {
                            this.rejectSessionStart(
                                new Error(
                                    'WebSocket connection failed.',
                                ),
                            );

                            return;
                        }

                        this.setError(
                            'Live pipeline WebSocket failed.',
                        );
                    },
                );

                socket.addEventListener(
                    'close',
                    () => {
                        if (
                            this.socketCloseExpected
                            || this.runCompleted
                        ) {
                            return;
                        }

                        if (
                            !this.sessionStarted
                        ) {
                            this.rejectSessionStart(
                                new Error(
                                    'WebSocket connection closed before the session started.',
                                ),
                            );

                            return;
                        }

                        if (!this.error) {
                            this.setError(
                                'Live pipeline connection closed unexpectedly.',
                            );
                        }
                    },
                );
            },
        );
    },

    handleSocketMessage(event) {
        if (
            event.data
            instanceof ArrayBuffer
        ) {
            this.handleTtsAudioChunk(
                event.data,
            );

            return;
        }

        if (
            typeof event.data
            !== 'string'
        ) {
            return;
        }

        let payload;

        try {
            payload =
                JSON.parse(
                    event.data,
                );
        } catch {
            this.setError(
                'Live pipeline returned invalid JSON.',
            );

            return;
        }

        if (
            !payload
            || typeof payload
                !== 'object'
        ) {
            return;
        }

        if (
            payload.type
            === 'session_started'
        ) {
            this.sessionStarted =
                true;

            this.resolveSessionStart();

            return;
        }

        if (
            payload.type
            === 'transcript'
        ) {
            if (
                typeof payload.text
                === 'string'
            ) {
                this.transcription =
                    payload.text;
            }

            if (
                payload.is_final === true
                && this.isStopping
            ) {
                this.status =
                    'Translating';
            }

            return;
        }

        if (
            payload.type
            === 'translation_completed'
        ) {
            this.handleTranslationCompleted(
                payload,
            );

            return;
        }

        if (
            payload.type
            === 'tts_started'
        ) {
            this.handleTtsStarted(
                payload,
            );

            return;
        }

        if (
            payload.type
            === 'completed'
        ) {
            this.handleCompleted(
                payload,
            );

            return;
        }

        if (
            payload.type
            === 'error'
        ) {
            const message =
                typeof payload.message
                    === 'string'
                    ? payload.message
                    : 'Live pipeline failed.';

            if (
                !this.sessionStarted
            ) {
                this.rejectSessionStart(
                    new Error(
                        message,
                    ),
                );

                return;
            }

            this.setError(
                message,
            );
        }
    },

    handleTranslationCompleted(payload) {
        if (
            typeof payload.text
            === 'string'
        ) {
            this.transcription =
                payload.text;
        }

        if (
            typeof payload.translated_text
            === 'string'
        ) {
            this.translatedText =
                payload.translated_text;
        }

        this.applyTranslationMetrics(
            payload,
        );

        if (
            this.stopStartedAt
            !== null
        ) {
            this.browserStopToTranslatedMs =
                this.roundMilliseconds(
                    performance.now()
                    - this.stopStartedAt,
                );
        }

        this.status =
            'Synthesizing speech';
    },

    handleTtsStarted(payload) {
        if (
            typeof payload.sample_rate_hz
            === 'number'
        ) {
            this.ttsSampleRate =
                payload.sample_rate_hz;
        }

        if (
            typeof payload.channels
            === 'number'
        ) {
            this.ttsChannels =
                payload.channels;
        }

        if (
            typeof payload.bytes_per_sample
            === 'number'
        ) {
            this.ttsBytesPerSample =
                payload.bytes_per_sample;
        }

        this.status =
            'Streaming translated speech';
    },

    handleTtsAudioChunk(arrayBuffer) {
        if (
            !this.firstAudioReceived
            && this.stopStartedAt !== null
        ) {
            this.firstAudioReceived =
                true;

            this.browserStopToFirstAudioReceivedMs =
                this.roundMilliseconds(
                    performance.now()
                    - this.stopStartedAt,
                );
        }

        try {
            this.schedulePcm16Chunk(
                arrayBuffer,
            );
        } catch (error) {
            this.setError(
                error instanceof Error
                    ? error.message
                    : 'Could not play translated speech.',
            );
        }
    },

    schedulePcm16Chunk(arrayBuffer) {
        if (!this.audioContext) {
            throw new Error(
                'Audio playback context is not available.',
            );
        }

        if (this.ttsChannels !== 1) {
            throw new Error(
                'Only mono TTS audio is currently supported.',
            );
        }

        if (this.ttsBytesPerSample !== 2) {
            throw new Error(
                'Only PCM16 TTS audio is currently supported.',
            );
        }

        if (
            arrayBuffer.byteLength === 0
            || arrayBuffer.byteLength % 2 !== 0
        ) {
            throw new Error(
                'Received invalid PCM16 audio chunk.',
            );
        }

        const view =
            new DataView(
                arrayBuffer,
            );

        const sampleCount =
            arrayBuffer.byteLength / 2;

        const samples =
            new Float32Array(
                sampleCount,
            );

        let sumSquares = 0;

        for (
            let index = 0;
            index < sampleCount;
            index += 1
        ) {
            const sample =
                view.getInt16(
                    index * 2,
                    true,
                );

            samples[index] =
                sample / 32768;

            sumSquares +=
                sample * sample;
        }

        const rms =
            Math.sqrt(
                sumSquares
                / sampleCount,
            );

        const buffer =
            this.audioContext.createBuffer(
                1,
                sampleCount,
                this.ttsSampleRate,
            );

        buffer.copyToChannel(
            samples,
            0,
        );

        const source =
            this.audioContext.createBufferSource();

        source.buffer =
            buffer;

        source.connect(
            this.audioContext.destination,
        );

        const now =
            this.audioContext.currentTime;

        if (
            this.nextPlaybackTime
            <= now + 0.005
        ) {
            this.nextPlaybackTime =
                now + 0.05;
        }

        const startAt =
            this.nextPlaybackTime;

        this.nextPlaybackTime +=
            buffer.duration;

        this.audioSources.push(
            source,
        );

        source.addEventListener(
            'ended',
            () => {
                source.disconnect();

                this.audioSources =
                    this.audioSources.filter(
                        (item) =>
                            item !== source,
                    );

                if (
                    this.runCompleted
                    && this.audioSources.length
                        === 0
                ) {
                    this.status =
                        'Completed';
                }
            },
            {
                once: true,
            },
        );

        source.start(
            startAt,
        );

        if (
            rms >= this.audibleRmsThreshold
            && !this.firstAudiblePlaybackScheduled
        ) {
            this.firstAudiblePlaybackScheduled =
                true;

            this.scheduleFirstAudibleMeasurement(
                startAt,
            );
        }
    },

    scheduleFirstAudibleMeasurement(startAt) {
        if (
            !this.audioContext
            || this.stopStartedAt === null
        ) {
            return;
        }

        const outputLatencySeconds =
            typeof this.audioContext.outputLatency
                === 'number'
                ? this.audioContext.outputLatency
                : 0;

        const delayMs =
            Math.max(
                0,
                (
                    startAt
                    - this.audioContext.currentTime
                    + outputLatencySeconds
                ) * 1000,
            );

        this.audiblePlaybackTimer =
            window.setTimeout(
                () => {
                    if (
                        this.stopStartedAt
                        === null
                    ) {
                        return;
                    }

                    this.browserStopToFirstAudiblePlaybackMs =
                        this.roundMilliseconds(
                            performance.now()
                            - this.stopStartedAt,
                        );

                    if (!this.runCompleted) {
                        this.status =
                            'Playing translated speech';
                    }
                },
                delayMs,
            );
    },

    handleCompleted(payload) {
        if (
            typeof payload.text
            === 'string'
        ) {
            this.transcription =
                payload.text;
        }

        if (
            typeof payload.translated_text
            === 'string'
        ) {
            this.translatedText =
                payload.translated_text;
        }

        this.applyTranslationMetrics(
            payload,
        );

        if (
            typeof payload.tts_first_audio_ms
            === 'number'
        ) {
            this.ttsFirstAudioMs =
                payload.tts_first_audio_ms;
        }

        if (
            typeof payload.server_stop_to_first_tts_audio_ms
            === 'number'
        ) {
            this.serverStopToFirstTtsAudioMs =
                payload.server_stop_to_first_tts_audio_ms;
        }

        if (
            typeof payload.tts_duration_ms
            === 'number'
        ) {
            this.ttsDurationMs =
                payload.tts_duration_ms;
        }

        if (
            typeof payload.server_stop_to_tts_completed_ms
            === 'number'
        ) {
            this.serverStopToTtsCompletedMs =
                payload.server_stop_to_tts_completed_ms;
        }

        if (
            typeof payload.translated_audio_duration_ms
            === 'number'
        ) {
            this.translatedAudioDurationMs =
                payload.translated_audio_duration_ms;
        }

        if (
            this.stopStartedAt
            !== null
        ) {
            this.browserStopToCompletedMs =
                this.roundMilliseconds(
                    performance.now()
                    - this.stopStartedAt,
                );
        }

        this.runCompleted =
            true;

        this.isStopping =
            false;

        this.status =
            this.audioSources.length > 0
                ? 'Playing translated speech'
                : 'Completed';

        this.closeSocket();
    },

    applyTranslationMetrics(payload) {
        if (
            typeof payload.server_stop_to_stt_ms
            === 'number'
        ) {
            this.serverStopToSttMs =
                payload.server_stop_to_stt_ms;
        }

        if (
            typeof payload.translation_ms
            === 'number'
        ) {
            this.translationMs =
                payload.translation_ms;
        }

        if (
            typeof payload.server_stop_to_translation_ms
            === 'number'
        ) {
            this.serverStopToTranslationMs =
                payload.server_stop_to_translation_ms;
        }
    },

    async prepareAudioPlayback() {
        const AudioContextClass =
            window.AudioContext
            || window.webkitAudioContext;

        if (!AudioContextClass) {
            throw new Error(
                'Web Audio playback is not supported by this browser.',
            );
        }

        if (!this.audioContext) {
            this.audioContext =
                new AudioContextClass();
        }

        if (
            this.audioContext.state
            === 'suspended'
        ) {
            await this.audioContext.resume();
        }

        this.nextPlaybackTime =
            this.audioContext.currentTime;
    },

    stopAudioPlayback() {
        this.audioSources.forEach(
            (source) => {
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
            },
        );

        this.audioSources = [];
        this.nextPlaybackTime = 0;

        if (
            this.audiblePlaybackTimer
            !== null
        ) {
            window.clearTimeout(
                this.audiblePlaybackTimer,
            );

            this.audiblePlaybackTimer =
                null;
        }
    },

    resolveSessionStart() {
        this.clearSessionTimeout();

        const resolve =
            this.sessionResolve;

        this.sessionResolve = null;
        this.sessionReject = null;

        if (resolve) {
            resolve();
        }
    },

    rejectSessionStart(error) {
        this.clearSessionTimeout();

        const reject =
            this.sessionReject;

        this.sessionResolve = null;
        this.sessionReject = null;

        if (reject) {
            reject(
                error,
            );
        }
    },

    clearSessionTimeout() {
        if (
            this.sessionTimeout
            === null
        ) {
            return;
        }

        window.clearTimeout(
            this.sessionTimeout,
        );

        this.sessionTimeout = null;
    },

    getSupportedMimeType() {
        const types = [
            'audio/webm;codecs=opus',
            'audio/webm',
        ];

        return types.find(
            (type) =>
                MediaRecorder.isTypeSupported(
                    type,
                ),
        ) ?? null;
    },

    stopMediaStream() {
        if (!this.mediaStream) {
            return;
        }

        this.mediaStream
            .getTracks()
            .forEach(
                (track) =>
                    track.stop(),
            );

        this.mediaStream =
            null;
    },

    closeSocket() {
        if (!this.socket) {
            return;
        }

        this.socketCloseExpected =
            true;

        if (
            this.socket.readyState
                === WebSocket.OPEN
            || this.socket.readyState
                === WebSocket.CONNECTING
        ) {
            this.socket.close();
        }

        this.socket =
            null;

        this.sessionStarted =
            false;
    },

    resetRun() {
        this.clearSessionTimeout();

        this.closeSocket();
        this.stopMediaStream();
        this.stopAudioPlayback();

        this.status = 'Idle';
        this.error = null;

        this.transcription = '';
        this.translatedText = '';

        this.serverStopToSttMs = null;
        this.translationMs = null;
        this.serverStopToTranslationMs = null;

        this.ttsFirstAudioMs = null;
        this.serverStopToFirstTtsAudioMs = null;
        this.ttsDurationMs = null;
        this.serverStopToTtsCompletedMs = null;

        this.browserStopToTranslatedMs = null;
        this.browserStopToFirstAudioReceivedMs = null;
        this.browserStopToFirstAudiblePlaybackMs = null;
        this.browserStopToCompletedMs = null;

        this.translatedAudioDurationMs = null;

        this.mimeType = null;
        this.stopStartedAt = null;

        this.sessionStarted = false;
        this.sessionResolve = null;
        this.sessionReject = null;

        this.socketCloseExpected = false;
        this.runCompleted = false;
        this.aborted = false;

        this.firstAudioReceived = false;
        this.firstAudiblePlaybackScheduled = false;
    },

    roundMilliseconds(value) {
        return Math.round(
            value * 100,
        ) / 100;
    },

    setError(message) {
        if (this.error) {
            return;
        }

        this.error =
            message;

        this.status =
            'Error';

        this.aborted =
            true;

        this.isConnecting =
            false;

        this.isRecording =
            false;

        this.isStopping =
            false;

        this.rejectSessionStart(
            new Error(
                message,
            ),
        );

        if (
            this.mediaRecorder
            && this.mediaRecorder.state
                === 'recording'
        ) {
            this.mediaRecorder.stop();
        }

        this.stopMediaStream();
        this.closeSocket();
        this.stopAudioPlayback();
    },
});