import { Head, router } from '@inertiajs/react';
import {
    Ban,
    CircleCheck,
    Copy,
    FileArchive,
    FileSearch,
    KeyRound,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { PeopleFrame } from '@/components/people/people-ui';
import { HashText } from '@/components/people/register-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import {
    accessStateLabel,
    exportKindLabel,
    formatBytes,
} from '@/lib/people-register';
import { cn } from '@/lib/utils';
import {
    check,
    exportMethod as exportRoute,
    revoke,
    store,
    toggle,
    verifyFile,
} from '@/routes/people/inspection';
import type { InspectionPageProps } from '@/types/people-register';

/**
 * «Inspección» (`/personas/inspeccion`, PLAN-FASE-11 §6.3.3 y §11; D-352 y D-353; W-088), solo
 * RR. HH.: la exportación para la Inspección al momento, la integridad del registro (comprobación
 * nocturna y ancla diaria), comprobar que un fichero no se ha tocado y el acceso temporal de solo
 * lectura de la Inspección (apagado por defecto).
 */
export default function InspectionPage(props: InspectionPageProps) {
    return (
        <>
            <Head title={t('people.inspection.title')} />
            <PeopleFrame
                section="inspection"
                title={t('people.inspection.title')}
                description={t('people.inspection.description')}
            >
                <ExportSection props={props} />
                <IntegritySection props={props} />
                <VerifySection props={props} />
                <AccessSection props={props} />
            </PeopleFrame>
        </>
    );
}

function PeoplePicker({
    people,
    selected,
    onChange,
    idPrefix,
}: {
    people: InspectionPageProps['people'];
    selected: number[];
    onChange: (ids: number[]) => void;
    idPrefix: string;
}) {
    return (
        <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm">
                {t('people.inspection.people')}
            </legend>
            <div className="flex items-center gap-2">
                <Checkbox
                    id={`${idPrefix}-all`}
                    checked={selected.length === 0}
                    onCheckedChange={() => onChange([])}
                />
                <Label htmlFor={`${idPrefix}-all`} className="font-normal">
                    {t('people.inspection.all_people')}
                </Label>
            </div>
            <div className="grid max-h-48 gap-1.5 overflow-y-auto sm:grid-cols-2">
                {people.map((person) => (
                    <div key={person.id} className="flex items-center gap-2">
                        <Checkbox
                            id={`${idPrefix}-${person.id}`}
                            checked={selected.includes(person.id)}
                            onCheckedChange={(checked) =>
                                onChange(
                                    checked
                                        ? [...selected, person.id]
                                        : selected.filter(
                                              (id) => id !== person.id,
                                          ),
                                )
                            }
                        />
                        <Label
                            htmlFor={`${idPrefix}-${person.id}`}
                            className="font-normal"
                        >
                            {person.active
                                ? person.name
                                : t('people.reports.inactive', {
                                      name: person.name,
                                  })}
                        </Label>
                    </div>
                ))}
            </div>
        </fieldset>
    );
}

function ExportSection({ props }: { props: InspectionPageProps }) {
    const id = useId();
    const [from, setFrom] = useState(props.period.from);
    const [to, setTo] = useState(props.period.to);
    const [selected, setSelected] = useState<number[]>([]);
    const valid = from !== '' && to !== '' && from <= to && to <= props.today;
    const query: Record<string, string | string[]> = { desde: from, hasta: to };
    if (selected.length > 0) {
        query['personas[]'] = selected.map(String);
    }

    return (
        <section
            aria-labelledby={`${id}-title`}
            className="grid gap-4 rounded-md border p-4"
        >
            <div className="space-y-1">
                <h2 id={`${id}-title`} className="text-lg">
                    {t('people.inspection.export_title')}
                </h2>
                <p className="max-w-3xl text-sm text-muted-foreground">
                    {t('people.inspection.export_hint', {
                        days: props.max_days,
                    })}
                </p>
            </div>
            <div className="flex flex-wrap gap-3">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-from`}>
                        {t('people.register.from')}
                    </Label>
                    <Input
                        id={`${id}-from`}
                        type="date"
                        value={from}
                        max={props.today}
                        onChange={(event) => setFrom(event.target.value)}
                        className="w-44"
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-to`}>
                        {t('people.register.to')}
                    </Label>
                    <Input
                        id={`${id}-to`}
                        type="date"
                        value={to}
                        max={props.today}
                        onChange={(event) => setTo(event.target.value)}
                        className="w-44"
                    />
                </div>
            </div>
            <PeoplePicker
                people={props.people}
                selected={selected}
                onChange={setSelected}
                idPrefix={`${id}-people`}
            />
            <div>
                <Button asChild={valid} disabled={!valid}>
                    {valid ? (
                        <a
                            href={exportRoute.url({ query })}
                            data-test="inspection-export"
                        >
                            <FileArchive aria-hidden="true" />
                            {t('people.inspection.export_button')}
                        </a>
                    ) : (
                        <span>
                            <FileArchive aria-hidden="true" />
                            {t('people.inspection.export_button')}
                        </span>
                    )}
                </Button>
            </div>
            {props.exports.length > 0 ? (
                <ul className="divide-y rounded-md border text-sm">
                    {props.exports.map((item) => (
                        <li
                            key={item.id}
                            className="grid gap-1 px-3 py-2 sm:grid-cols-[1fr_auto] sm:items-center"
                        >
                            <span className="min-w-0">
                                <span className="block truncate">
                                    {item.filename}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {formatDateTime(item.created_at)}
                                    {item.by ? ` · ${item.by}` : ''} ·{' '}
                                    {formatBytes(item.size)}
                                </span>
                            </span>
                            <HashText
                                hash={item.sha256}
                                label={t('people.reports.file_hash')}
                            />
                        </li>
                    ))}
                </ul>
            ) : null}
        </section>
    );
}

function IntegritySection({ props }: { props: InspectionPageProps }) {
    const id = useId();
    const [processing, setProcessing] = useState(false);
    const latest = props.anchors[0];

    return (
        <section
            aria-labelledby={`${id}-title`}
            className="grid gap-4 rounded-md border p-4"
            data-test="integrity"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                    <h2 id={`${id}-title`} className="text-lg">
                        {t('people.inspection.integrity_title')}
                    </h2>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {t('people.inspection.integrity_hint')}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    disabled={processing}
                    onClick={() =>
                        router.post(
                            check.url(),
                            {},
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                            },
                        )
                    }
                    data-test="integrity-check"
                >
                    {processing ? (
                        <Spinner />
                    ) : (
                        <ShieldCheck aria-hidden="true" />
                    )}
                    {t('people.inspection.check_now')}
                </Button>
            </div>

            {props.check ? (
                <p
                    role="status"
                    className={cn(
                        'flex items-start gap-2 rounded-md p-3 text-sm',
                        props.check.ok ? 'bg-success-soft' : 'bg-danger-soft',
                    )}
                    data-test="integrity-result"
                    data-ok={props.check.ok ? 'true' : 'false'}
                >
                    {props.check.ok ? (
                        <CircleCheck
                            aria-hidden="true"
                            className="size-4 shrink-0 text-success"
                        />
                    ) : (
                        <TriangleAlert
                            aria-hidden="true"
                            className="size-4 shrink-0 text-danger"
                        />
                    )}
                    <span>
                        {props.check.ok
                            ? t('people.inspection.check_ok', {
                                  events: props.check.events,
                                  corrections: props.check.corrections,
                              })
                            : tCount(
                                  'people.inspection.check_failed',
                                  props.check.problems.length,
                              )}
                        {props.check.problems.slice(0, 5).map((problem) => (
                            <span key={problem} className="block text-xs">
                                {problem}
                            </span>
                        ))}
                    </span>
                </p>
            ) : null}

            {latest ? (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm" data-test="anchors">
                        <caption className="sr-only">
                            {t('people.inspection.anchors_caption')}
                        </caption>
                        <thead>
                            <tr className="border-b text-left text-xs text-muted-foreground">
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('people.inspection.anchor_date')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('people.inspection.anchor_check')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {t('people.inspection.anchor_rows')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('people.inspection.anchor_digest')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {props.anchors.map((anchor) => (
                                <tr
                                    key={anchor.date}
                                    className="border-b last:border-0"
                                >
                                    <th
                                        scope="row"
                                        className="tabular px-3 py-2 text-left font-normal"
                                    >
                                        {formatDate(anchor.date)}
                                    </th>
                                    <td className="px-3 py-2">
                                        <span
                                            className={cn(
                                                'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs',
                                                anchor.verified_ok
                                                    ? 'bg-success-soft'
                                                    : 'bg-danger-soft',
                                            )}
                                        >
                                            {anchor.verified_ok ? (
                                                <CircleCheck
                                                    aria-hidden="true"
                                                    className="size-3.5 text-success"
                                                />
                                            ) : (
                                                <TriangleAlert
                                                    aria-hidden="true"
                                                    className="size-3.5 text-danger"
                                                />
                                            )}
                                            {anchor.verified_ok
                                                ? t(
                                                      'people.inspection.anchor_ok',
                                                  )
                                                : t(
                                                      'people.inspection.anchor_failed',
                                                  )}
                                        </span>
                                    </td>
                                    <td className="tabular px-3 py-2 text-right">
                                        {anchor.events_count}
                                    </td>
                                    <td className="px-3 py-2">
                                        <HashText
                                            hash={anchor.digest}
                                            label={t(
                                                'people.inspection.anchor_digest',
                                            )}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    {t('people.inspection.no_anchors')}
                </p>
            )}
        </section>
    );
}

function VerifySection({ props }: { props: InspectionPageProps }) {
    const id = useId();
    const [file, setFile] = useState<File | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const result = props.verification;

    return (
        <section
            aria-labelledby={`${id}-title`}
            className="grid gap-3 rounded-md border p-4"
        >
            <div className="space-y-1">
                <h2 id={`${id}-title`} className="text-lg">
                    {t('people.inspection.verify_title')}
                </h2>
                <p className="max-w-3xl text-sm text-muted-foreground">
                    {t('people.inspection.verify_hint')}
                </p>
            </div>
            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (!file) {
                        return;
                    }
                    router.post(
                        verifyFile.url(),
                        { file },
                        {
                            forceFormData: true,
                            preserveScroll: true,
                            onStart: () => setProcessing(true),
                            onFinish: () => setProcessing(false),
                            onError: (errors) => setError(errors.file),
                        },
                    );
                }}
            >
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-file`}>
                        {t('people.inspection.verify_file')}
                    </Label>
                    <Input
                        id={`${id}-file`}
                        type="file"
                        onChange={(event) =>
                            setFile(event.target.files?.[0] ?? null)
                        }
                        className="max-w-sm"
                        data-test="verify-file"
                    />
                    <InputError message={error} />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    disabled={!file || processing}
                    data-test="verify-submit"
                >
                    {processing ? (
                        <Spinner />
                    ) : (
                        <FileSearch aria-hidden="true" />
                    )}
                    {t('people.inspection.verify_button')}
                </Button>
            </form>
            {result ? (
                <div
                    role="status"
                    className={cn(
                        'grid gap-1 rounded-md p-3 text-sm',
                        result.match || result.close
                            ? 'bg-success-soft'
                            : 'bg-warning-soft',
                    )}
                    data-test="verify-result"
                    data-match={result.match || result.close ? 'true' : 'false'}
                >
                    <p>
                        {result.match
                            ? t('people.inspection.verify_match', {
                                  name: result.name,
                                  kind: exportKindLabel(result.match.kind),
                                  date: formatDateTime(result.match.created_at),
                                  by: result.match.by ?? '—',
                              })
                            : result.close
                              ? t('people.inspection.verify_close', {
                                    name: result.name,
                                    user: result.close.user,
                                    month: result.close.month,
                                    version: result.close.version,
                                })
                              : t('people.inspection.verify_no_match', {
                                    name: result.name,
                                })}
                    </p>
                    <HashText
                        hash={result.sha256}
                        label={t('people.reports.file_hash')}
                    />
                </div>
            ) : null}
        </section>
    );
}

function AccessSection({ props }: { props: InspectionPageProps }) {
    const id = useId();
    const [processing, setProcessing] = useState(false);

    return (
        <section
            aria-labelledby={`${id}-title`}
            className="grid gap-4 rounded-md border p-4"
            data-test="inspection-access"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                    <h2 id={`${id}-title`} className="text-lg">
                        {t('people.inspection.access_title')}
                    </h2>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {t('people.inspection.access_hint', {
                            days: props.max_access_days,
                        })}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Switch
                        id={`${id}-enabled`}
                        checked={props.enabled}
                        disabled={processing}
                        onCheckedChange={(checked) =>
                            router.put(
                                toggle.url(),
                                { enabled: checked },
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                },
                            )
                        }
                        data-test="inspection-toggle"
                    />
                    <Label htmlFor={`${id}-enabled`}>
                        {props.enabled
                            ? t('people.inspection.enabled')
                            : t('people.inspection.disabled')}
                    </Label>
                </div>
            </div>

            {props.created ? (
                <div
                    className="grid gap-2 rounded-md border border-info p-3 text-sm"
                    role="status"
                    data-test="inspection-created"
                >
                    <p className="flex items-center gap-2">
                        <KeyRound
                            aria-hidden="true"
                            className="size-4 text-info"
                        />
                        {t('people.inspection.created_once')}
                    </p>
                    <CopyLine
                        label={t('people.inspection.link')}
                        value={props.created.url}
                    />
                    <CopyLine
                        label={t('people.inspection.code')}
                        value={props.created.code}
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('people.inspection.created_hint')}
                    </p>
                </div>
            ) : null}

            {props.enabled ? <AccessForm props={props} /> : null}

            {props.accesses.length > 0 ? (
                <ul
                    className="divide-y rounded-md border text-sm"
                    data-test="inspection-accesses"
                >
                    {props.accesses.map((access) => (
                        <li
                            key={access.id}
                            className="grid gap-2 px-3 py-2 sm:grid-cols-[1fr_auto_auto] sm:items-center"
                        >
                            <span className="min-w-0">
                                <span className="block">
                                    {access.name}
                                    {access.reference
                                        ? ` · ${access.reference}`
                                        : ''}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {t('people.inspection.access_detail', {
                                        email: access.email,
                                        from: formatDate(access.scope_from),
                                        to: formatDate(access.scope_to),
                                        until: formatDateTime(
                                            access.valid_until,
                                        ),
                                        by: access.created_by,
                                    })}
                                </span>
                                {access.last_used_at ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {t('people.inspection.last_used', {
                                            date: formatDateTime(
                                                access.last_used_at,
                                            ),
                                        })}
                                    </span>
                                ) : null}
                            </span>
                            <span
                                className={cn(
                                    'justify-self-start rounded-md px-1.5 py-0.5 text-xs',
                                    access.state === 'active'
                                        ? 'bg-success-soft'
                                        : 'bg-neutral-soft',
                                )}
                                data-test="access-state"
                            >
                                {accessStateLabel(access.state)}
                            </span>
                            {access.state === 'active' ||
                            access.state === 'scheduled' ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            revoke.url(access.id),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    data-test="access-revoke"
                                >
                                    <Ban aria-hidden="true" />
                                    {t('people.inspection.revoke')}
                                </Button>
                            ) : (
                                <span />
                            )}
                        </li>
                    ))}
                </ul>
            ) : null}
        </section>
    );
}

function CopyLine({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="w-16 text-xs text-muted-foreground">{label}</span>
            <code className="min-w-0 flex-1 rounded-md bg-muted px-2 py-1 text-xs break-all">
                {value}
            </code>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => {
                    void navigator.clipboard?.writeText(value);
                    setCopied(true);
                }}
            >
                <Copy aria-hidden="true" />
                {copied
                    ? t('people.inspection.copied')
                    : t('people.inspection.copy')}
            </Button>
        </div>
    );
}

function AccessForm({ props }: { props: InspectionPageProps }) {
    const id = useId();
    const [data, setData] = useState({
        name: '',
        email: '',
        reference: '',
        scope_from: props.period.from,
        scope_to: props.period.to,
        valid_from: props.today,
        days: 7,
    });
    const [selected, setSelected] = useState<number[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const field = (key: keyof typeof data) => ({
        id: `${id}-${key}`,
        value: String(data[key]),
        onChange: (event: React.ChangeEvent<HTMLInputElement>) =>
            setData({
                ...data,
                [key]:
                    key === 'days'
                        ? Number(event.target.value)
                        : event.target.value,
            }),
        'aria-invalid': errors[key] ? true : undefined,
    });

    return (
        <form
            className="grid gap-3 rounded-md bg-muted/40 p-3"
            onSubmit={(event) => {
                event.preventDefault();
                router.post(
                    store.url(),
                    { ...data, user_ids: selected },
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                        onError: (bag) => setErrors(bag),
                        onSuccess: () => setErrors({}),
                    },
                );
            }}
            data-test="access-form"
        >
            <h3 className="text-sm font-medium">
                {t('people.inspection.new_access')}
            </h3>
            <div className="grid items-start gap-3 sm:grid-cols-3">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-name`}>
                        {t('people.inspection.name')}
                    </Label>
                    <Input
                        {...field('name')}
                        required
                        maxLength={120}
                        data-test="access-name"
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-email`}>
                        {t('people.inspection.email')}
                    </Label>
                    <Input
                        {...field('email')}
                        type="email"
                        required
                        maxLength={190}
                        data-test="access-email"
                    />
                    <InputError message={errors.email} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-reference`}>
                        {t('people.inspection.reference')}
                    </Label>
                    <Input {...field('reference')} maxLength={120} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-scope_from`}>
                        {t('people.inspection.scope_from')}
                    </Label>
                    <Input
                        {...field('scope_from')}
                        type="date"
                        max={props.today}
                    />
                    <InputError message={errors.scope_from} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-scope_to`}>
                        {t('people.inspection.scope_to')}
                    </Label>
                    <Input {...field('scope_to')} type="date" />
                    <InputError message={errors.scope_to} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-days`}>
                        {t('people.inspection.days')}
                    </Label>
                    <Input
                        {...field('days')}
                        type="number"
                        min={1}
                        max={props.max_access_days}
                    />
                    <InputError message={errors.days} />
                </div>
            </div>
            <PeoplePicker
                people={props.people}
                selected={selected}
                onChange={setSelected}
                idPrefix={`${id}-scope`}
            />
            <InputError message={errors.enabled} />
            <Button
                type="submit"
                className="justify-self-start"
                disabled={processing}
                data-test="access-create"
            >
                {processing ? <Spinner /> : <KeyRound aria-hidden="true" />}
                {t('people.inspection.create')}
            </Button>
        </form>
    );
}
