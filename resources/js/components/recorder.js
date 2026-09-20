export default (config = {}) => ({
    status: 'Idle',
    isRecording: false,
    isTranscribing: false,
    error: null,

    sourceLanguage: 'en',
    transcription: '',

    transcribeUrl: config.transcribeUrl ?? '/transcribe',

    mediaRecorder: null,
    mediaStream: null,
    audioChunks: [],
    audioBlob: null,
    audioUrl: null,
    mimeType: null,

    async startRecording() {
        if (this.isRecording || this.isTranscribing) {
            return;
        }

        this.resetRecording();

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

        try {
            this.mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });

            const mimeType = this.getSupportedMimeType();

            this.mediaRecorder = mimeType
                ? new MediaRecorder(this.mediaStream, { mimeType })
                : new MediaRecorder(this.mediaStream);

            this.mediaRecorder.addEventListener(
                'dataavailable',
                (event) => {
                    if (event.data.size > 0) {
                        this.audioChunks.push(event.data);
                    }
                },
            );

            this.mediaRecorder.addEventListener(
                'stop',
                async () => {
                    await this.finishRecording();
                },
            );

            this.mediaRecorder.addEventListener(
                'error',
                (event) => {
                    this.setError(
                        event.error?.message ?? 'Audio recording failed.',
                    );

                    this.stopMediaStream();
                },
            );

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

    async finishRecording() {
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

        this.stopMediaStream();
        this.mediaRecorder = null;

        if (this.audioBlob.size === 0) {
            this.setError('The recorded audio is empty.');

            return;
        }

        await this.transcribeRecording();
    },

    async transcribeRecording() {
        if (!this.audioBlob || this.isTranscribing) {
            return;
        }

        this.error = null;
        this.transcription = '';
        this.isTranscribing = true;
        this.status = 'Transcribing';

        try {
            const formData = new FormData();

            formData.append(
                'audio',
                this.audioBlob,
                this.getAudioFileName(),
            );

            formData.append(
                'language',
                this.sourceLanguage,
            );

            const csrfToken = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            if (!csrfToken) {
                throw new Error('CSRF token is missing.');
            }

            const response = await fetch(this.transcribeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: formData,
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message ?? 'Speech transcription failed.',
                );
            }

            if (!data.text) {
                throw new Error(
                    'Speech transcription returned an empty result.',
                );
            }

            this.transcription = data.text;
            this.status = 'Transcribed';
        } catch (error) {
            this.setError(
                error instanceof Error
                    ? error.message
                    : 'Speech transcription failed.',
            );
        } finally {
            this.isTranscribing = false;
        }
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

    getAudioFileName() {
        const mimeType = this.mimeType
            ?.split(';')[0]
            ?.toLowerCase();

        const extensions = {
            'audio/webm': 'webm',
            'video/webm': 'webm',
            'audio/ogg': 'ogg',
            'audio/mp4': 'mp4',
            'video/mp4': 'mp4',
            'audio/mpeg': 'mp3',
            'audio/wav': 'wav',
            'audio/x-wav': 'wav',
        };

        const extension = extensions[mimeType] ?? 'webm';

        return `recording.${extension}`;
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
        this.transcription = '';
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
            NotReadableError:
                'The microphone is already in use or unavailable.',
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