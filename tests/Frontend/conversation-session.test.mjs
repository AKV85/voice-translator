import test from "node:test";
import assert from "node:assert/strict";
import recorder from "../../resources/js/components/conversation-recorder.js";
import { ConversationSession } from "../../resources/js/components/conversation-session.js";

const code = "ABCDEFGH";
const token = "t".repeat(64);
function setup(t) {
    t.mock.timers.enable({ apis: ["setTimeout"] });
    const values = new Map();
    const storage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
    const sockets = [];
    globalThis.WebSocket = class {
        static OPEN = 1;
        constructor(url) {
            this.url = url;
            this.sent = [];
            this.readyState = 1;
            sockets.push(this);
        }
        send(value) {
            this.sent.push(JSON.parse(value));
        }
        close() {
            this.readyState = 3;
            this.onclose?.();
        }
    };
    const make = () => recorder({ wsUrl: "ws://mock", storage: () => storage });
    const joined = (socket, role = "guest") => {
        socket.onopen();
        socket.onmessage({
            data: JSON.stringify({
                type: "conversation_joined",
                role,
                peer_connected: true,
            }),
        });
    };
    const session = new ConversationSession(() => storage);
    return { make, sockets, joined, session, values };
}

for (const role of ["host", "guest"]) {
    test(`${role} refresh restores bearer credentials without HTTP registration or URL tokens`, (t) => {
        const { make, sockets, joined, session } = setup(t);
        session.save(code, token);
        globalThis.fetch = () => {
            assert.fail("refresh must not register a guest");
        };
        const component = make();
        component.init();
        joined(sockets[0], role);
        assert.equal(component.connected, true);
        assert.equal(component.role, role);
        assert.deepEqual(sockets[0].sent, [
            { type: "conversation_join", code, token },
        ]);
        assert.equal(sockets[0].url.includes(token), false);
        component.destroy();
        const refreshed = make();
        refreshed.init();
        joined(sockets[1], role);
        assert.equal(refreshed.connected, true);
        assert.deepEqual(sockets[1].sent[0], {
            type: "conversation_join",
            code,
            token,
        });
        refreshed.destroy();
    });
}

test("temporary loss retries the same participant and ignores stale socket callbacks", async (t) => {
    const { make, sockets, joined, session } = setup(t);
    session.save(code, token);
    const component = make();
    component.init();
    joined(sockets[0]);
    const old = sockets[0];
    old.close();
    assert.equal(component.connected, false);
    assert.deepEqual(session.load(), { code, token });
    t.mock.timers.tick(1000);
    joined(sockets[1]);
    old.onclose();
    old.onerror();
    old.onmessage({
        data: JSON.stringify({ type: "conversation_session_invalid" }),
    });
    assert.equal(component.socket, sockets[1]);
    assert.equal(component.connected, true);
    assert.equal(component.reconnectAttempt, 0);
    component.disconnect();
    t.mock.timers.tick(30000);
    assert.equal(sockets.length, 2);
    assert.equal(session.load(), null);
});

for (const type of ["conversation_session_invalid", "conversation_replaced"]) {
    test(`${type} clears credentials and never enters a reconnect/replacement loop`, async (t) => {
        const { make, sockets, session } = setup(t);
        session.save(code, token);
        const component = make();
        component.init();
        sockets[0].onmessage({ data: JSON.stringify({ type }) });
        await Promise.resolve();
        t.mock.timers.tick(60000);
        assert.equal(session.load(), null);
        assert.equal(component.code, "");
        assert.equal(component.token, "");
        assert.equal(sockets.length, 1);
        assert.equal(component.connected, false);
    });
}

test("invalid storage and denied sessionStorage do not leak credentials or prevent explicit joins", (t) => {
    const { session, values } = setup(t);
    values.set("voice-translator.conversation.v1", "broken");
    assert.equal(session.load(), null);
    assert.equal(values.size, 0);
    const denied = new ConversationSession(() => {
        throw new Error("denied");
    });
    assert.equal(denied.load(), null);
    assert.equal(denied.save(code, token), false);
});

test("rejoining the active room uses saved credentials instead of consuming another participant slot", async (t) => {
    const { make, sockets, joined, session } = setup(t);
    session.save(code, token);
    const component = make();
    component.joinCode = code.toLowerCase();
    const pending = component.joinRoom();
    joined(sockets[0]);
    await pending;
    assert.equal(component.connected, true);
    component.destroy();
});
