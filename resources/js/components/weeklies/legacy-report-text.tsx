import { FileText } from 'lucide-react';
import { t } from '@/lib/i18n';

export type ReportTextLine = {
    kind: 'heading' | 'subheading' | 'text';
    text: string;
};

export type ReportTextSection = {
    /** null: lo que va antes del primer «## ». */
    title: string | null;
    anchor: string;
    lines: ReportTextLine[];
};

/** Quita las marcas de negrita y cursiva de Markdown («**Siguientes pasos:**»). */
function plain(line: string): string {
    return line.replace(/\*\*(.+?)\*\*/g, '$1').replace(/__(.+?)__/g, '$1');
}

/**
 * El texto final de una semana sin informe estructurado (las importadas de WeeklySync que solo
 * tenían `final_report_text`, 10.9b): como el original (`ws:ReportView.tsx`), cada «## » abre una
 * sección del índice; «### » es un subtítulo y el resto, párrafos (una línea, un párrafo).
 */
export function reportTextSections(text: string): ReportTextSection[] {
    const sections: ReportTextSection[] = [];
    let current: ReportTextSection = {
        title: null,
        anchor: 'seccion-0',
        lines: [],
    };

    text.replace(/\r\n?/g, '\n')
        .split('\n')
        .forEach((raw) => {
            const line = raw.trimEnd();
            const h2 = /^##\s+(.+)$/.exec(line);

            if (h2) {
                if (current.title !== null || current.lines.length > 0) {
                    sections.push(current);
                }

                current = {
                    title: plain(h2[1].trim()),
                    anchor: `seccion-${sections.length + 1}`,
                    lines: [],
                };

                return;
            }

            const h1 = /^#\s+(.+)$/.exec(line);
            const h3 = /^#{3,6}\s+(.+)$/.exec(line);

            if (h1 || h3) {
                current.lines.push({
                    kind: h1 ? 'heading' : 'subheading',
                    text: plain((h1 ?? h3)![1].trim()),
                });

                return;
            }

            if (line.trim() !== '') {
                current.lines.push({ kind: 'text', text: plain(line) });
            }
        });

    if (current.title !== null || current.lines.length > 0) {
        sections.push(current);
    }

    return sections;
}

/** El texto de una semana importada sin informe estructurado, por secciones (10.9b). */
export function LegacyReportText({
    sections,
}: {
    sections: ReportTextSection[];
}) {
    return (
        <div className="grid gap-6" data-test="weekly-legacy-text">
            <p className="flex items-center gap-2 bg-muted/50 p-3 text-xs text-muted-foreground">
                <FileText aria-hidden="true" className="size-4 shrink-0" />
                {t('weeklies.report.legacy_text')}
            </p>
            {sections.map((section) => (
                <section
                    key={section.anchor}
                    id={section.anchor}
                    aria-label={section.title ?? undefined}
                    className="grid scroll-mt-4 gap-2"
                >
                    {section.title !== null ? (
                        <h2 className="text-xl">{section.title}</h2>
                    ) : null}
                    {section.lines.map((line, index) =>
                        line.kind === 'heading' ? (
                            <p key={index} className="text-lg">
                                {line.text}
                            </p>
                        ) : line.kind === 'subheading' ? (
                            <h3 key={index} className="mt-2 text-base">
                                {line.text}
                            </h3>
                        ) : (
                            <p
                                key={index}
                                className="whitespace-pre-line text-muted-foreground"
                            >
                                {line.text}
                            </p>
                        ),
                    )}
                </section>
            ))}
        </div>
    );
}
