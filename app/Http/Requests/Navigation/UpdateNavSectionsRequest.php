<?php

namespace App\Http\Requests\Navigation;

use App\Domain\Navigation\NavSections;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guardar las secciones plegadas de la barra lateral (D-260): una lista (quizá vacía) de ids de la
 * lista blanca, sin repetir. Es la preferencia propia de quien la guarda: no hace falta más
 * autorización.
 */
class UpdateNavSectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'collapsed' => ['present', 'array', 'list', 'max:'.count(NavSections::SECTIONS)],
            'collapsed.*' => ['required', 'string', 'distinct:strict', Rule::in(NavSections::SECTIONS)],
        ];
    }

    /**
     * @return list<string>
     */
    public function collapsed(): array
    {
        /** @var array<array-key, mixed> $collapsed */
        $collapsed = $this->validated('collapsed', []);

        return NavSections::normalize($collapsed);
    }
}
