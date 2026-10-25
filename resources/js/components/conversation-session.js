const KEY = "voice-translator.conversation.v1";

// Tab-scoped bearer credentials. Never put these in URLs, diagnostics or logs.
export class ConversationSession {
    constructor(storage = () => window.sessionStorage) {
        this.storage = storage;
    }

    load() {
        try {
            const value = JSON.parse(this.storage().getItem(KEY));
            if (
                value &&
                /^[A-Z0-9]{8}$/.test(value.code) &&
                /^[A-Za-z0-9]{64}$/.test(value.token)
            ) {
                return { code: value.code, token: value.token };
            }
        } catch {
            /* Storage can be unavailable or malformed. */
        }
        this.clear();
        return null;
    }

    save(code, token) {
        try {
            this.storage().setItem(KEY, JSON.stringify({ code, token }));
        } catch {
            return false;
        }
        return true;
    }

    clear() {
        try {
            this.storage().removeItem(KEY);
        } catch {
            /* Session-only operation remains usable without storage. */
        }
    }
}
