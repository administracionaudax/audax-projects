import { router, useForm } from '@inertiajs/react';
import { Crown, ShieldCheck, UserMinus, UserPlus } from 'lucide-react';
import { useId, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PersonSelect } from '@/components/projects-list/person-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import { destroy, store, update } from '@/routes/projects/members';
import type { ProjectMember, ProjectPersonOption } from '@/types';

/**
 * Miembros y gestores del proyecto (D-005, D-032): añadir personas internas activas, marcar o
 * desmarcar gestor y quitar miembros. El gestor principal siempre es gestor y no se quita.
 */
export function ProjectMembers({
    projectId,
    members,
    people,
    canManage,
}: {
    projectId: number;
    members: ProjectMember[];
    people: ProjectPersonOption[];
    canManage: boolean;
}) {
    const memberIds = new Set(members.map((member) => member.id));
    const candidates = people.filter((person) => !memberIds.has(person.id));

    return (
        <div className="grid gap-6">
            <ul className="divide-y rounded-md border">
                {members.map((member) => (
                    <MemberRow
                        key={member.id}
                        projectId={projectId}
                        member={member}
                        department={
                            people.find((person) => person.id === member.id)
                                ?.department ?? null
                        }
                        canManage={canManage}
                    />
                ))}
            </ul>

            {canManage ? (
                <AddMember projectId={projectId} candidates={candidates} />
            ) : null}
        </div>
    );
}

function MemberRow({
    projectId,
    member,
    department,
    canManage,
}: {
    projectId: number;
    member: ProjectMember;
    department: string | null;
    canManage: boolean;
}) {
    const switchId = useId();
    const [processing, setProcessing] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const toggleManager = (isManager: boolean) => {
        router.patch(
            update.url({ project: projectId, user: member.id }),
            { is_manager: isManager },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const remove = () => {
        router.delete(destroy.url({ project: projectId, user: member.id }), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirming(false);
            },
        });
    };

    return (
        <li
            className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center"
            data-test="project-member"
        >
            <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-medium">{member.name}</span>
                    {member.is_owner ? (
                        <Badge className="gap-1 border-transparent bg-info-soft font-medium text-foreground">
                            <Crown
                                aria-hidden="true"
                                className="size-3.5 text-info"
                            />
                            {t('projects.members.owner')}
                        </Badge>
                    ) : member.is_manager ? (
                        <Badge className="gap-1 border-transparent bg-neutral-soft font-medium text-foreground">
                            <ShieldCheck
                                aria-hidden="true"
                                className="size-3.5 text-muted-foreground"
                            />
                            {t('projects.members.manager')}
                        </Badge>
                    ) : null}
                    {!member.is_active ? (
                        <Badge variant="outline" className="font-normal">
                            {t('projects.members.inactive')}
                        </Badge>
                    ) : null}
                </p>
                {department ? (
                    <p className="text-xs text-muted-foreground">
                        {department}
                    </p>
                ) : null}
            </div>

            {canManage ? (
                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex items-center gap-2">
                        <Switch
                            id={switchId}
                            checked={member.is_manager}
                            disabled={member.is_owner || processing}
                            onCheckedChange={toggleManager}
                        />
                        <Label htmlFor={switchId} className="font-normal">
                            {t('projects.members.is_manager')}
                        </Label>
                    </div>
                    {member.is_owner ? null : (
                        <ConfirmDialog
                            open={confirming}
                            onOpenChange={setConfirming}
                            trigger={
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={t(
                                        'projects.members.remove_label',
                                        { name: member.name },
                                    )}
                                >
                                    <UserMinus aria-hidden="true" />
                                    {t('projects.members.remove')}
                                </Button>
                            }
                            title={t('projects.members.remove_title', {
                                name: member.name,
                            })}
                            description={t(
                                'projects.members.remove_description',
                            )}
                            confirmLabel={t('projects.members.remove')}
                            processing={processing}
                            onConfirm={remove}
                        />
                    )}
                </div>
            ) : null}
        </li>
    );
}

function AddMember({
    projectId,
    candidates,
}: {
    projectId: number;
    candidates: ProjectPersonOption[];
}) {
    const id = useId();
    const form = useForm<{ user_id: number | null; is_manager: boolean }>({
        user_id: null,
        is_manager: false,
    });

    if (candidates.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('projects.members.everyone_in')}
            </p>
        );
    }

    return (
        <form
            noValidate
            className="grid gap-3 rounded-md border border-dashed p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.submit(store(projectId), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <p className="text-sm font-medium">{t('projects.members.add')}</p>
            <div className="flex flex-wrap items-end gap-4">
                <PersonSelect
                    id={`${id}-person`}
                    label={t('projects.members.person')}
                    placeholder={t('projects.members.choose_person')}
                    people={candidates}
                    value={form.data.user_id}
                    onChange={(value) => form.setData('user_id', value)}
                    className="min-w-0 flex-1 basis-64"
                />
                <div className="flex h-9 items-center gap-2">
                    <Checkbox
                        id={`${id}-manager`}
                        checked={form.data.is_manager}
                        onCheckedChange={(value) =>
                            form.setData('is_manager', value === true)
                        }
                    />
                    <Label htmlFor={`${id}-manager`} className="font-normal">
                        {t('projects.members.as_manager')}
                    </Label>
                </div>
                <Button
                    type="submit"
                    disabled={form.processing || form.data.user_id === null}
                >
                    {form.processing ? (
                        <Spinner />
                    ) : (
                        <UserPlus aria-hidden="true" />
                    )}
                    {t('projects.members.add_button')}
                </Button>
            </div>
            <InputError message={form.errors.user_id} />
        </form>
    );
}
