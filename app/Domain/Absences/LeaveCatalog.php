<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceType;
use App\Enums\LeaveUnit;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Collection;

/**
 * Catálogo de tipos de ausencia (Fase 11, R3; PLAN-FASE-11 §7.6 y §8.3; W-057 a W-059; D-360 y
 * D-361). Sustituye al enum fijo de la Fase 3 sin romperlo: cada tipo tiene una **categoría**, que
 * es uno de los cinco valores de siempre (`App\Enums\AbsenceType`) y se sigue guardando en
 * `absences.type`; la capacidad, la carga, la Weekly, el calendario y la privacidad de D-088 siguen
 * leyendo esa categoría. Los cinco tipos de siempre conservan su clave (`vacation`, `sick`…).
 *
 * DEFAULTS es lo que se precarga (migración `create_people_r3_tables`): los permisos del art. 37
 * del Estatuto en la redacción del RDL 5/2023 (y del RDL 8/2024 en la catástrofe), el art. 37.9
 * (fuerza mayor), el art. 38 (vacaciones), el art. 48 bis (permiso parental) y el 48.4 (nacimiento,
 * RDL 9/2025). Lo que solo da un convenio llega **inactivo y «pendiente de asesor»**: el convenio
 * aplicable está por confirmar (WOFFU-INVESTIGACION F-1). Las cantidades, en las unidades del tipo
 * (centésimas de día o minutos, LeaveUnit). RR. HH. lo edita en /ausencias/tipos.
 */
final class LeaveCatalog
{
    /** Cada «día» de los tipos en días, en centésimas. */
    public const int DAY = 100;

    /**
     * @var list<array<string, mixed>>
     */
    public const array DEFAULTS = [
        [
            'key' => 'vacation',
            'name' => 'Vacaciones',
            'category' => 'vacation',
            'unit' => 'working_days',
            'paid' => true,
            'annual_allowance' => 2200,
            'carry_over_until' => '03-31',
            'allow_without_balance' => false,
            'respects_blocked_days' => true,
            'legal_basis' => 'Art. 38 ET (mínimo de 30 días naturales; el calendario se conoce 2 meses antes) y art. 23 del convenio de publicidad (22 días laborables).',
            'description' => 'Se descuentan los días laborables de tu jornada: los festivos y los días que no trabajas no cuentan, y un día de media jornada cuenta medio. Lo que no disfrutes se puede usar hasta el 31 de marzo del año siguiente.',
            'advisor_pending' => true,
            'advisor_note' => 'Convenio aplicable por confirmar (publicidad o consultoría): 22 días laborables y el arrastre hasta el 31/03 son una decisión de la empresa a favor de la plantilla.',
        ],
        [
            'key' => 'sick',
            'name' => 'Baja por incapacidad temporal',
            'category' => 'sick',
            'unit' => 'calendar_days',
            'paid' => false,
            'health_data' => true,
            'legal_basis' => 'Art. 45.1.c ET. Desde el RD 1060/2022 la persona no entrega el parte a la empresa: lo recibe de la Seguridad Social.',
            'description' => 'La baja la comunica la Seguridad Social a la empresa; no tienes que subir el parte. Es un dato de salud: solo lo ven tú, tu responsable y RR. HH.',
        ],
        [
            'key' => 'marriage',
            'name' => 'Matrimonio o registro de pareja de hecho',
            'category' => 'leave',
            'unit' => 'calendar_days',
            'default_amount' => 1500,
            'paid' => true,
            'requires_document' => true,
            'legal_basis' => 'Art. 37.3.a ET (redacción del RDL 5/2023): 15 días naturales.',
            'description' => 'Quince días naturales. Adjunta el certificado de matrimonio o de inscripción de la pareja de hecho.',
        ],
        [
            'key' => 'family_illness',
            'name' => 'Accidente, enfermedad grave u hospitalización de un familiar',
            'category' => 'leave',
            'unit' => 'working_days',
            'default_amount' => 500,
            'paid' => true,
            'requires_document' => true,
            'health_data' => true,
            'legal_basis' => 'Art. 37.3.b ET (redacción del RDL 5/2023): 5 días por accidente o enfermedad graves, hospitalización o intervención quirúrgica sin hospitalización que precise reposo domiciliario del cónyuge, pareja de hecho, parientes hasta el segundo grado o conviviente.',
            'description' => 'Cinco días. Adjunta un justificante de la hospitalización o del reposo (sin diagnóstico): solo lo ven tú y RR. HH.',
            'advisor_pending' => true,
            'advisor_note' => 'El ET dice «cinco días» sin precisar: se cuentan como laborables (lo más favorable) hasta que la asesoría diga otra cosa.',
        ],
        [
            'key' => 'bereavement',
            'name' => 'Fallecimiento de un familiar',
            'category' => 'leave',
            'unit' => 'working_days',
            'default_amount' => 200,
            'travel_extra' => 200,
            'paid' => true,
            'requires_document' => true,
            'legal_basis' => 'Art. 37.3.b bis ET (redacción del RDL 5/2023): 2 días por el fallecimiento del cónyuge, pareja de hecho o parientes hasta el segundo grado, ampliables en 2 si hay que desplazarse.',
            'description' => 'Dos días, o cuatro si tienes que desplazarte. Adjunta el certificado o la esquela.',
            'advisor_pending' => true,
            'advisor_note' => 'El convenio de publicidad (art. 24) da más días; si es el aplicable, prevalece lo más favorable.',
        ],
        [
            'key' => 'moving',
            'name' => 'Traslado del domicilio habitual',
            'category' => 'leave',
            'unit' => 'working_days',
            'default_amount' => 100,
            'paid' => true,
            'requires_document' => true,
            'legal_basis' => 'Art. 37.3.c ET: 1 día por traslado del domicilio habitual.',
            'description' => 'Un día. Adjunta el certificado de empadronamiento o el contrato de la vivienda nueva.',
            'advisor_pending' => true,
            'advisor_note' => 'El convenio de publicidad (art. 24) da 2 días; si es el aplicable, prevalece.',
        ],
        [
            'key' => 'public_duty',
            'name' => 'Deber inexcusable de carácter público y personal',
            'category' => 'leave',
            'unit' => 'hours',
            'paid' => true,
            'requires_document' => true,
            'legal_basis' => 'Art. 37.3.d ET: el tiempo indispensable (citación judicial, mesa electoral, voto…).',
            'description' => 'El tiempo indispensable, con su franja. Adjunta la citación o el justificante.',
        ],
        [
            'key' => 'prenatal',
            'name' => 'Exámenes prenatales y preparación al parto',
            'category' => 'leave',
            'unit' => 'hours',
            'paid' => true,
            'requires_document' => true,
            'health_data' => true,
            'legal_basis' => 'Art. 37.3.f ET: el tiempo indispensable para exámenes prenatales y técnicas de preparación al parto (y las sesiones de adopción o acogimiento), dentro de la jornada.',
            'description' => 'El tiempo indispensable, con su franja. Adjunta el justificante de la cita.',
        ],
        [
            'key' => 'force_majeure',
            'name' => 'Fuerza mayor familiar (por horas)',
            'category' => 'leave',
            'unit' => 'hours',
            'paid' => true,
            'requires_document' => true,
            'health_data' => true,
            'annual_allowance' => 400,
            'allowance_in_days' => true,
            'allow_without_balance' => true,
            'legal_basis' => 'Art. 37.9 ET (RDL 5/2023): ausencia por motivos familiares urgentes de enfermedad o accidente; se pagan las horas equivalentes a 4 días al año, aportando, en su caso, la acreditación del motivo.',
            'description' => 'Para una urgencia familiar por enfermedad o accidente. Se pagan las horas de 4 días de tu jornada al año; lo que pase de ahí también se puede pedir, sin retribuir.',
        ],
        [
            'key' => 'catastrophe',
            'name' => 'Imposibilidad de acudir por catástrofe o aviso de la autoridad',
            'category' => 'leave',
            'unit' => 'working_days',
            'default_amount' => 400,
            'paid' => true,
            'legal_basis' => 'Art. 37.3.g ET (RDL 8/2024): hasta 4 días por la imposibilidad de acceder al trabajo por recomendaciones, limitaciones o prohibiciones de la autoridad ante una catástrofe o un fenómeno meteorológico adverso.',
            'description' => 'Hasta cuatro días si una alerta o una prohibición de la autoridad te impide ir a trabajar (y no puedes teletrabajar).',
        ],
        [
            'key' => 'breastfeeding',
            'name' => 'Lactancia',
            'category' => 'leave',
            'unit' => 'hours',
            'paid' => true,
            'requires_document' => true,
            'legal_basis' => 'Art. 37.4 ET: una hora diaria (o media hora al principio o al final de la jornada) hasta los 9 meses del menor; se puede acumular en jornadas completas si lo prevé el convenio o un acuerdo.',
            'description' => 'Una hora al día, con su franja, o las jornadas completas acumuladas que acuerdes con la empresa. Adjunta el libro de familia la primera vez.',
            'advisor_pending' => true,
            'advisor_note' => 'La acumulación en días (el convenio de publicidad habla de 15 días laborables) depende del convenio aplicable.',
        ],
        [
            'key' => 'parental',
            'name' => 'Permiso parental',
            'category' => 'leave',
            'unit' => 'calendar_days',
            'default_amount' => 5600,
            'paid' => false,
            'notice_days' => 10,
            'legal_basis' => 'Art. 48 bis ET: hasta 8 semanas, continuas o discontinuas, hasta que el menor cumpla 8 años; se avisa con 10 días de antelación.',
            'description' => 'Hasta 8 semanas por hijo o hija, sin retribuir por la empresa. Avisa con 10 días de antelación.',
        ],
        [
            'key' => 'birth',
            'name' => 'Nacimiento y cuidado del menor',
            'category' => 'leave',
            'unit' => 'calendar_days',
            'default_amount' => 13300,
            'paid' => false,
            'notice_days' => 15,
            'requires_document' => true,
            'legal_basis' => 'Art. 48.4 ET (RDL 9/2025): 19 semanas por progenitor (6 obligatorias tras el parto), que paga la Seguridad Social; se avisa con 15 días de antelación de cada periodo.',
            'description' => 'Diecinueve semanas, que paga la Seguridad Social. Avisa con 15 días de antelación de cada periodo que no sea el obligatorio.',
        ],
        [
            'key' => 'exams',
            'name' => 'Exámenes',
            'category' => 'leave',
            'unit' => 'hours',
            'paid' => false,
            'requires_document' => true,
            'legal_basis' => 'Art. 23.1.a ET: el permiso necesario para concurrir a exámenes.',
            'description' => 'El tiempo necesario para el examen, con su franja. Adjunta el justificante de asistencia.',
            'advisor_pending' => true,
            'advisor_note' => 'El ET no dice que se pague; el convenio de publicidad (art. 24) sí lo retribuye.',
        ],
        [
            'key' => 'leave',
            'name' => 'Otro permiso',
            'category' => 'leave',
            'unit' => 'working_days',
            'paid' => true,
            'description' => 'Un permiso que no está en la lista. Explica el motivo en las notas.',
        ],
        [
            'key' => 'training',
            'name' => 'Formación externa',
            'category' => 'training',
            'unit' => 'working_days',
            'paid' => true,
            'legal_basis' => 'Art. 23 ET (formación profesional para el empleo).',
        ],
        [
            'key' => 'unpaid_leave',
            'name' => 'Permiso no retribuido',
            'category' => 'other',
            'unit' => 'working_days',
            'paid' => false,
            'description' => 'Días sin sueldo que acuerdas con la empresa.',
        ],
        [
            'key' => 'other',
            'name' => 'Otro',
            'category' => 'other',
            'unit' => 'working_days',
            'paid' => true,
        ],
        [
            'key' => 'medical_accompaniment',
            'name' => 'Acompañamiento médico urgente',
            'category' => 'leave',
            'unit' => 'hours',
            'paid' => true,
            'requires_document' => true,
            'health_data' => true,
            'annual_allowance' => 960,
            'active' => false,
            'legal_basis' => 'Art. 24 del convenio de publicidad: hasta 16 horas al año.',
            'description' => 'Para acompañar a un familiar a una urgencia médica, hasta 16 horas al año.',
            'advisor_pending' => true,
            'advisor_note' => 'Solo si el convenio de publicidad es el aplicable. Se activa cuando lo confirme la asesoría.',
        ],
        [
            'key' => 'family_wedding',
            'name' => 'Boda de un familiar',
            'category' => 'leave',
            'unit' => 'working_days',
            'default_amount' => 100,
            'paid' => true,
            'requires_document' => true,
            'active' => false,
            'legal_basis' => 'Art. 24 del convenio de publicidad: 1 día.',
            'advisor_pending' => true,
            'advisor_note' => 'Solo si el convenio de publicidad es el aplicable. Se activa cuando lo confirme la asesoría.',
        ],
        [
            'key' => 'own_affairs',
            'name' => 'Asuntos propios',
            'category' => 'leave',
            'unit' => 'working_days',
            'paid' => true,
            'allow_without_balance' => false,
            'active' => false,
            'legal_basis' => 'Solo si los da el convenio o un acuerdo de empresa (el ET no los prevé).',
            'advisor_pending' => true,
            'advisor_note' => 'Ningún convenio confirmado los da. Si la empresa los concede, pon aquí los días al año y actívalo.',
        ],
    ];

    /**
     * Fila completa con los valores por defecto de un tipo (para la migración y los tests).
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function row(array $definition, int $sort): array
    {
        return [
            'key' => $definition['key'],
            'name' => $definition['name'],
            'category' => $definition['category'],
            'unit' => $definition['unit'],
            'description' => $definition['description'] ?? null,
            'legal_basis' => $definition['legal_basis'] ?? null,
            'default_amount' => $definition['default_amount'] ?? null,
            'travel_extra' => $definition['travel_extra'] ?? null,
            'paid' => $definition['paid'] ?? true,
            'requires_document' => $definition['requires_document'] ?? false,
            'notice_days' => $definition['notice_days'] ?? null,
            'health_data' => $definition['health_data'] ?? ($definition['category'] === 'sick'),
            'annual_allowance' => $definition['annual_allowance'] ?? null,
            'allowance_in_days' => $definition['allowance_in_days'] ?? false,
            'carry_over_until' => $definition['carry_over_until'] ?? null,
            'allow_without_balance' => $definition['allow_without_balance'] ?? true,
            'second_approval' => false,
            'respects_blocked_days' => $definition['respects_blocked_days'] ?? false,
            'advisor_pending' => $definition['advisor_pending'] ?? false,
            'advisor_note' => $definition['advisor_note'] ?? null,
            'active' => $definition['active'] ?? true,
            'sort' => $sort,
        ];
    }

    /**
     * El tipo del catálogo que corresponde a una categoría de la Fase 3 (los cinco de siempre tienen
     * su misma clave): para las solicitudes que llegan con `type` y para rellenar las antiguas.
     */
    public static function legacy(AbsenceType $category): LeaveType
    {
        /** @var LeaveType */
        return LeaveType::query()->where('key', $category->value)->firstOrFail();
    }

    /** Un tipo en días laborables sin guardar: para saber si un día tiene jornada (AbsenceCost). */
    public static function workingProbe(): LeaveType
    {
        $type = new LeaveType;
        $type->unit = LeaveUnit::WorkingDays;

        return $type;
    }

    /**
     * Los tipos que se pueden pedir, en su orden.
     *
     * @return Collection<int, LeaveType>
     */
    public static function active(): Collection
    {
        return LeaveType::query()->where('active', true)->orderBy('sort')->orderBy('id')->get();
    }
}
