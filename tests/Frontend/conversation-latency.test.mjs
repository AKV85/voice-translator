import test from "node:test";
import assert from "node:assert/strict";
import {
    ConversationLatency,
    LatencyObservations,
} from "../../resources/js/components/conversation-latency.js";
import recorder from "../../resources/js/components/conversation-recorder.js";

const turn = "a".repeat(32);
const id = (index) => index.toString(16).padStart(32, "0");
const event = (type, index = 1, parent = turn) => ({
    type,
    conversation_turn_id: parent,
    segment_id: parent.slice(0, 16) + id(index).slice(-16),
    segment_index: index,
    format: "pcm16",
    sample_rate_hz: 24000,
    channels: 1,
    bytes_per_sample: 2,
});

function audioContext() {
    const sources = [];
    return {
        currentTime: 1,
        state: "running",
        destination: {},
        sources,
        createBuffer(_channels, length, rate) {
            return {
                duration: length / rate,
                getChannelData: () => new Float32Array(length),
            };
        },
        createBufferSource() {
            const source = {
                connect() {},
                disconnect() {},
                stop() {
                    this.stopped = true;
                },
                start(at) {
                    this.startAt = at;
                },
            };
            sources.push(source);
            return source;
        },
    };
}

function connectedRecorder(enabled = true) {
    const component = recorder({ wsUrl: "ws://mock", latencyDebug: enabled });
    const sockets = [];
    globalThis.WebSocket = class {
        constructor() {
            sockets.push(this);
        }
        close() {}
    };
    void component.connect();
    const socket = sockets[0];
    const send = (message) =>
        socket.onmessage({ data: JSON.stringify(message) });
    send({
        type: "conversation_joined",
        incremental_stt_enabled: true,
        peer_connected: true,
    });
    send({ type: "conversation_peer_started", conversation_turn_id: turn });
    component.audioContext = audioContext();
    component.audioEnabled = true;
    return { component, socket, send };
}

test("statistics exclude missing/invalid values, use median and nearest-rank P95, and bound storage", () => {
    const metrics = new LatencyObservations(20);
    for (const value of [null, undefined, NaN, Infinity, -1, "5"])
        metrics.observe("stage", value);
    assert.deepEqual(metrics.summary(), {});
    for (let value = 1; value <= 20; value++) metrics.observe("stage", value);
    assert.deepEqual(metrics.summary().stage, {
        count: 20,
        median: 10.5,
        p95: 19,
        max: 20,
    });
    metrics.observe("stage", 21);
    assert.deepEqual(metrics.summary().stage, {
        count: 20,
        median: 11.5,
        p95: 20,
        max: 21,
    });
    metrics.observe("zero", 0);
    assert.deepEqual(metrics.summary().zero, {
        count: 1,
        median: 0,
        p95: 0,
        max: 0,
    });
});

test("multiple segments share a turn but cannot duplicate, skip, reorder, or cross turns", () => {
    const metrics = new ConversationLatency();
    metrics.begin(turn);
    assert.equal(metrics.accept(event("conversation_tts_started")), false);
    assert.equal(
        metrics.accept(event("conversation_translation_completed", 2)),
        false,
    );
    assert.equal(
        metrics.accept(event("conversation_translation_completed")),
        true,
    );
    assert.equal(
        metrics.accept(event("conversation_translation_completed")),
        false,
    );
    assert.equal(metrics.accept(event("conversation_completed")), false);
    assert.equal(metrics.accept(event("conversation_tts_started")), true);
    assert.equal(metrics.accept(event("conversation_completed", 2)), false);
    assert.equal(metrics.accept(event("conversation_completed")), true);
    assert.equal(metrics.accept(event("conversation_completed")), false);
    assert.equal(
        metrics.accept(event("conversation_translation_completed", 2)),
        true,
    );
    assert.equal(metrics.accept(event("conversation_tts_started", 2)), true);
    assert.equal(metrics.accept(event("conversation_completed", 2)), true);
    metrics.begin("b".repeat(32));
    assert.equal(
        metrics.accept(event("conversation_translation_completed")),
        false,
    );
    assert.equal(metrics.accept({ type: "conversation_tts_started" }), true); // legacy
    assert.deepEqual(metrics.summary(), {});
});

test("the speaking client accepts consecutive translations without receiving peer-only audio events", () => {
    const { component, send } = connectedRecorder();
    component.listeningTurn = false;
    send({
        ...event("conversation_translation_completed"),
        translated_text: "first",
    });
    send({
        ...event("conversation_translation_completed", 2),
        translated_text: "second",
    });
    send({
        ...event("conversation_translation_completed"),
        translated_text: "stale",
    });
    assert.equal(component.translatedText, "first second");
    assert.equal(component.latency.records.size, 0);
});

test("receipt, queue, predicted scheduling and end callback use separate stages and exclude aborted samples", () => {
    let now = 100;
    const metrics = new ConversationLatency({ enabled: true, now: () => now });
    metrics.begin(turn);
    metrics.accept(event("conversation_translation_completed"));
    metrics.accept(event("conversation_tts_started"));
    now = 110;
    const ticket = metrics.receipt();
    now = 112;
    metrics.queued(ticket, 1, 1.04);
    const complete = event("conversation_completed");
    complete.server_latency = {
        translation_ms: 12,
        total_ms: null,
        transcript: "never retained",
    };
    metrics.accept(complete);
    assert.equal(
        metrics.summary().first_pcm_receipt_to_playback_end_callback_ms,
        undefined,
    );
    now = 160;
    metrics.ended(ticket);
    const summary = metrics.summary();
    assert.equal(summary.tts_event_to_first_pcm_receipt_ms.median, 10);
    assert.equal(summary.pcm_receipt_to_queue_ms.median, 2);
    assert.ok(
        Math.abs(summary.queue_to_scheduled_start_estimate_ms.median - 40) <
            0.001,
    );
    assert.equal(
        summary.first_pcm_receipt_to_playback_end_callback_ms.median,
        50,
    );
    assert.equal(summary.server_translation_ms.median, 12);
    assert.equal(summary.server_total_ms, undefined);
    assert.equal(JSON.stringify(summary).includes("never retained"), false);
    metrics.reset();
    now = 200;
    metrics.ended(ticket);
    assert.deepEqual(metrics.summary(), summary);
    assert.equal(metrics.records.size, 0);
});

test("PCM buffers retain ordering across segments and completion waits for onended", () => {
    const { component, socket, send } = connectedRecorder();
    for (const index of [1, 2]) {
        send(event("conversation_translation_completed", index));
        send(event("conversation_tts_started", index));
        socket.onmessage({ data: new ArrayBuffer(4800) }); // 100 ms
        send(event("conversation_completed", index));
    }
    const [first, second] = component.audioContext.sources;
    assert.equal(first.startAt, 1.04);
    assert.ok(Math.abs(second.startAt - 1.14) < 0.0001);
    assert.equal(component.playbackPending, 2);
    assert.equal(
        component.latencySummary()
            .first_pcm_receipt_to_playback_end_callback_ms,
        undefined,
    );
    first.onended();
    second.onended();
    assert.equal(component.playbackPending, 0);
    assert.equal(component.audioStatus, "Playback finished");
    assert.equal(
        component.latencySummary().first_pcm_receipt_to_playback_end_callback_ms
            .count,
        2,
    );
});

test("a successful stop preserves draining audio and the following turn shares its timeline", () => {
    const { component, socket, send } = connectedRecorder();
    component.incrementalSttEnabled = false;
    send(event("conversation_translation_completed"));
    send(event("conversation_tts_started"));
    socket.onmessage({ data: new ArrayBuffer(4800) });
    send(event("conversation_completed"));
    send({ type: "conversation_peer_stopped" });
    const nextTurn = "b".repeat(32);
    send({ type: "conversation_peer_started", conversation_turn_id: nextTurn });
    send(event("conversation_translation_completed", 1, nextTurn));
    send(event("conversation_tts_started", 1, nextTurn));
    socket.onmessage({ data: new ArrayBuffer(4800) });
    const [first, second] = component.audioContext.sources;
    assert.equal(first.stopped, undefined);
    assert.ok(second.startAt >= first.startAt + 0.1);
    component.disconnect();
    assert.equal(first.stopped, true);
    assert.equal(second.stopped, true);
    assert.equal(component.playbackSources.size, 0);
    assert.equal(component.latency.records.size, 0);
});

test("abort/disconnect stop scheduled audio and stale callbacks/sockets cannot change a new session", () => {
    const { component, socket, send } = connectedRecorder();
    send(event("conversation_translation_completed"));
    send(event("conversation_tts_started"));
    socket.onmessage({ data: new ArrayBuffer(4800) });
    const source = component.audioContext.sources[0];
    const oldEnded = source.onended;
    send({ type: "conversation_peer_stopped", aborted: true });
    assert.equal(source.stopped, true);
    assert.equal(component.playbackPending, 0);
    assert.equal(component.latency.records.size, 0);
    oldEnded();
    assert.equal(component.playbackPending, 0);
    component.disconnect();
    component.socket = {};
    socket.onmessage({
        data: JSON.stringify({
            type: "conversation_peer_started",
            conversation_turn_id: turn,
        }),
    });
    socket.onclose();
    assert.notEqual(component.socket, null);
    assert.equal(component.turnBusy, false);
    assert.equal(
        component.latencySummary()
            .first_pcm_receipt_to_playback_end_callback_ms,
        undefined,
    );
});

test("debug is off by default and a suspended speaker does not invent a playback sample", () => {
    const { component, socket, send } = connectedRecorder(false);
    component.audioContext.state = "suspended";
    send(event("conversation_translation_completed"));
    send(event("conversation_tts_started"));
    socket.onmessage({ data: new ArrayBuffer(4800) });
    send(event("conversation_completed"));
    assert.deepEqual(component.latencySummary(), {});
    assert.equal(component.audioContext.sources.length, 0);
});

test("Stop to playback ACK uses only the source clock, correlates segments and excludes duplicates", () => {
    let now = 900000; // Different origin from the receiver, deliberately.
    const source = new ConversationLatency({ enabled: true, now: () => now });
    source.begin(turn);
    source.markStop();
    now += 800;
    const ack = {
        ...event("conversation_playback_ack"),
        stage: "playback_started",
        after_stop: true,
    };
    source.acknowledge(ack);
    source.acknowledge(ack);
    source.acknowledge({ ...ack, conversation_turn_id: "b".repeat(32) });
    source.acknowledge({ ...ack, stage: "untrusted" });
    assert.deepEqual(source.summary().stop_to_playback_started_ack_ms, {
        count: 1,
        median: 800,
        p95: 800,
        max: 800,
    });
    source.reset();
    now += 50;
    source.acknowledge(ack);
    assert.equal(source.summary().stop_to_playback_started_ack_ms.count, 1);
});

test("receiver reports first receipt, schedule and observed WebAudio start once per segment", () => {
    let now = 10;
    const events = [];
    const metrics = new ConversationLatency({
        enabled: true,
        now: () => now,
        onStage: (event) => events.push(event),
    });
    metrics.begin(turn);
    metrics.stopReceived({ conversation_turn_id: turn });
    metrics.accept(event("conversation_translation_completed"));
    metrics.accept(event("conversation_tts_started"));
    now = 700;
    const first = metrics.receipt();
    metrics.queued(first, 1, 1.04);
    const next = metrics.receipt();
    metrics.queued(next, 1, 1.08);
    now = 745;
    metrics.started(first);
    metrics.started(first);
    metrics.started(next);
    assert.deepEqual(
        events.map((event) => event.stage),
        ["first_pcm_received", "playback_scheduled", "playback_started"],
    );
    assert.ok(
        events.every(
            (e) =>
                e.segment_id === event("x").segment_id &&
                e.conversation_turn_id === turn,
        ),
    );
    assert.equal(
        metrics.summary().receiver_stop_marker_to_first_pcm_ms.median,
        690,
    );
    assert.equal(
        metrics.summary().first_pcm_receipt_to_playback_start_observed_ms
            .median,
        45,
    );
    metrics.reset();
    metrics.started(first);
    assert.equal(events.length, 3);
});

test("reconnected playback cancels old sources and drops stale binary frames until a new stream starts", () => {
    const { component, socket, send } = connectedRecorder();
    send(event("conversation_translation_completed"));
    send(event("conversation_tts_started"));
    socket.onmessage({ data: new ArrayBuffer(3840) });
    const old = component.audioContext.sources[0];
    component.disconnect(false);
    assert.equal(old.stopped, true);
    component.receiveAudio(new ArrayBuffer(3840));
    assert.equal(component.audioContext.sources.length, 1);
    component.reconnectAllowed = false;
    void component.connect();
    component.socket.onmessage({
        data: JSON.stringify({
            type: "conversation_joined",
            peer_connected: true,
        }),
    });
    const nextTurn = "b".repeat(32);
    const deliver = (message) =>
        component.socket.onmessage({ data: JSON.stringify(message) });
    deliver({
        type: "conversation_peer_started",
        conversation_turn_id: nextTurn,
    });
    for (const index of [1, 2]) {
        deliver(event("conversation_translation_completed", index, nextTurn));
        deliver(event("conversation_tts_started", index, nextTurn));
        component.socket.onmessage({ data: new ArrayBuffer(3840) });
        deliver(event("conversation_completed", index, nextTurn));
    }
    const [, first, second] = component.audioContext.sources;
    assert.ok(second.startAt >= first.startAt + 0.08);
    socket.onmessage({ data: new ArrayBuffer(3840) });
    assert.equal(component.audioContext.sources.length, 3);
    component.disconnect(false);
});

test("replacement peer clears an interrupted turn and its queued audio before a new recording", () => {
    const { component, socket, send } = connectedRecorder();
    send(event("conversation_translation_completed"));
    send(event("conversation_tts_started"));
    socket.onmessage({ data: new ArrayBuffer(1920) });
    const old = component.audioContext.sources[0];
    component.turnBusy = true;
    component.isStopping = true;
    send({ type: "conversation_peer_joined", replaced: true });
    assert.equal(old.stopped, true);
    assert.equal(component.turnBusy, false);
    assert.equal(component.isStopping, false);
    assert.equal(component.recordingCancelled, true);
    assert.equal(component.peerConnected, true);
    component.disconnect();
});

test("recorder observes the scheduled audio timeline and cancels pending start observers on disconnect", (t) => {
    t.mock.timers.enable({ apis: ["setTimeout"] });
    const { component, socket, send } = connectedRecorder();
    const stages = [];
    component.latency.onStage = (value) => stages.push(value);
    send(event("conversation_translation_completed"));
    send(event("conversation_tts_started"));
    socket.onmessage({ data: new ArrayBuffer(1920) });
    assert.equal(
        stages.some((value) => value.stage === "playback_started"),
        false,
    );
    component.audioContext.currentTime = 1.04;
    t.mock.timers.tick(50);
    assert.equal(
        stages.filter((value) => value.stage === "playback_started").length,
        1,
    );
    socket.onmessage({ data: new ArrayBuffer(3840) });
    component.disconnect();
    t.mock.timers.tick(60000);
    assert.equal(component.playbackStartTimers.size, 0);
    assert.equal(
        stages.filter((value) => value.stage === "playback_started").length,
        1,
    );
});
