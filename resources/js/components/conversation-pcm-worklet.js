/* VT-21: microphone Float32 -> mono PCM16 @ 24000 Hz.
 * Browser audio processing code, loaded with audioContext.audioWorklet.addModule().
 * Worklet buffers ~100 ms per message. No network access from audio thread.
 */
class ConversationPcmProcessor extends AudioWorkletProcessor {
    constructor() {
        super();
        this.targetRate = 24000;
        this.sourceRate = sampleRate;
        this.sourceSamplesPerOutput = this.sourceRate / this.targetRate;
        this.sourcePosition = 0;
        this.previousSample = 0;
        this.sourceSamplesSeen = 0;
        this.buffer = new Int16Array(2400);
        this.bufferLength = 0;
        this.stopped = false;
        this.port.onmessage = (event) => {
            if (event.data?.type === "stop") {
                this.stopped = true;
                this.emit();
                this.port.postMessage({ type: "stopped" });
            }
        };
    }

    emit() {
        if (this.bufferLength === 0) return;
        const pcm = new Int16Array(this.bufferLength);
        pcm.set(this.buffer.subarray(0, this.bufferLength));
        this.port.postMessage({ type: "audio", pcm: pcm.buffer }, [pcm.buffer]);
        this.bufferLength = 0;
    }

    append(value) {
        // WebAudio samples are normally [-1, 1], but explicitly clamp.
        const v = Math.max(-1, Math.min(1, value));
        this.buffer[this.bufferLength++] =
            v < 0 ? Math.round(v * 32768) : Math.round(v * 32767);
        if (this.bufferLength === this.buffer.length) this.emit();
    }

    process(inputs) {
        if (this.stopped) return false;
        const channels = inputs[0];
        if (!channels || !channels.length || !channels[0].length) return true;
        const length = channels[0].length;
        // Average channels to mono. Process every frame even if there is no output.
        for (let i = 0; i < length; i++) {
            let mono = 0;
            for (const channel of channels) mono += channel[i];
            mono /= channels.length;
            // Interpolate samples on absolute input-sample timeline.
            const currentIndex = this.sourceSamplesSeen++;
            while (this.sourcePosition <= currentIndex) {
                const fraction = this.sourcePosition - (currentIndex - 1);
                const output =
                    currentIndex === 0
                        ? mono
                        : this.previousSample +
                          (mono - this.previousSample) * fraction;
                this.append(output);
                this.sourcePosition += this.sourceSamplesPerOutput;
            }
            this.previousSample = mono;
        }
        return true;
    }
}

registerProcessor("conversation-pcm-processor", ConversationPcmProcessor);
