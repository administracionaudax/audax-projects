import { Head, setLayoutProps } from '@inertiajs/react';
import { MessagesSquare } from 'lucide-react';
import { ConversationView } from '@/components/chat/conversation-view';
import { EmptyState } from '@/components/empty-state';
import { ProjectShell } from '@/components/projects/project-shell';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { index as projectsIndex } from '@/routes/projects';
import type { ChatProjectPageProps } from '@/types/chat';

/**
 * Pestaña Chat del proyecto (SPEC §6 y §12): la conversación del proyecto, con sus miembros. El
 * admin la ve y la modera sin escribir; en un proyecto archivado es de solo lectura; quien no es
 * miembro ve por qué no puede entrar (D-071).
 */
export default function ProjectChat({
    project,
    canManage,
    conversation,
    messages,
    pinned,
    focus,
}: ChatProjectPageProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.projects'), href: projectsIndex() },
            { title: project.name, href: urls.project(project.id) },
            {
                title: t('project_tabs.chat'),
                href: urls.project(project.id, 'chat'),
            },
        ],
    });

    return (
        <>
            <Head title={`${t('chat.project.title')} · ${project.name}`} />

            <ProjectShell project={project} tab="chat" canManage={canManage}>
                {conversation && messages ? (
                    <div className="flex h-[70svh] min-h-[28rem] flex-col overflow-hidden rounded-[3px] border">
                        <ConversationView
                            key={`${conversation.id}:${focus ?? ''}`}
                            conversation={conversation}
                            initial={messages}
                            pinned={pinned}
                            focus={focus}
                            showProjectLink={false}
                        />
                    </div>
                ) : (
                    <EmptyState
                        icon={MessagesSquare}
                        title={t('chat.project.no_access.title')}
                        description={t('chat.project.no_access.description')}
                    />
                )}
            </ProjectShell>
        </>
    );
}

ProjectChat.layout = {
    breadcrumbs: [{ title: t('nav.projects'), href: projectsIndex() }],
};
