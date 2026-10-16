function defaultWebSocketUrl() {
    const scheme = window.location.protocol === "https:" ? "wss" : "ws";

    return `${scheme}://${window.location.hostname}:8081`;
}

export default (config = {}) => ({
    wsUrl: config.wsUrl || defaultWebSocketUrl(),
    createUrl: config.createUrl || "/conversation/create",
    joinUrl: config.joinUrl || "/conversation/join",

    code: "",
    token: "",
    role: null,
    joinCode: "",
    peerConnected: false,
    connected: false,
    busy: false,
    status: "Not connected",
    error: null,
    socket: null,
    turnBusy: false,
    isRecording: false,
    isStopping: false,
    recordingCancelled: false,
    mediaRecorder: null,
    mediaStream: null,
    sendQueue: null,
    transcription: "",
    translatedText: "",
    audioStatus: "No audio received",
    audioChunks: [],
    receivingAudio: false,
    audioContext: null,
    audioEnabled: false,
    lastAudioBytes: 0,
    playbackNextTime: 0,
    playbackPending: 0,
    stopSentAt: null,
    firstAudioLogged: false,


    get canSpeak() {
        return this.connected && this.peerConnected
            && !this.busy && !this.turnBusy
            && !this.isRecording && !this.isStopping;
    },

    async createRoom() {
        await this.openRoom(this.createUrl, {});
    },

    async joinRoom() {
        await this.openRoom(this.joinUrl, {
            code: this.joinCode.trim().toUpperCase(),
        });
    },

    async openRoom(url, data) {
        if (this.busy || this.socket) return;

        this.busy = true;
        this.error = null;

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

            const response = await fetch(url, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                    "X-CSRF-TOKEN": csrf || "",
                },
                body: JSON.stringify(data),
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || "Could not open conversation.");
            }

            this.code = result.code;
            this.token = result.token;

            await this.connect();
        } catch (error) {
            this.error = error.message;
            this.status = "Failed";
        } finally {
            this.busy = false;
        }
    },

    connect() {
        return new Promise((resolve, reject) => {
            const socket = new WebSocket(this.wsUrl);
            socket.binaryType = "arraybuffer";
            this.socket = socket;

            let settled = false;

            socket.onopen = () => {
                socket.send(JSON.stringify({
                    type: "conversation_join",
                    code: this.code,
                    token: this.token,
                }));
            };

            socket.onmessage = (event) => {
                if (typeof event.data !== "string") {
                    this.receiveAudio(event.data);
                    return;
                }

                let message;

                try {
                    message = JSON.parse(event.data);
                } catch {
                    return;
                }

                switch (message.type) {
                    case "conversation_joined":
                        this.connected = true;
                        this.role = message.role;
                        this.peerConnected = !!message.peer_connected;
                        this.status = this.peerConnected
                            ? "Connected to peer"
                            : "Waiting for peer";

                        settled = true;
                        resolve();
                        break;

                    case "conversation_peer_joined":
                        this.peerConnected = true;
                        this.status = "Connected to peer";
                        break;

                    case "conversation_peer_left":
                        this.turnBusy = false;
                        this.isStopping = false;
                        this.releaseMicrophone();
                        this.peerConnected = false;
                        this.status = "Waiting for peer";
                        break;

                    case "conversation_turn_started":
                        this.transcription = "";
                        this.translatedText = "";
                        this.turnBusy = true;
                        this.beginRecording();
                        break;

                    case "conversation_peer_started":
                        this.transcription = "";
                        this.translatedText = "";
                        this.turnBusy = true;
                        this.status = "Peer is speaking";
                        break;

                    case "conversation_turn_busy":
                        this.status = "Turn is busy";
                        this.releaseMicrophone();
                        break;

                    case "conversation_transcript":
                    case "conversation_recognition_completed":
                        this.transcription = message.text || "";
                        this.status = "Processing translation";
                        break;

                    case "conversation_translation_completed":
                        this.transcription = message.text || "";
                        this.translatedText = message.translated_text || "";
                        this.status = "Translation received";
                        break;

                    case "conversation_tts_started":
                        if (
                            message.format !== "pcm16"
                            || message.sample_rate_hz !== 24000
                            || message.channels !== 1
                            || message.bytes_per_sample !== 2
                        ) {
                            this.error = "Unsupported translated audio format.";
                            this.receivingAudio = false;
                            break;
                        }

                        this.audioChunks = [];
                        this.lastAudioBytes = 0;
                        this.playbackNextTime = 0;
                        this.receivingAudio = true;
                        this.audioStatus = "Receiving translated audio";
                        break;

                    case "conversation_completed":
                        this.receivingAudio = false;
                        this.playReceivedAudio();
                        break;

                    case "conversation_turn_stopped":
                    case "conversation_peer_stopped":
                        this.turnBusy = false;
                        this.isStopping = false;
                        this.releaseMicrophone();
                        this.status = "Ready for next speaker";
                        break;

                    case "error":
                        this.error = message.message || "WebSocket error";

                        if (this.turnBusy || this.isRecording || this.isStopping) {
                            this.recordingCancelled = true;
                            this.turnBusy = false;
                            this.isStopping = false;
                            this.releaseMicrophone();
                            this.status = "Recording failed";
                        }

                        if (!settled) {
                            settled = true;
                            reject(new Error(this.error));
                        }
                        break;
                }
            };

            socket.onerror = () => {
                if (!settled) {
                    settled = true;
                    reject(new Error("WebSocket connection failed."));
                }
            };

            socket.onclose = () => {
                this.connected = false;
                this.peerConnected = false;
                this.turnBusy = false;
                this.isStopping = false;
                this.releaseMicrophone();
                this.socket = null;
                this.status = "Disconnected";

                if (!settled) {
                    settled = true;
                    reject(new Error("WebSocket closed before joining."));
                }
            };
        });
    },

    async startSpeaking() {
        if (!this.canSpeak) return;

        this.error = null;
        this.recordingCancelled = false;
        this.busy = true;

        try {
            if (!navigator.mediaDevices?.getUserMedia
                || typeof MediaRecorder === "undefined") {
                throw new Error("Microphone recording is unavailable.");
            }

            const mimeType = [
                "audio/webm;codecs=opus",
                "audio/webm",
            ].find((type) => MediaRecorder.isTypeSupported(type));

            if (!mimeType) {
                throw new Error("WebM/Opus recording is unsupported.");
            }

            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });

            if (!this.connected || !this.peerConnected || this.turnBusy) {
                stream.getTracks().forEach((track) => track.stop());
                throw new Error("Conversation is no longer ready.");
            }

            this.mediaStream = stream;
            this.mediaRecorder = new MediaRecorder(stream, { mimeType });
            this.turnBusy = true;
            this.status = "Waiting for recording confirmation";

            this.socket.send(JSON.stringify({
                type: "conversation_start",
                mime_type: mimeType,
            }));
        } catch (error) {
            this.error = error.message;
            this.turnBusy = false;
            this.releaseMicrophone();
        } finally {
            this.busy = false;
        }
    },

    beginRecording() {
        const recorder = this.mediaRecorder;

        if (!recorder) {
            this.error = "Microphone was not initialized.";
            this.turnBusy = false;
            this.socket?.close();
            return;
        }

        this.sendQueue = Promise.resolve();

        recorder.ondataavailable = (event) => {
            if (event.data.size === 0) return;

            const socket = this.socket;

            this.sendQueue = this.sendQueue.then(async () => {
                if (this.recordingCancelled) return;

                const bytes = await event.data.arrayBuffer();

                if (this.recordingCancelled) return;

                if (socket?.readyState !== WebSocket.OPEN) {
                    throw new Error("WebSocket disconnected during recording.");
                }

                socket.send(bytes);
            }).catch((error) => {
                this.recordingCancelled = true;
                this.error = error.message;
                this.status = "Audio upload failed";
                this.socket?.close();
            });
        };

        recorder.onerror = () => {
            this.error = "Microphone recording failed.";
            this.socket?.close();
        };

        try {
            recorder.start(250);
            this.isRecording = true;
            this.status = "Recording";
        } catch (error) {
            this.error = error.message;
            this.socket?.close();
        }
    },

    async stopSpeaking() {
        if (!this.isRecording || this.isStopping) return;

        this.isStopping = true;
        this.status = "Finishing recording";

        const recorder = this.mediaRecorder;

        try {
            if (!recorder || recorder.state === "inactive") {
                throw new Error("Recorder is no longer active.");
            }

            const stopped = new Promise((resolve) => {
                recorder.addEventListener("stop", resolve, { once: true });
            });

            recorder.stop();
            await stopped;

            // The final dataavailable event has already run.
            // Wait until every binary frame has been sent in order.
            await this.sendQueue;

            if (this.recordingCancelled || !this.turnBusy) {
                return;
            }

            if (this.socket?.readyState !== WebSocket.OPEN) {
                throw new Error("WebSocket disconnected before stop.");
            }

            this.stopSentAt = performance.now();
            this.firstAudioLogged = false;

            this.socket.send(JSON.stringify({
                type: "conversation_stop",
            }));

            this.status = "Recognizing and translating";
        } catch (error) {
            this.error = error.message;
            // A failed upload must not leave server turn ownership stuck.
            this.socket?.close();
        } finally {
            this.releaseMicrophone();
        }
    },

    async enablePlayback() {
        try {
            const AudioContextClass =
                window.AudioContext || window.webkitAudioContext;

            if (!AudioContextClass) {
                throw new Error("Web Audio API is unavailable.");
            }

            if (!this.audioContext || this.audioContext.state === "closed") {
                this.audioContext = new AudioContextClass();
            }

            await this.audioContext.resume();

            this.audioEnabled = this.audioContext.state === "running";

            if (!this.audioEnabled) {
                throw new Error("Speaker could not be enabled.");
            }

            this.audioStatus = "Speaker ready";
            this.error = null;
        } catch (error) {
            this.audioEnabled = false;
            this.error = error.message;
        }
    },

    receiveAudio(data) {
        if (!this.receivingAudio) return;

        if (!(data instanceof ArrayBuffer) || data.byteLength % 2 !== 0) {
            this.error = "Invalid PCM16 audio frame.";
            this.receivingAudio = false;
            return;
        }

        const total = this.lastAudioBytes + data.byteLength;

        if (total > 2 * 1024 * 1024) {
            this.error = "Audio exceeds size limit.";
            this.receivingAudio = false;
            return;
        }

        this.lastAudioBytes = total;

        if (!this.firstAudioLogged) {
            this.firstAudioLogged = true;
            console.info("[Conversation latency] first PCM received", {
                timestamp: performance.now(),
            });
        }

        if (!this.audioEnabled || !this.audioContext) {
            this.audioStatus = "Enable speaker to hear translation";
            return;
        }

        if (data.byteLength === 0) return;

        const context = this.audioContext;

        if (context.state !== "running") {
            this.audioStatus = "Speaker is suspended";
            return;
        }

        const view = new DataView(data);
        const buffer = context.createBuffer(
            1,
            data.byteLength / 2,
            24000,
        );
        const samples = buffer.getChannelData(0);

        for (let i = 0; i < samples.length; i++) {
            samples[i] = view.getInt16(i * 2, true) / 32768;
        }

        const source = context.createBufferSource();
        source.buffer = buffer;
        source.connect(context.destination);

        const startTime = Math.max(
            context.currentTime + 0.04,
            this.playbackNextTime,
        );

        this.playbackNextTime = startTime + buffer.duration;
        this.playbackPending++;

        source.onended = () => {
            source.disconnect();
            this.playbackPending--;

            if (this.playbackPending === 0 && !this.receivingAudio) {
                this.audioStatus = "Playback finished";
            }
        };

        source.start(startTime);
        this.audioStatus = "Playing translation";
    },

    playReceivedAudio() {
        // Chunks are already scheduled when received.
        if (this.playbackPending === 0) {
            this.audioStatus = this.lastAudioBytes > 0
                ? "Playback finished"
                : "No translated audio received";
        }
    },

    releaseMicrophone() {
        const recorder = this.mediaRecorder;

        if (recorder) {
            recorder.ondataavailable = null;
            recorder.onerror = null;

            if (recorder.state !== "inactive") {
                recorder.stop();
            }
        }

        this.mediaStream?.getTracks().forEach((track) => track.stop());
        this.mediaRecorder = null;
        this.mediaStream = null;
        this.isRecording = false;
    },

    disconnect() {
        this.releaseMicrophone();
        this.turnBusy = false;
        this.isStopping = false;
        this.socket?.close();
        this.socket = null;
        this.connected = false;
        this.peerConnected = false;
        this.status = "Disconnected";
    },
});
