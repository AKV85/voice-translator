export default function benchmarkRecorder(config) {
    return {
        phrases: config.phrases,
        storeUrl: config.storeUrl,
        csrfToken: config.csrfToken,

        currentIndex: 0,

        mediaRecorder: null,
        mediaStream: null,
        chunks: [],

        audioBlob: null,
        audioUrl: null,

        isRecording: false,
        isSaving: false,

        error: null,

        init() {
            const firstIncompleteIndex = this.phrases.findIndex(
                (phrase) => !phrase.completed,
            );

            this.currentIndex = firstIncompleteIndex >= 0
                ? firstIncompleteIndex
                : 0;
        },

        get currentPhrase() {
            return this.phrases[this.currentIndex] ?? null;
        },

        get completedCount() {
            return this.phrases.filter((phrase) => phrase.completed).length;
        },

        get totalCount() {
            return this.phrases.length;
        },

        get allCompleted() {
            return this.completedCount === this.totalCount;
        },

        async startRecording() {
            this.error = null;
            this.clearRecording();

            try {
                const mimeType = this.getSupportedMimeType();

                if (!mimeType) {
                    throw new Error('WebM audio recording is not supported by this browser.');
                }

                this.mediaStream = await navigator.mediaDevices.getUserMedia({
                    audio: true,
                });

                this.mediaRecorder = new MediaRecorder(
                    this.mediaStream,
                    { mimeType },
                );

                this.chunks = [];

                this.mediaRecorder.addEventListener('dataavailable', (event) => {
                    if (event.data.size > 0) {
                        this.chunks.push(event.data);
                    }
                });

                this.mediaRecorder.addEventListener('stop', () => {
                    this.audioBlob = new Blob(this.chunks, {
                        type: this.mediaRecorder.mimeType,
                    });

                    this.audioUrl = URL.createObjectURL(this.audioBlob);

                    this.stopMediaStream();
                });

                this.mediaRecorder.start();
                this.isRecording = true;
            } catch (error) {
                this.error = error instanceof Error
                    ? error.message
                    : 'Could not start audio recording.';

                this.stopMediaStream();
            }
        },

        stopRecording() {
            if (
                !this.mediaRecorder
                || this.mediaRecorder.state !== 'recording'
            ) {
                return;
            }

            this.mediaRecorder.stop();
            this.isRecording = false;
        },

        async acceptRecording() {
            if (!this.audioBlob || !this.currentPhrase) {
                return;
            }

            this.error = null;
            this.isSaving = true;

            try {
                const formData = new FormData();

                formData.append(
                    'phrase_id',
                    this.currentPhrase.id,
                );

                formData.append(
                    'audio',
                    this.audioBlob,
                    `${this.currentPhrase.id}.webm`,
                );

                const response = await fetch(this.storeUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: formData,
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => null);

                    throw new Error(
                        data?.message ?? 'Could not save benchmark audio.',
                    );
                }

                this.currentPhrase.completed = true;

                this.clearRecording();
                this.goToNextIncompletePhrase();
            } catch (error) {
                this.error = error instanceof Error
                    ? error.message
                    : 'Could not save benchmark audio.';
            } finally {
                this.isSaving = false;
            }
        },

        rerecord() {
            this.clearRecording();
        },

        goToPhrase(index) {
            if (this.isRecording || this.isSaving) {
                return;
            }

            this.clearRecording();
            this.currentIndex = index;
        },

        goToNextIncompletePhrase() {
            const nextIndex = this.phrases.findIndex(
                (phrase, index) => (
                    index > this.currentIndex
                    && !phrase.completed
                ),
            );

            if (nextIndex >= 0) {
                this.currentIndex = nextIndex;

                return;
            }

            const firstIncompleteIndex = this.phrases.findIndex(
                (phrase) => !phrase.completed,
            );

            if (firstIncompleteIndex >= 0) {
                this.currentIndex = firstIncompleteIndex;
            }
        },

        clearRecording() {
            if (this.audioUrl) {
                URL.revokeObjectURL(this.audioUrl);
            }

            this.audioBlob = null;
            this.audioUrl = null;
            this.chunks = [];
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

        getSupportedMimeType() {
            const types = [
                'audio/webm;codecs=opus',
                'audio/webm',
            ];

            return types.find((type) => (
                MediaRecorder.isTypeSupported(type)
            )) ?? null;
        },
    };
}