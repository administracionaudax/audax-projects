import { xsrfToken } from '@/lib/xsrf';
import {
    chunk as chunkRoute,
    store as startRoute,
} from '@/routes/help/tutorials/uploads';

/**
 * Subida por trozos de los vídeos de los tutoriales (F-155, D-207): el servidor solo admite 55 MB
 * por petición, así que el vídeo (hasta 200 MB) sube en trozos de 8 MB con su progreso real.
 * Un trozo que falla por la red se reintenta (hasta 3 veces); el servidor dice cuántos bytes tiene,
 * así que un trozo repetido no se duplica. Devuelve el id de la subida, que se manda al guardar.
 */

export type UploadProgress = {
    uploadedBytes: number;
    totalBytes: number;
    percentage: number;
};

export class VideoUploadError extends Error {
    constructor(
        public readonly status: number,
        public readonly userMessage: string | null,
    ) {
        super(userMessage ?? `HTTP ${status}`);
        this.name = 'VideoUploadError';
    }
}

const RETRIES = 3;

function firstError(body: unknown): string | null {
    if (body === null || typeof body !== 'object') {
        return null;
    }

    const data = body as {
        message?: string;
        errors?: Record<string, string[]>;
    };

    return Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? null;
}

function send(
    url: string,
    body: FormData | string,
    json: boolean,
    onProgress?: (loaded: number) => void,
    signal?: AbortSignal,
): Promise<unknown> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.responseType = 'json';
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        const token = xsrfToken();

        if (token) {
            xhr.setRequestHeader('X-XSRF-TOKEN', token);
        }

        if (json) {
            xhr.setRequestHeader('Content-Type', 'application/json');
        }

        if (onProgress) {
            xhr.upload.onprogress = (event) => onProgress(event.loaded);
        }

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(xhr.response);

                return;
            }

            reject(
                new VideoUploadError(
                    xhr.status,
                    xhr.status === 422 ? firstError(xhr.response) : null,
                ),
            );
        };
        xhr.onerror = () => reject(new VideoUploadError(0, null));
        xhr.onabort = () =>
            reject(new DOMException('Subida cancelada', 'AbortError'));

        signal?.addEventListener('abort', () => xhr.abort(), { once: true });
        xhr.send(body);
    });
}

export async function uploadVideoInChunks(
    file: File,
    options: {
        onProgress?: (progress: UploadProgress) => void;
        signal?: AbortSignal;
    } = {},
): Promise<string> {
    const total = file.size;
    const report = (uploaded: number) =>
        options.onProgress?.({
            uploadedBytes: Math.min(uploaded, total),
            totalBytes: total,
            percentage:
                total > 0
                    ? Math.min(100, Math.round((uploaded / total) * 100))
                    : 0,
        });

    const started = (await send(
        startRoute.url(),
        JSON.stringify({ name: file.name, size: total }),
        true,
        undefined,
        options.signal,
    )) as { upload: string; chunk_size: number; received: number };

    let received = started.received;
    report(received);

    while (received < total) {
        const end = Math.min(total, received + started.chunk_size);
        const form = new FormData();
        form.append('offset', String(received));
        form.append('chunk', file.slice(received, end), 'chunk');
        let attempt = 0;

        for (;;) {
            try {
                const result = (await send(
                    chunkRoute.url(started.upload),
                    form,
                    false,
                    (loaded) => report(received + loaded),
                    options.signal,
                )) as { received: number };
                received = result.received;
                break;
            } catch (error) {
                attempt++;

                if (
                    !(error instanceof VideoUploadError) ||
                    error.status !== 0 ||
                    attempt >= RETRIES
                ) {
                    throw error;
                }
            }
        }

        report(received);
    }

    return started.upload;
}
