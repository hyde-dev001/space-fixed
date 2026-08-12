import "@testing-library/jest-dom/vitest";

// Node 25 can expose a partial localStorage object when the jsdom runtime is
// started without a valid --localstorage-file. Keep browser-facing tests on
// the standard Storage contract without changing application code.
if (typeof globalThis.localStorage?.clear !== "function") {

    const values = new Map<string, string>();
    const storage = {
        getItem: (key: string) => values.get(key) ?? null,
        setItem: (key: string, value: string) => values.set(key, String(value)),
        removeItem: (key: string) => values.delete(key),
        clear: () => values.clear(),
        key: (index: number) => Array.from(values.keys())[index] ?? null,
        get length() {
            return values.size;
        },
    } as Storage;

    Object.defineProperty(globalThis, "localStorage", {
        configurable: true,
        value: storage,
    });
}
