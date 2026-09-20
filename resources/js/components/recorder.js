export default () => ({
    status: 'Idle',
    isRecording: false,
    error: null,

    mediaRecorder: null,
    mediaStream: null,
    audioChunks: [],
    audioBlob: null,
    audioUrl: null,
    mimeType: null,

    async startRecording() {
        if (this.isRecording) {
            return;
        }

        this.resetRecording();

        if (!navigator.mediaDevices?.getUserMedia) {
            this.setError('Microphone access is not supported by this browser.');

            return;
        }

        if (!window.MediaRecorder) {
            this.setError('Audio recording is not supported by this browser.');

            return;
        }

        try {
            this.mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });

            const mimeType = this.getSupportedMimeType();

            this.mediaRecorder = mimeType
                ? new MediaRecorder(this.mediaStream, { mimeType })
                : new MediaRecorder(this.mediaStream);

            this.mediaRecorder.addEventListener('dataavailable', (event) => {
                if (event.data.size > 0) {
                    this.audioChunks.push(event.data);
                }
            });

            this.mediaRecorder.addEventListener('stop', () => {
                this.finishRecording();
            });

            this.mediaRecorder.addEventListener('error', (event) => {
                this.setError(
                    event.error?.message ?? 'Audio recording failed.',
                );

                this.stopMediaStream();
            });

            this.mediaRecorder.start();

            this.isRecording = true;
            this.status = 'Recording';
        } catch (error) {
            this.handleMicrophoneError(error);
            this.stopMediaStream();
        }
    },

    stopRecording() {
        if (
            !this.mediaRecorder
            || this.mediaRecorder.state === 'inactive'
        ) {
            return;
        }

        this.isRecording = false;
        this.mediaRecorder.stop();
    },

    finishRecording() {
        const recordedMimeType =
            this.mediaRecorder?.mimeType
            || this.audioChunks[0]?.type
            || '';

        this.audioBlob = recordedMimeType
            ? new Blob(this.audioChunks, { type: recordedMimeType })
            : new Blob(this.audioChunks);

        this.mimeType = this.audioBlob.type || null;

        if (this.audioUrl) {
            URL.revokeObjectURL(this.audioUrl);
        }

        this.audioUrl = URL.createObjectURL(this.audioBlob);

        this.status = 'Recorded';

        this.stopMediaStream();
        this.mediaRecorder = null;
    },

    getSupportedMimeType() {
        const mimeTypes = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/mp4',
        ];

        return mimeTypes.find((mimeType) =>
            MediaRecorder.isTypeSupported(mimeType)
        ) ?? null;
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

    resetRecording() {
        this.error = null;
        this.audioChunks = [];
        this.audioBlob = null;
        this.mimeType = null;

        if (this.audioUrl) {
            URL.revokeObjectURL(this.audioUrl);
            this.audioUrl = null;
        }
    },

    handleMicrophoneError(error) {
        const messages = {
            NotAllowedError: 'Microphone permission was denied.',
            NotFoundError: 'No microphone was found.',
            NotReadableError: 'The microphone is already in use or unavailable.',
        };

        this.setError(
            messages[error.name]
            ?? 'Unable to access the microphone.',
        );
    },

    setError(message) {
        this.error = message;
        this.status = 'Error';
        this.isRecording = false;
    },
});