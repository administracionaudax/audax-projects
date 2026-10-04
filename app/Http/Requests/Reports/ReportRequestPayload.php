<?php

namespace App\Http\Requests\Reports;

use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un ReportRequest en el cuerpo JSON (D-139): `kind`, `route_params` (los parámetros de la ruta
 * del informe) y `query` (los filtros de su URL). Qué informe se puede ver lo decide después
 * ReportFileGenerator con los permisos de quien lo pide.
 */
class ReportRequestPayload extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::enum(ReportKind::class)],
            'route_params' => ['nullable', 'array', 'max:4'],
            'route_params.*' => ['required', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'query' => ['nullable', 'array', 'max:40'],
        ];
    }

    public function reportRequest(): ReportRequest
    {
        /** @var array<string, int|string> $params */
        $params = array_map(
            fn (mixed $value): int|string => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : (string) $value,
            (array) $this->input('route_params', []),
        );

        /** @var array<string, mixed> $query */
        $query = (array) $this->input('query', []);

        return new ReportRequest(ReportKind::from((string) $this->string('kind')), $params, $query);
    }
}
