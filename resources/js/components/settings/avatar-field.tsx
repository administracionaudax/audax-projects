import { router } from '@inertiajs/react';
import { Camera, Trash2 } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { t } from '@/lib/i18n';
import {
    destroy as destroyAvatar,
    update as updateAvatar,
} from '@/routes/profile/avatar';

/** Lado del recuadro de recorte en pantalla y de la imagen que se sube, en píxeles. */
export const CROP_VIEW = 256;
export const CROP_OUTPUT = 512;

export type CropState = { zoom: number; x: number; y: number };

/** Escala con la que la imagen cubre el recuadro, con el zoom. */
export function cropScale(width: number, height: number, zoom: number): number {
    return (CROP_VIEW / Math.min(width, height)) * zoom;
}

/** Mantiene la imagen cubriendo todo el recuadro (sin huecos al arrastrar o al alejar). */
export function clampCrop(
    width: number,
    height: number,
    state: CropState,
): CropState {
    const scale = cropScale(width, height, state.zoom);
    const minX = CROP_VIEW - width * scale;
    const minY = CROP_VIEW - height * scale;

    return {
        zoom: state.zoom,
        x: Math.min(0, Math.max(minX, state.x)),
        y: Math.min(0, Math.max(minY, state.y)),
    };
}

/** La parte de la imagen original que queda dentro del recuadro (sx, sy, lado). */
export function cropSource(
    width: number,
    height: number,
    state: CropState,
): { sx: number; sy: number; size: number } {
    const scale = cropScale(width, height, state.zoom);

    return {
        sx: -state.x / scale,
        sy: -state.y / scale,
        size: CROP_VIEW / scale,
    };
}

function centered(width: number, height: number, zoom = 1): CropState {
    const scale = cropScale(width, height, zoom);

    return {
        zoom,
        x: (CROP_VIEW - width * scale) / 2,
        y: (CROP_VIEW - height * scale) / 2,
    };
}

/**
 * La foto de perfil (F-029, D-234; WeeklySync generaba un avatar con las iniciales): elegir una
 * imagen, recortarla al cuadrado (arrastrar o flechas para encuadrar, zoom) y subirla ya reducida a
 * 512 px. El servidor la vuelve a recortar, la reduce a 256 px y quita sus metadatos. «Quitar foto»
 * vuelve a las iniciales.
 */
export function AvatarField({
    name,
    avatar,
}: {
    name: string;
    avatar: string | null;
}) {
    const id = useId();
    const getInitials = useInitials();
    const input = useRef<HTMLInputElement>(null);
    const [source, setSource] = useState<{
        url: string;
        image: HTMLImageElement;
    } | null>(null);
    const [crop, setCrop] = useState<CropState>({ zoom: 1, x: 0, y: 0 });
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const drag = useRef<{ x: number; y: number; crop: CropState } | null>(null);

    useEffect(
        () => () => {
            if (source) {
                URL.revokeObjectURL(source.url);
            }
        },
        [source],
    );

    const choose = (file: File | undefined) => {
        setError(null);

        if (!file) {
            return;
        }

        if (!file.type.startsWith('image/')) {
            setError(t('profile.avatar.invalid'));

            return;
        }

        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            setCrop(centered(image.naturalWidth, image.naturalHeight));
            setSource({ url, image });
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            setError(t('profile.avatar.invalid'));
        };
        image.src = url;
    };

    const update = (next: CropState) => {
        if (!source) {
            return;
        }

        setCrop(
            clampCrop(
                source.image.naturalWidth,
                source.image.naturalHeight,
                next,
            ),
        );
    };

    const zoomTo = (zoom: number) => {
        if (!source) {
            return;
        }

        const { naturalWidth: w, naturalHeight: h } = source.image;
        const before = cropScale(w, h, crop.zoom);
        const after = cropScale(w, h, zoom);
        const center = CROP_VIEW / 2;
        // Zoom alrededor del centro del recuadro.
        update({
            zoom,
            x: center - ((center - crop.x) / before) * after,
            y: center - ((center - crop.y) / before) * after,
        });
    };

    const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
        event.currentTarget.setPointerCapture(event.pointerId);
        drag.current = { x: event.clientX, y: event.clientY, crop };
    };

    const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
        if (!drag.current) {
            return;
        }

        update({
            zoom: drag.current.crop.zoom,
            x: drag.current.crop.x + event.clientX - drag.current.x,
            y: drag.current.crop.y + event.clientY - drag.current.y,
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const step = event.shiftKey ? 40 : 10;
        const moves: Record<string, [number, number]> = {
            ArrowLeft: [step, 0],
            ArrowRight: [-step, 0],
            ArrowUp: [0, step],
            ArrowDown: [0, -step],
        };
        const move = moves[event.key];

        if (move) {
            event.preventDefault();
            update({ ...crop, x: crop.x + move[0], y: crop.y + move[1] });
        }
    };

    const close = () => {
        setSource(null);

        if (input.current) {
            input.current.value = '';
        }
    };

    const save = () => {
        if (!source) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = CROP_OUTPUT;
        canvas.height = CROP_OUTPUT;
        const context = canvas.getContext('2d');

        if (!context) {
            setError(t('profile.avatar.invalid'));

            return;
        }

        const { sx, sy, size } = cropSource(
            source.image.naturalWidth,
            source.image.naturalHeight,
            crop,
        );
        context.drawImage(
            source.image,
            sx,
            sy,
            size,
            size,
            0,
            0,
            CROP_OUTPUT,
            CROP_OUTPUT,
        );
        setProcessing(true);
        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    setProcessing(false);
                    setError(t('profile.avatar.invalid'));

                    return;
                }

                router.post(
                    updateAvatar.url(),
                    {
                        avatar: new File([blob], 'avatar.jpg', {
                            type: blob.type || 'image/jpeg',
                        }),
                    },
                    {
                        forceFormData: true,
                        preserveScroll: true,
                        onFinish: () => setProcessing(false),
                        onSuccess: () => close(),
                        onError: (errors) => {
                            setError(
                                errors.avatar ?? t('profile.avatar.invalid'),
                            );
                            close();
                        },
                    },
                );
            },
            'image/jpeg',
            0.9,
        );
    };

    const remove = () =>
        router.delete(destroyAvatar.url(), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });

    const scale = source
        ? cropScale(
              source.image.naturalWidth,
              source.image.naturalHeight,
              crop.zoom,
          )
        : 1;

    return (
        <div className="grid gap-2" data-test="avatar-field">
            <span id={`${id}-label`} className="text-sm font-medium">
                {t('profile.avatar.label')}
            </span>
            <div className="flex flex-wrap items-center gap-4">
                <Avatar className="size-16 overflow-hidden rounded-full">
                    <AvatarImage src={avatar ?? undefined} alt="" />
                    <AvatarFallback className="rounded-full bg-neutral-soft text-lg text-foreground">
                        {getInitials(name)}
                    </AvatarFallback>
                </Avatar>
                <div className="flex flex-wrap gap-2">
                    <input
                        ref={input}
                        id={`${id}-file`}
                        type="file"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        className="sr-only"
                        aria-labelledby={`${id}-label`}
                        onChange={(event) => choose(event.target.files?.[0])}
                        data-test="avatar-input"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => input.current?.click()}
                        disabled={processing}
                    >
                        <Camera aria-hidden="true" />
                        {avatar
                            ? t('profile.avatar.change')
                            : t('profile.avatar.upload')}
                    </Button>
                    {avatar ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={remove}
                            disabled={processing}
                            data-test="avatar-remove"
                        >
                            <Trash2 aria-hidden="true" />
                            {t('profile.avatar.remove')}
                        </Button>
                    ) : null}
                </div>
            </div>
            <p className="text-sm text-muted-foreground">
                {t('profile.avatar.help')}
            </p>
            <InputError message={error ?? undefined} />

            <Dialog
                open={source !== null}
                onOpenChange={(open) => (open ? null : close())}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>
                            {t('profile.avatar.crop_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('profile.avatar.crop_description')}
                        </DialogDescription>
                    </DialogHeader>
                    {source ? (
                        <div className="grid justify-items-center gap-4">
                            <div
                                role="img"
                                aria-label={t('profile.avatar.crop_area')}
                                tabIndex={0}
                                onPointerDown={onPointerDown}
                                onPointerMove={onPointerMove}
                                onPointerUp={() => (drag.current = null)}
                                onPointerCancel={() => (drag.current = null)}
                                onKeyDown={onKeyDown}
                                className="relative cursor-move touch-none overflow-hidden rounded-full border outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                style={{ width: CROP_VIEW, height: CROP_VIEW }}
                                data-test="avatar-crop"
                            >
                                <img
                                    src={source.url}
                                    alt=""
                                    draggable={false}
                                    className="pointer-events-none absolute max-w-none select-none"
                                    style={{
                                        left: crop.x,
                                        top: crop.y,
                                        width:
                                            source.image.naturalWidth * scale,
                                        height:
                                            source.image.naturalHeight * scale,
                                    }}
                                />
                            </div>
                            <label className="grid w-full gap-1 text-sm">
                                {t('profile.avatar.zoom')}
                                <input
                                    type="range"
                                    min={1}
                                    max={4}
                                    step={0.05}
                                    value={crop.zoom}
                                    onChange={(event) =>
                                        zoomTo(Number(event.target.value))
                                    }
                                    data-test="avatar-zoom"
                                />
                            </label>
                        </div>
                    ) : null}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            onClick={save}
                            disabled={processing}
                            data-test="avatar-save"
                        >
                            {processing && <Spinner />}
                            {t('profile.avatar.save')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
