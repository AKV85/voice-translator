// All client timestamps use performance.now(). Server values are durations only.
const SERVER_STAGES = [
    "startup_ms",
    "translation_ms",
    "translation_to_first_pcm_ms",
    "total_ms",
    "queue_wait_ms",
    "stt_completion_ms",
    "tts_request_ms",
    "tts_request_to_headers_ms",
    "tts_request_to_first_audio_ms",
    "first_pcm_emitted_ms",
    "first_audio_to_pcm_emit_ms",
    "final_to_first_pcm_send_ms",
    "stop_to_first_pcm_send_ms",
    "socket_write_ms",
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
    constructor({
        enabled = false,
        now = () => performance.now(),
        onStage = () => {},
    } = {}) {
        this.enabled = enabled;
        this.onStage = onStage;
        this.stops = new Map();
        this.remoteStops = new Map();
        this.acknowledgements = new Set();
        this.now = now;
        this.observations = new LatencyObservations();
        this.reset();
    }

    reset() {
        this.stops?.clear();
        this.remoteStops?.clear();
        this.acknowledgements?.clear();
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
                turn,
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
            this.report(record, "first_pcm_received");
            const stopAt = this.remoteStops.get(record.turn);
            if (this.enabled && stopAt !== undefined)
                this.observations.observe(
                    "receiver_stop_marker_to_first_pcm_ms",
                    at - stopAt,
                );
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
        if (!record.scheduled) {
            record.scheduled = true;
            ticket.first = true;
            record.firstQueued = queuedAt;
            record.firstScheduleDelay = (scheduledTime - contextTime) * 1000;
            this.report(record, "playback_scheduled");
        }
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

    // Called when the running AudioContext has reached the scheduled source time.
    // This observes the WebAudio timeline, not acoustic output from hardware.
    started(ticket) {
        if (
            !this.enabled ||
            !ticket?.first ||
            ticket.generation !== this.generation ||
            ticket.record.started
        )
            return;
        ticket.record.started = true;
        ticket.record.startedAt = this.now();
        this.observations.observe(
            "first_pcm_receipt_to_playback_start_observed_ms",
            this.now() - ticket.record.firstReceipt,
        );
        this.report(ticket.record, "playback_started");
    }

    report(record, stage) {
        if (!this.enabled) return;
        const local = {};
        if (stage === "first_pcm_received") {
            local.tts_event_to_first_pcm_receipt_ms =
                record.firstReceipt - record.ttsReceived;
            const stop = this.remoteStops.get(record.turn);
            if (stop !== undefined)
                local.receiver_stop_marker_to_first_pcm_ms =
                    record.firstReceipt - stop;
        } else if (stage === "playback_scheduled") {
            local.first_pcm_receipt_to_queue_ms =
                record.firstQueued - record.firstReceipt;
            local.queue_to_scheduled_start_estimate_ms =
                record.firstScheduleDelay;
        } else if (stage === "playback_started") {
            local.first_pcm_receipt_to_playback_start_observed_ms =
                record.startedAt - record.firstReceipt;
        }
        this.onStage({
            client_latency: local,
            conversation_turn_id: record.turn,
            segment_id: record.id,
            segment_index: record.index,
            stage,
            after_stop: this.remoteStops.has(record.turn),
        });
    }

    markStop() {
        if (!this.enabled || !this.turn) return;
        this.stops.set(this.turn, this.now());
        if (this.stops.size > 128)
            this.stops.delete(this.stops.keys().next().value);
    }

    controlSent() {
        const at = this.stops.get(this.turn);
        if (this.enabled && at !== undefined)
            this.observations.observe(
                "stop_to_control_send_ms",
                this.now() - at,
            );
    }

    stopReceived(message) {
        if (!this.enabled || message.conversation_turn_id !== this.turn) return;
        this.remoteStops.set(this.turn, this.now());
        if (this.remoteStops.size > 128)
            this.remoteStops.delete(this.remoteStops.keys().next().value);
    }

    acknowledge(message) {
        const stop = this.stops.get(message.conversation_turn_id);
        if (
            !this.enabled ||
            message.after_stop !== true ||
            stop === undefined ||
            !/^[a-f0-9]{32}$/.test(message.segment_id) ||
            !Number.isSafeInteger(message.segment_index) ||
            message.segment_index < 1 ||
            ![
                "first_pcm_received",
                "playback_scheduled",
                "playback_started",
            ].includes(message.stage)
        )
            return;
        const key = `${message.segment_id}:${message.stage}`;
        if (this.acknowledgements.has(key)) return;
        this.acknowledgements.add(key);
        if (this.acknowledgements.size > 384)
            this.acknowledgements.delete(
                this.acknowledgements.values().next().value,
            );
        this.observations.observe(
            `stop_to_${message.stage}_ack_ms`,
            this.now() - stop,
        );
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
