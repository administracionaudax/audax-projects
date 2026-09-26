import { Pencil } from 'lucide-react';
import { useState } from 'react';
import { RichTextContent } from '@/components/rich-text/rich-text-content';
import { LazyRichTextEditor } from '@/components/tasks/lazy-rich-text-editor';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import { updateTask } from '@/components/tasks/task-requests';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import type { TaskPanelData } from '@/types';

/**
 * Descripción de la tarea: se lee el HTML ya saneado por el servidor y se edita con Tiptap
 * (menciones con @). Al guardar, el servidor la sanea de nuevo y avisa a los mencionados nuevos.
 */
export function TaskDescription({
    panel,
    headingId,
}: {
    panel: TaskPanelData;
    headingId: string;
}) {
    const lookups = useTaskLookups();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(panel.task.description ?? '');
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        updateTask(
            panel.task.id,
            { description: draft },
            {
                onSuccess: () => setEditing(false),
                onFinish: () => setSaving(false),
            },
        );
    };

    if (editing) {
        return (
            <div className="grid gap-2">
                <LazyRichTextEditor
                    value={panel.task.description ?? ''}
                    onChange={setDraft}
                    mentionables={lookups.users}
                    placeholder={t('task_panel.description_placeholder')}
                    aria-label={t('task_panel.description')}
                    autoFocus
                    onSubmit={save}
                />
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setDraft(panel.task.description ?? '');
                            setEditing(false);
                        }}
                        disabled={saving}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        onClick={save}
                        disabled={saving}
                    >
                        {saving ? <Spinner /> : null}
                        {t('common.save')}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            {panel.task.description ? (
                <RichTextContent html={panel.task.description} />
            ) : (
                <p className="text-sm text-muted-foreground">
                    {t('task_panel.no_description')}
                </p>
            )}
            {panel.can.update ? (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        aria-describedby={headingId}
                        onClick={() => {
                            setDraft(panel.task.description ?? '');
                            setEditing(true);
                        }}
                    >
                        <Pencil aria-hidden="true" />
                        {panel.task.description
                            ? t('task_panel.edit_description')
                            : t('task_panel.add_description')}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
