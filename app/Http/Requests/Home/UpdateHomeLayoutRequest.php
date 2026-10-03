<?php

namespace App\Http\Requests\Home;

use App\Domain\Home\HomeLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guardar el orden de las tarjetas de Inicio (D-138): una lista de ids de la lista blanca
 * (HomeLayout::CARDS), sin repetir y como mucho tantas como tarjetas hay. Es el orden propio de
 * quien lo guarda: no hace falta más autorización.
 */
class UpdateHomeLayoutRequest extends FormRequest
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
            'cards' => ['required', 'array', 'list', 'min:1', 'max:'.count(HomeLayout::CARDS)],
            'cards.*' => ['required', 'string', 'distinct:strict', Rule::in(HomeLayout::CARDS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cards.*.in' => __('home.errors.unknown_card'),
            'cards.*.distinct' => __('home.errors.duplicated_card'),
            'cards.max' => __('home.errors.too_many_cards', ['max' => count(HomeLayout::CARDS)]),
        ];
    }

    /**
     * @return list<string>
     */
    public function cards(): array
    {
        /** @var list<string> $cards */
        $cards = $this->validated('cards');

        return $cards;
    }
}
