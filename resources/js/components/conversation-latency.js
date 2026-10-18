// All client timestamps use performance.now(). Server values are durations only.
const SERVER_STAGES = [
    "startup_ms",
    "translation_ms",
    "translation_to_first_pcm_ms",
    "total_ms",
];

export class LatencyObservations {
    constructor(limit = 256) {
        this.limit = limit;
        this.samples = new Map();
    }

    observe(stage, value) {
        if (typeof value !== "number" || !Number.isFinite(value) || value < 0)
            return;
        const values = this.samples.get(stage) ?? [];
        values.push(value);
        if (values.length > this.limit) values.shift();
        this.samples.set(stage, values);
    }

    summary() {
        return Object.fromEntries(
            [...this.samples].map(([stage, values]) => {
                const sorted = [...values].sort((a, b) => a - b);
                const n = sorted.length;
                return [
                    stage,
                    {
                        count: n,
                        median:
                            n % 2
                                ? sorted[(n - 1) / 2]
                                : (sorted[n / 2 - 1] + sorted[n / 2]) / 2,
                        p95: sorted[Math.ceil(n * 0.95) - 1],
                        max: sorted[n - 1],
                    },
                ];
            }),
        );
    }
}

export class ConversationLatency {
    constructor({ enabled = false, now = () => performance.now() } = {}) {
        this.enabled = enabled;
        this.now = now;
        this.observations = new LatencyObservations();
        this.reset();
    }

    reset() {
        this.turn = null;
        this.nextIndex = 1;
        this.current = null;
        this.records = new Map();
        this.generation = (this.generation ?? 0) + 1;
    }

    begin(turnId) {
        this.turn = turnId ?? null;
        this.nextIndex = 1;
        this.current = null;
        // Prior turn audio may still drain on the shared playback timeline.
    }

    accept(message, translationOnly = false) {
        const {
            conversation_turn_id: turn,
            segment_id: id,
            segment_index: index,
        } = message;
        // Original servers have no correlation fields. Preserve their protocol.
        if (turn === undefined && id === undefined && index === undefined)
            return true;
        if (
            typeof id !== "string" ||
            !/^[a-f0-9]{32}$/.test(id) ||
            turn !== this.turn ||
            !Number.isSafeInteger(index)
        )
            return false;
        if (message.type === "conversation_translation_completed") {
            if (
                this.current ||
                index !== this.nextIndex ||
                this.records.has(id)
            )
                return false;
            if (translationOnly) {
                this.nextIndex++;
                return true;
            }
            this.current = {
                id,
                index,
                phase: "translation",
                pending: 0,
                generation: this.generation,
            };
            this.records.set(id, this.current);
            // Bound debug state even when speaker output is unavailable.
            if (this.records.size > 128)
                this.records.delete(this.records.keys().next().value);
            return true;
        }
        const record = this.current;
        if (!record || record.id !== id || record.index !== index) return false;
        if (
            message.type === "conversation_tts_started" &&
            record.phase === "translation"
        ) {
            record.phase = "audio";
            if (this.enabled) record.ttsReceived = this.now();
            return true;
        }
        if (
            message.type === "conversation_completed" &&
            record.phase === "audio"
        ) {
            record.complete = true;
            this.current = null;
            this.nextIndex++;
            if (this.enabled) {
                for (const stage of SERVER_STAGES) {
                    this.observations.observe(
                        `server_${stage}`,
                        message.server_latency?.[stage],
                    );
                }
            }
            this.finish(record);
            return true;
        }
        return false;
    }

    receipt() {
        const record = this.current;
        if (!record || record.phase !== "audio") return null;
        const at = this.enabled ? this.now() : undefined;
        if (record.firstReceipt === undefined) {
            record.firstReceipt = at;
            if (this.enabled)
                this.observations.observe(
                    "tts_event_to_first_pcm_receipt_ms",
                    at - record.ttsReceived,
                );
        }
        return { record, at, generation: this.generation };
    }

    queued(ticket, contextTime, scheduledTime) {
        if (!ticket || ticket.generation !== this.generation) return;
        const record = ticket.record;
        const queuedAt = this.enabled ? this.now() : undefined;
        record.pending++;
        if (this.enabled) {
            this.observations.observe(
                "pcm_receipt_to_queue_ms",
                queuedAt - ticket.at,
            );
            this.observations.observe(
                "queue_to_scheduled_start_estimate_ms",
                (scheduledTime - contextTime) * 1000,
            );
        }
    }

    ended(ticket) {
        if (!ticket || ticket.generation !== this.generation) return;
        const record = ticket.record;
        record.pending--;
        if (this.enabled) record.lastEnded = this.now();
        this.finish(record);
    }

    finish(record) {
        if (!record.complete || record.pending !== 0) return;
        if (
            this.enabled &&
            record.firstReceipt !== undefined &&
            record.lastEnded !== undefined
        ) {
            this.observations.observe(
                "first_pcm_receipt_to_playback_end_callback_ms",
                record.lastEnded - record.firstReceipt,
            );
        }
        this.records.delete(record.id);
    }

    summary() {
        return this.enabled ? this.observations.summary() : {};
    }
}
