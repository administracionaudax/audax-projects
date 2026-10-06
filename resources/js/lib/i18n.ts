/**
 * Traducciones de la interfaz (SPEC §15: textos en ficheros de idioma, `es` completo).
 *
 * - Fuentes:
 *   · `lang/es.json`: textos de la Fase 0 y claves del backend (`__()` de Laravel lo lee),
 *   · `lang/ui/*.json`: textos del frontend por área desde la Fase 1 (shared, admin, clients,
 *     projects, hour-banks, tasks, time, notifications y reports; en la Fase 5, portal-access; en
 *     la Fase 6, el chat; en la Fase 7, las preferencias de notificación, la auditoría y la
 *     privacidad; en la Fase 8, el orden de las tarjetas de Inicio; en la Fase 9, el envío de
 *     informes, Mis tareas y el calendario del equipo; en la Fase 10, la Weekly y sus fichas, weekly-insights, sus avisos, weekly-reminders, las tareas de Mi espacio, my-space-tasks, y el asistente, assistant; y el centro de ayuda, help, y sus sugerencias, suggestions; el plan del día, day-plan; y la previsión, forecast). Laravel no los lee.
 *   Una clave solo puede estar en un fichero (tests/js/i18n.test.ts).
 * - Se importan en la compilación (Vite los incrusta en el bundle): no hay petición en runtime.
 * - Claves del frontend: semánticas, en inglés y con puntos (`nav.projects`, `login.title`).
 *   Nunca empiezan por `auth.`, `pagination.`, `passwords.`, `validation.` ni `time.`, para no
 *   pisar los grupos PHP de `lang/es/*.php` (`__('auth.failed')` busca primero en el JSON).
 * - Claves del backend: el texto de origen en inglés, como hace Laravel (`"Profile updated."`),
 *   o grupos PHP en `lang/es/*.php` (`__('time.errors.future_date')`).
 * - Reemplazos al estilo Laravel: `:name` → valor, `:Name` → primera letra en mayúscula,
 *   `:NAME` → todo en mayúsculas.
 */
import base from '../../../lang/es.json';
import absences from '../../../lang/ui/absences.json';
import assistant from '../../../lang/ui/assistant.json';
import admin from '../../../lang/ui/admin.json';
import audit from '../../../lang/ui/audit.json';
import calendar from '../../../lang/ui/calendar.json';
import chatMedia from '../../../lang/ui/chat-media.json';
import chat from '../../../lang/ui/chat.json';
import clients from '../../../lang/ui/clients.json';
import dayPlan from '../../../lang/ui/day-plan.json';
import forecast from '../../../lang/ui/forecast.json';
import gantt from '../../../lang/ui/gantt.json';
import home from '../../../lang/ui/home.json';
import integrations from '../../../lang/ui/integrations.json';
import help from '../../../lang/ui/help.json';
import hourBanks from '../../../lang/ui/hour-banks.json';
import mySpaceTasks from '../../../lang/ui/my-space-tasks.json';
import myTasks from '../../../lang/ui/my-tasks.json';
import notificationSettings from '../../../lang/ui/notification-settings.json';
import notifications from '../../../lang/ui/notifications.json';
import planning from '../../../lang/ui/planning.json';
import portalBanks from '../../../lang/ui/portal-banks.json';
import portalAccess from '../../../lang/ui/portal-access.json';
import privacy from '../../../lang/ui/privacy.json';
import projects from '../../../lang/ui/projects.json';
import realtime from '../../../lang/ui/realtime.json';
import reportDeliveries from '../../../lang/ui/report-deliveries.json';
import reports from '../../../lang/ui/reports.json';
import reportsR2 from '../../../lang/ui/reports-r2.json';
import reportsR1 from '../../../lang/ui/reports-r1.json';
import reportsR3 from '../../../lang/ui/reports-r3.json';
import shared from '../../../lang/ui/shared.json';
import suggestions from '../../../lang/ui/suggestions.json';
import tasks from '../../../lang/ui/tasks.json';
import templates from '../../../lang/ui/templates.json';
import time from '../../../lang/ui/time.json';
import weeklies from '../../../lang/ui/weeklies.json';
import weeklyInsights from '../../../lang/ui/weekly-insights.json';
import weeklyReminders from '../../../lang/ui/weekly-reminders.json';
import workload from '../../../lang/ui/workload.json';

const messages = {
    ...base,
    ...shared,
    ...admin,
    ...audit,
    ...privacy,
    ...clients,
    ...projects,
    ...home,
    ...hourBanks,
    ...tasks,
    ...myTasks,
    ...calendar,
    ...time,
    ...notifications,
    ...notificationSettings,
    ...realtime,
    ...chatMedia,
    ...chat,
    ...reports,
    ...reportsR2,
    ...reportsR1,
    ...reportsR3,
    ...reportDeliveries,
    ...absences,
    ...workload,
    ...gantt,
    ...planning,
    ...templates,
    ...portalBanks,
    ...portalAccess,
    ...integrations,
    ...weeklies,
    ...weeklyInsights,
    ...weeklyReminders,
    ...mySpaceTasks,
    ...assistant,
    ...help,
    ...suggestions,
    ...dayPlan,
    ...forecast,
};

export type TranslationKey = keyof typeof messages;

export type Replacements = Record<string, string | number>;

const dictionary: Readonly<Record<string, string>> = messages;

function capitalize(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

/** Traduce una clave de `lang/es.json`. Si no existe, devuelve la propia clave. */
export function t(key: TranslationKey, replacements?: Replacements): string {
    let line = dictionary[key] ?? key;

    if (!replacements) {
        return line;
    }

    // Primero las claves más largas, para que `:name` no se coma `:names`.
    const names = Object.keys(replacements).sort((a, b) => b.length - a.length);

    for (const name of names) {
        const value = String(replacements[name]);

        line = line
            .replaceAll(`:${name.toUpperCase()}`, value.toUpperCase())
            .replaceAll(`:${capitalize(name)}`, capitalize(value))
            .replaceAll(`:${name}`, value);
    }

    return line;
}

/** Comprueba si una clave dinámica existe (útil con valores que llegan del servidor). */
export function hasTranslation(key: string): key is TranslationKey {
    return Object.hasOwn(dictionary, key);
}
