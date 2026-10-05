<?php

namespace App\Domain\Import\WeeklySync\Stages;

use App\Domain\Admin\WorkScheduleVersions;
use App\Domain\Import\WeeklySync\WeeklySyncContext;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport as Report;
use App\Domain\Import\WeeklySync\WeeklySyncNames;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Personas (D-149 y D-214). Para cada persona de WeeklySync, en este orden:
 *   1. el fichero de personas (`email`, `import: false` o `create: true`),
 *   2. la correspondencia de una importación anterior (import_refs),
 *   3. cualquiera de sus correos (el suyo, el canónico y los de sus identidades de Google) contra
 *      el correo de una cuenta de Audax, sin distinguir mayúsculas,
 *   4. si no casa, se crea una cuenta INACTIVA (empleado, sin contraseña conocida, sin invitación)
 *      para conservar quién escribió qué. Solo si escribió algo o contaba para la weekly (no las
 *      cuentas «PENDING» sin nada escrito).
 * No se cambian el rol, el departamento ni el estado de las cuentas que ya existen (mandan los de
 * Audax); solo se rellena el puesto (`job_title`) si está vacío.
 */
final class PeopleStage
{
    public function __construct(private readonly WorkScheduleVersions $schedules) {}

    public function run(WeeklySyncContext $context): void
    {
        $users = $context->rows('users');
        $identities = $context->rows('user_identities');
        $authored = $this->authored($context);

        /** @var array<string, list<string>> $emails persona => correos */
        $emails = [];
        foreach ($users as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $emails[$id] = array_values(array_unique(array_filter([
                WeeklySyncNames::email($row['email'] ?? null),
                WeeklySyncNames::email($row['canonical_email'] ?? null),
            ])));
        }
        foreach ($identities as $identity) {
            $id = WeeklySyncContext::id($identity['user_id'] ?? null);
            foreach (['email_normalized', 'email'] as $field) {
                $email = WeeklySyncNames::email($identity[$field] ?? null);
                if ($email !== '' && isset($emails[$id]) && ! in_array($email, $emails[$id], true)) {
                    $emails[$id][] = $email;
                }
            }
        }

        /** @var array<string, User> $audax correo => cuenta */
        $audax = [];
        foreach (User::query()->get(['id', 'name', 'email', 'job_title', 'is_active']) as $user) {
            $audax[Str::lower($user->email)] = $user;
            $context->usersByEmail[Str::lower($user->email)] = $user->id;
        }

        foreach ($users as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $name = WeeklySyncContext::str($row['name'] ?? null);
            $rule = $context->mappings->person($id, $emails[$id] ?? []);

            if ($rule !== null && ! $rule['import']) {
                $context->users[$id] = null;
                $context->report->skip('people', 'Personas fuera por el fichero de personas');

                continue;
            }

            $user = null;

            if ($rule !== null && $rule['email'] !== null) {
                $user = $audax[$rule['email']] ?? null;

                if ($user === null) {
                    $context->report->warn("El fichero de personas lleva a {$name} a {$rule['email']}, que no es ninguna cuenta de Audax: se busca por sus correos.");
                }
            }

            if ($user === null && ($rule === null || ! $rule['create'])) {
                $local = $context->refs->find('user', $id);
                $user = $local !== null ? User::query()->find($local) : null;
            }

            if ($user === null && ($rule === null || ! $rule['create'])) {
                foreach ($emails[$id] ?? [] as $email) {
                    if (isset($audax[$email])) {
                        $user = $audax[$email];

                        break;
                    }
                }
            }

            if ($user === null) {
                $pending = strtoupper(WeeklySyncContext::str($row['account_status'] ?? '')) === 'PENDING';

                if (! ($rule['create'] ?? false) && ! isset($authored[$id]) && $pending) {
                    $context->users[$id] = null;
                    $context->report->skip('people', 'Cuentas pendientes de WeeklySync sin nada escrito');

                    continue;
                }

                $email = $emails[$id][0] ?? null;

                if ($email === null || isset($audax[$email])) {
                    // Sin correo propio (o ya usado): uno interno que no recibe nada.
                    $email = 'weeklysync-'.substr($id, 0, 8).'@importado.invalid';
                }

                $user = $this->create($name !== '' ? $name : $email, $email, WeeklySyncContext::nullableStr($row['position'] ?? null));
                $audax[$email] = $user;
                $context->report->count('people', Report::CREATED);
                $context->report->warn("Persona sin cuenta en Audax, creada inactiva: {$user->name}.");
            } else {
                $position = WeeklySyncContext::nullableStr($row['position'] ?? null);

                if (($user->job_title === null || $user->job_title === '') && $position !== null) {
                    $user->job_title = Str::limit($position, 120, '');
                    $user->save();
                    $context->report->count('people', Report::UPDATED);
                } else {
                    $context->report->count('people', Report::UNCHANGED);
                }
            }

            $context->refs->put('user', $id, 'user', $user->id);
            $context->users[$id] = $user->id;

            foreach ($emails[$id] ?? [] as $email) {
                $context->usersByEmail[$email] ??= $user->id;
            }
        }
    }

    private function create(string $name, string $email, ?string $position): User
    {
        $user = new User;
        $user->forceFill([
            'name' => Str::limit($name, 255, ''),
            'email' => $email,
            'password' => Str::password(40),
            'job_title' => $position !== null ? Str::limit($position, 120, '') : null,
            'is_active' => false,
        ])->save();

        $user->syncRoles([Role::Employee->value]);
        $this->schedules->createDefault($user);

        return $user;
    }

    /**
     * Personas de WeeklySync que escribieron algo (aunque su cuenta esté pendiente).
     *
     * @return array<string, true>
     */
    private function authored(WeeklySyncContext $context): array
    {
        $fields = [
            'weekly_submissions' => ['user_id'],
            'weekly_submission_drafts' => ['user_id'],
            'tasks' => ['assignee_id'],
            'help_update_likes' => ['user_id'],
            'suggestion_posts' => ['author_id'],
            'suggestion_votes' => ['user_id'],
            'suggestion_comments' => ['author_id'],
            'suggestion_comment_reactions' => ['user_id'],
        ];

        $authored = [];
        foreach ($fields as $table => $columns) {
            foreach ($context->dump->rows($table) as $row) {
                foreach ($columns as $column) {
                    $id = WeeklySyncContext::id($row[$column] ?? null);
                    if ($id !== '') {
                        $authored[$id] = true;
                    }
                }
            }
        }

        return $authored;
    }
}
