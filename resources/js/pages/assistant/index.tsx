import { Head } from '@inertiajs/react';
import {
    Bot,
    Info,
    Loader2,
    RotateCcw,
    Search,
    Send,
    UserRound,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { useAssistant } from '@/components/assistant/use-assistant';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as assistantIndex } from '@/routes/assistant';
import type { AssistantPageProps } from '@/types/weeklies';

/**
 * /ia: el asistente IA (F-006, F-146 y F-147; KnowledgeBaseView de WeeklySync). Chat de preguntas y
 * respuestas con las preguntas sugeridas, Intro para enviar (Mayúsculas+Intro, salto de línea) y el
 * historial de la sesión. Responde con SOLO lo que puede ver quien pregunta (D-205), en la cola `ai`
 * (D-206). Siempre avisa de que es IA y puede equivocarse.
 */
export default function Assistant({
    suggested_questions: suggested,
    scope,
    max_question: maxQuestion,
}: AssistantPageProps) {
    const { messages, waiting, send, reset } = useAssistant();
    const [input, setInput] = useState('');
    const end = useRef<HTMLDivElement>(null);
    const inputId = useId();

    useEffect(() => {
        end.current?.scrollIntoView?.({ behavior: 'smooth', block: 'end' });
    }, [messages.length, waiting]);

    const submit = (text: string = input) => {
        if (text.trim() === '' || waiting) {
            return;
        }

        setInput('');
        void send(text);
    };

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        submit();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.nativeEvent.isComposing
        ) {
            event.preventDefault();
            submit();
        }
    };

    const hours =
        scope.hours === 'all'
            ? t('assistant.scope.hours_all')
            : scope.hours === 'team'
              ? t('assistant.scope.hours_team')
              : t('assistant.scope.hours_own');

    return (
        <>
            <Head title={t('assistant.title')} />
            <div className="mx-auto flex w-full max-w-4xl min-w-0 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        as="h1"
                        title={t('assistant.heading')}
                        description={t('assistant.page_description')}
                    />
                    {messages.length > 0 ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={reset}
                            disabled={waiting}
                            data-test="assistant-reset"
                        >
                            <RotateCcw aria-hidden="true" />
                            {t('assistant.reset')}
                        </Button>
                    ) : null}
                </div>

                <p
                    className="flex items-start gap-2 border bg-muted/40 p-3 text-sm"
                    data-test="assistant-scope"
                >
                    <Info
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-info"
                    />
                    <span>
                        {t('assistant.scope.intro')} {hours}
                        {scope.financials
                            ? ` ${t('assistant.scope.financials')}`
                            : ` ${t('assistant.scope.no_financials')}`}
                    </span>
                </p>

                <section
                    aria-label={t('assistant.conversation')}
                    className="flex min-h-[50dvh] flex-col border bg-card"
                >
                    <div
                        role="log"
                        aria-live="polite"
                        aria-busy={waiting}
                        className="flex-1 space-y-4 overflow-y-auto bg-muted/20 p-3 md:p-6"
                        data-test="assistant-log"
                    >
                        {messages.length === 0 ? (
                            <div className="grid justify-items-center gap-3 p-2 text-center md:p-6">
                                <Search
                                    aria-hidden="true"
                                    className="hidden size-10 text-muted-foreground md:block"
                                    strokeWidth={1.5}
                                />
                                <h2 className="text-base font-normal">
                                    {t('assistant.empty.title')}
                                </h2>
                                <p className="max-w-md text-sm text-muted-foreground">
                                    {t('assistant.empty.description')}
                                </p>
                                <ul
                                    className="grid w-full max-w-2xl gap-2 md:grid-cols-2"
                                    aria-label={t('assistant.suggested')}
                                >
                                    {suggested.map((question, index) => (
                                        <li
                                            key={question}
                                            className={cn(
                                                index > 3 && 'hidden md:block',
                                            )}
                                        >
                                            <button
                                                type="button"
                                                onClick={() => submit(question)}
                                                className={cn(
                                                    'w-full border bg-background p-3 text-left text-sm hover:border-primary hover:text-primary-text',
                                                    FOCUS_RING,
                                                )}
                                                data-test={`assistant-suggestion-${index}`}
                                            >
                                                {question}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : (
                            messages.map((item) => (
                                <div
                                    key={item.id}
                                    className={cn(
                                        'flex gap-3',
                                        item.role === 'user'
                                            ? 'justify-end'
                                            : 'justify-start',
                                    )}
                                    data-test={`assistant-message-${item.role}`}
                                >
                                    {item.role === 'assistant' ? (
                                        <span
                                            aria-hidden="true"
                                            className="mt-1 flex size-8 shrink-0 items-center justify-center bg-primary text-primary-foreground"
                                        >
                                            <Bot className="size-4" />
                                        </span>
                                    ) : null}
                                    <div
                                        className={cn(
                                            'max-w-[85%] border p-3 text-sm leading-relaxed break-words whitespace-pre-wrap',
                                            item.role === 'user'
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : item.error
                                                  ? 'border-danger bg-background text-foreground'
                                                  : 'bg-background text-foreground',
                                        )}
                                    >
                                        <span className="sr-only">
                                            {item.role === 'user'
                                                ? t('assistant.you_said')
                                                : t('assistant.it_said')}
                                        </span>
                                        {item.content}
                                    </div>
                                    {item.role === 'user' ? (
                                        <span
                                            aria-hidden="true"
                                            className="mt-1 flex size-8 shrink-0 items-center justify-center border bg-muted"
                                        >
                                            <UserRound className="size-4" />
                                        </span>
                                    ) : null}
                                </div>
                            ))
                        )}

                        {waiting ? (
                            <div
                                className="flex justify-start gap-3"
                                data-test="assistant-loading"
                            >
                                <span
                                    aria-hidden="true"
                                    className="flex size-8 shrink-0 items-center justify-center bg-primary text-primary-foreground"
                                >
                                    <Bot className="size-4" />
                                </span>
                                <p className="flex items-center gap-2 border bg-background p-3 text-sm text-muted-foreground">
                                    <Loader2
                                        aria-hidden="true"
                                        className="size-4 animate-spin"
                                    />
                                    {t('assistant.thinking')}
                                </p>
                            </div>
                        ) : null}
                        <div ref={end} />
                    </div>

                    <form
                        onSubmit={onSubmit}
                        className="grid gap-2 border-t p-3"
                    >
                        <label htmlFor={inputId} className="sr-only">
                            {t('assistant.input_label')}
                        </label>
                        <div className="flex items-end gap-2">
                            <Textarea
                                id={inputId}
                                value={input}
                                onChange={(event) =>
                                    setInput(event.target.value)
                                }
                                onKeyDown={onKeyDown}
                                rows={1}
                                maxLength={maxQuestion}
                                placeholder={t('assistant.placeholder')}
                                aria-describedby={`${inputId}-hint`}
                                className="max-h-40 min-h-10 flex-1 resize-y text-sm"
                                data-test="assistant-input"
                            />
                            <Button
                                type="submit"
                                size="icon"
                                // Del alto mínimo de la caja (40 px), no 36 px a su lado.
                                className="size-10"
                                disabled={waiting || input.trim() === ''}
                                aria-label={t('assistant.send')}
                                data-test="assistant-send"
                            >
                                {waiting ? (
                                    <Loader2
                                        aria-hidden="true"
                                        className="animate-spin"
                                    />
                                ) : (
                                    <Send aria-hidden="true" />
                                )}
                            </Button>
                        </div>
                        <p
                            id={`${inputId}-hint`}
                            className="text-center text-xs text-muted-foreground"
                        >
                            {t('assistant.disclaimer')}
                        </p>
                    </form>
                </section>
            </div>
        </>
    );
}

Assistant.layout = {
    breadcrumbs: [{ title: t('assistant.title'), href: assistantIndex() }],
};
