/**
 * Preparación común de Vitest. Los tests de componentes declaran su entorno por fichero
 * (`// @vitest-environment jsdom`); aquí solo se completa lo que jsdom no trae.
 */
import { afterEach } from 'vitest';

/** Storage en memoria: Node ≥ 22 expone un `localStorage` global que tapa el de jsdom. */
class MemoryStorage implements Storage {
    private items = new Map<string, string>();

    get length(): number {
        return this.items.size;
    }

    clear(): void {
        this.items.clear();
    }

    getItem(key: string): string | null {
        return this.items.get(key) ?? null;
    }

    key(index: number): string | null {
        return [...this.items.keys()][index] ?? null;
    }

    removeItem(key: string): void {
        this.items.delete(key);
    }

    setItem(key: string, value: string): void {
        this.items.set(key, String(value));
    }
}

if (typeof window !== 'undefined') {
    if (typeof globalThis.localStorage?.setItem !== 'function') {
        const storage = new MemoryStorage();

        for (const target of [globalThis, window]) {
            Object.defineProperty(target, 'localStorage', {
                configurable: true,
                value: storage,
            });
        }
    }

    // matchMedia: lo usan el tema (prefers-color-scheme) y la barra lateral (móvil).
    if (!window.matchMedia) {
        window.matchMedia = (query: string): MediaQueryList =>
            ({
                matches: false,
                media: query,
                onchange: null,
                addEventListener: () => {},
                removeEventListener: () => {},
                addListener: () => {},
                removeListener: () => {},
                dispatchEvent: () => false,
            }) as MediaQueryList;
    }

    // cmdk y Radix miden elementos y hacen scroll al elemento activo.
    if (!('ResizeObserver' in window)) {
        class ResizeObserverStub {
            observe(): void {}
            unobserve(): void {}
            disconnect(): void {}
        }

        Object.assign(window, { ResizeObserver: ResizeObserverStub });
        Object.assign(globalThis, { ResizeObserver: ResizeObserverStub });
    }

    if (!Element.prototype.scrollIntoView) {
        Element.prototype.scrollIntoView = () => {};
    }

    const { cleanup } = await import('@testing-library/react');

    afterEach(() => {
        cleanup();
        localStorage.clear();
        document.documentElement.className = '';
    });
}
