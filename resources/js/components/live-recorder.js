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

    serverStopToSttMs: null,
    browserStopToCompletedMs: null,

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

    get isBusy() {
        return this.isConnecting
            || this.isRecording
            || this.isStopping;
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
        this.status = 'Requesting microphone';

        try {
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

        /*
         * MediaRecorder emits the final dataavailable event
         * before the stop event.
         *
         * Therefore the final audio chunk has already been
         * queued on the WebSocket before this control message.
         */
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

    handleCompleted(payload) {
        if (
            typeof payload.text
            === 'string'
        ) {
            this.transcription =
                payload.text;
        }

        if (
            typeof payload.server_stop_to_stt_ms
            === 'number'
        ) {
            this.serverStopToSttMs =
                payload.server_stop_to_stt_ms;
        }

        if (
            this.stopStartedAt
            !== null
        ) {
            this.browserStopToCompletedMs =
                Math.round(
                    (
                        performance.now()
                        - this.stopStartedAt
                    )
                    * 100,
                ) / 100;
        }

        this.runCompleted = true;
        this.isStopping = false;
        this.status = 'Completed';

        this.closeSocket();
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

        this.status = 'Idle';
        this.error = null;

        this.transcription = '';

        this.serverStopToSttMs = null;
        this.browserStopToCompletedMs = null;

        this.mimeType = null;
        this.stopStartedAt = null;

        this.sessionStarted = false;
        this.sessionResolve = null;
        this.sessionReject = null;

        this.socketCloseExpected = false;
        this.runCompleted = false;
        this.aborted = false;
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
    },
});