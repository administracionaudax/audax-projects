<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\EmploymentProfile;
use App\Models\PeopleDocumentRead;
use App\Models\User;

/**
 * Mis datos laborales del registro (Fase 11; D-357): alta, baja, si estoy sujeto al registro,
 * tiempo parcial, la retención por litigio y los documentos de RR. HH. que he leído.
 */
final class EmploymentSection extends Section
{
    public function key(): string
    {
        return 'datos-laborales';
    }

    protected function textKey(): string
    {
        return 'employment';
    }

    protected function columnKeys(): array
    {
        return ['field', 'value', 'date'];
    }

    public function rows(User $user): iterable
    {
        $profile = EmploymentProfile::query()->where('user_id', $user->id)->first();

        if ($profile !== null) {
            yield ['field' => 'hire_date', 'value' => self::date($profile->hire_date), 'date' => null];
            yield ['field' => 'termination_date', 'value' => self::date($profile->termination_date), 'date' => null];
            yield ['field' => 'subject_to_register', 'value' => $profile->subject_to_register ? 'yes' : 'no', 'date' => null];
            yield ['field' => 'register_exemption_reason', 'value' => $profile->register_exemption_reason, 'date' => null];
            yield ['field' => 'part_time', 'value' => $profile->part_time ? 'yes' : 'no', 'date' => null];
            yield ['field' => 'legal_hold', 'value' => $profile->legal_hold ? 'yes' : 'no', 'date' => self::instant($profile->legal_hold_since)];
        }

        foreach (PeopleDocumentRead::query()->with('document:id,key,version')->where('user_id', $user->id)->orderBy('read_at')->get() as $read) {
            yield ['field' => 'read:'.$read->document->key.':v'.$read->document->version, 'value' => 'read', 'date' => self::instant($read->read_at)];
        }
    }
}
