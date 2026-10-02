import { usePage } from '@inertiajs/react';
import {
    ACCEPTED_EXTENSIONS,
    MAX_FILES,
} from '@/components/tasks/task-attachments';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Límites y utilidades de lo multimedia del chat. Los límites llegan en la prop compartida `config`
 * (ajustes max_attachment_mb y max_audio_seconds; el servidor los vuelve a comprobar todos).
 */

export const DEFAULT_MAX_AUDIO_SECONDS = 300;

export const DEFAULT_MAX_ATTACHMENT_MB = 50;

/** Archivos por mensaje (AttachmentStorage::MAX_FILES). */
export const MAX_CHAT_FILES = MAX_FILES;

/** Audios de menos de esto no se envían (una pulsación sin querer). */
export const MIN_RECORDING_MS = 1000;

export type MediaLimits = {
    maxAudioSeconds: number;
    maxAttachmentMb: number;
};

function positive(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value) && value > 0
        ? value
        : fallback;
}

/** Límites de la prop compartida `config`, con los valores por defecto del SPEC. */
export function useMediaLimits(): MediaLimits {
    const config = usePage().props.config;

    return {
        maxAudioSeconds: positive(
            config?.max_audio_seconds,
            DEFAULT_MAX_AUDIO_SECONDS,
        ),
        maxAttachmentMb: positive(
            config?.max_attachment_mb,
            DEFAULT_MAX_ATTACHMENT_MB,
        ),
    };
}

/** 65 000 → «1:05»; 3 723 000 → «1:02:03». */
export function formatClock(milliseconds: number | null | undefined): string {
    const total =
        milliseconds === null ||
        milliseconds === undefined ||
        !Number.isFinite(milliseconds)
            ? 0
            : Math.max(0, Math.floor(milliseconds / 1000));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = String(total % 60).padStart(2, '0');

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}`
        : `${minutes}:${seconds}`;
}

/** 1.5 → «1,5×». */
export function formatRate(rate: number): string {
    return `${formatNumber(rate, 1)}×`;
}

/** 212 000 → «3 min 32 s»; 45 000 → «45 s». */
export function formatSpan(milliseconds: number | null | undefined): string {
    if (
        milliseconds === null ||
        milliseconds === undefined ||
        !Number.isFinite(milliseconds)
    ) {
        return '';
    }

    const total = Math.max(0, Math.round(milliseconds / 1000));
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    if (minutes === 0) {
        return t('chat_media.time.seconds', { seconds });
    }

    return seconds === 0
        ? t('chat_media.time.minutes', { minutes })
        : t('chat_media.time.minutes_seconds', { minutes, seconds });
}

const AUDIO_EXTENSIONS: [string, string][] = [
    ['webm', 'webm'],
    ['ogg', 'ogg'],
    ['mp4', 'm4a'],
    ['m4a', 'm4a'],
    ['aac', 'm4a'],
    ['mpeg', 'mp3'],
    ['mp3', 'mp3'],
    ['wav', 'wav'],
];

/** Extensión de un audio grabado según su tipo («audio/webm;codecs=opus» → «webm»). */
export function audioExtension(mime: string): string {
    const type = mime.split(';')[0].trim().toLowerCase();

    return AUDIO_EXTENSIONS.find(([key]) => type.endsWith(key))?.[1] ?? 'webm';
}

function stamp(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}-${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}`;
}

/** Nombre del archivo de un audio grabado: «audio-20260927-101500.webm». */
export function audioFileName(mime: string, date = new Date()): string {
    return `audio-${stamp(date)}.${audioExtension(mime)}`;
}

const IMAGE_EXTENSIONS: Record<string, string> = {
    'image/png': 'png',
    'image/jpeg': 'jpg',
    'image/gif': 'gif',
    'image/webp': 'webp',
    'image/avif': 'avif',
};

/**
 * Los archivos pegados desde el portapapeles (capturas) llegan como «image.png» o sin nombre:
 * se les da uno con fecha y la extensión de su tipo, que el servidor exige.
 */
export function namePastedFile(file: File, date = new Date()): File {
    const extension = IMAGE_EXTENSIONS[file.type];
    const generic = /^image\.\w+$/i.test(file.name) || !file.name.includes('.');

    if (!extension || !generic) {
        return file;
    }

    return new File(
        [file],
        `${t('chat_media.paste.file_prefix')}-${stamp(date)}.${extension}`,
        {
            type: file.type,
            lastModified: file.lastModified,
        },
    );
}

const ACCEPTED = ACCEPTED_EXTENSIONS.split(',').map((extension) =>
    extension.replace('.', '').toLowerCase(),
);

export type RejectedFile = { file: File; reason: string };

/**
 * Comprueba en el navegador el número, la extensión y el tamaño de los archivos (el servidor lo
 * vuelve a comprobar todo, también el tipo real). `pending` son los que ya esperan a enviarse.
 */
export function checkChatFiles(
    files: File[],
    maxMb: number,
    pending = 0,
): { accepted: File[]; rejected: RejectedFile[] } {
    const accepted: File[] = [];
    const rejected: RejectedFile[] = [];

    for (const file of files) {
        const extension = file.name.includes('.')
            ? (file.name.split('.').pop() ?? '').toLowerCase()
            : '';

        if (!ACCEPTED.includes(extension)) {
            rejected.push({
                file,
                reason: t('chat_media.files.type', { name: file.name }),
            });
        } else if (file.size > maxMb * 1024 * 1024) {
            rejected.push({
                file,
                reason: t('chat_media.files.too_big', {
                    name: file.name,
                    max: maxMb,
                }),
            });
        } else if (pending + accepted.length >= MAX_CHAT_FILES) {
            rejected.push({
                file,
                reason: t('chat_media.files.too_many', { max: MAX_CHAT_FILES }),
            });
        } else {
            accepted.push(file);
        }
    }

    return { accepted, rejected };
}
