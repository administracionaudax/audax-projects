<?php

namespace App\Http\Requests\Reports;

use App\Domain\Reports\Delivery\DeliveryRecipients;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\ReportAccess;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Delivery\ReportVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Lo común de enviar un informe y de programar su envío (D-141):
 * - el informe (ReportRequest: tipo, parámetros de ruta y filtros) y su título para las listas,
 * - formato: PDF, Excel o los dos,
 * - destinatarios: personas activas de la plantilla (ids) y correos externos, como mucho
 *   DeliveryRecipients::MAX en total y al menos uno,
 * - asunto y mensaje opcionales,
 * - la versión del informe en su query (?version=interno|cliente, D-240), en los que la tienen.
 * Que quien lo envía pueda ver el informe (403 si no) lo comprueba el controlador con
 * ReportAccess, ya con los datos validados.
 */
abstract class ReportDeliveryRequest extends FormRequest
{
    /** Formatos que se pueden enviar (CSV no, D-141). */
    public const array FORMATS = [ExportFormat::Pdf->value, ExportFormat::Xlsx->value];

    public const int MAX_SUBJECT = 150;

    public const int MAX_MESSAGE = 2000;

    /** Tamaño máximo de los filtros del informe, en JSON. */
    public const int MAX_QUERY_BYTES = 4000;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'recipient_user_ids' => is_array($this->input('recipient_user_ids')) ? DeliveryRecipients::normalizeIds($this->input('recipient_user_ids')) : $this->input('recipient_user_ids', []),
            'recipient_emails' => is_array($this->input('recipient_emails')) ? DeliveryRecipients::normalizeEmails($this->input('recipient_emails')) : $this->input('recipient_emails', []),
            'subject' => is_string($this->input('subject')) && trim($this->input('subject')) !== '' ? trim($this->input('subject')) : null,
            'message' => is_string($this->input('message')) && trim($this->input('message')) !== '' ? trim($this->input('message')) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'request' => ['required', 'array:kind,route_params,query'],
            'request.kind' => ['required', 'string', Rule::enum(ReportKind::class)],
            'request.route_params' => ['nullable', 'array'],
            'request.route_params.*' => ['integer', 'min:1'],
            'request.query' => ['nullable', 'array'],
            'title' => ['required', 'string', 'max:200'],
            'formats' => ['required', 'array', 'min:1', 'max:'.count(self::FORMATS)],
            'formats.*' => ['required', 'string', 'distinct', Rule::in(self::FORMATS)],
            'recipient_user_ids' => ['present', 'array', 'max:'.DeliveryRecipients::MAX],
            'recipient_user_ids.*' => ['integer'],
            'recipient_emails' => ['present', 'array', 'max:'.DeliveryRecipients::MAX],
            'recipient_emails.*' => ['string', 'max:254', 'email:rfc,strict'],
            'subject' => ['nullable', 'string', 'max:'.self::MAX_SUBJECT],
            'message' => ['nullable', 'string', 'max:'.self::MAX_MESSAGE],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $errors = $validator->errors();
            $kind = ReportKind::tryFrom((string) $this->input('request.kind'));

            if ($kind !== null && ! $errors->has('request.*')) {
                $params = (array) $this->input('request.route_params', []);

                foreach (ReportAccess::routeParams($kind) as $param) {
                    if (! isset($params[$param])) {
                        $errors->add('request', $this->text('report_deliveries.errors.request'));
                        break;
                    }
                }

                if (strlen((string) json_encode($this->input('request.query', []))) > self::MAX_QUERY_BYTES) {
                    $errors->add('request', $this->text('report_deliveries.errors.request'));
                }

                // La versión del informe (D-240): interno o cliente, y solo en los que la tienen.
                $version = $this->input('request.query.'.ReportVersion::QUERY_KEY);
                if ($version !== null && (! ReportVersion::supports($kind) || ! in_array($version, ReportVersion::values(), true))) {
                    $errors->add('request', $this->text('report_deliveries.errors.request'));
                }
            }

            if ($errors->hasAny(['recipient_user_ids', 'recipient_user_ids.*', 'recipient_emails', 'recipient_emails.*'])) {
                return;
            }

            /** @var list<int> $ids */
            $ids = $this->input('recipient_user_ids', []);
            /** @var list<string> $emails */
            $emails = $this->input('recipient_emails', []);
            $total = count($ids) + count($emails);

            if ($total === 0) {
                $errors->add('recipients', $this->text('report_deliveries.errors.no_recipients'));
            } elseif ($total > DeliveryRecipients::MAX) {
                $errors->add('recipients', $this->text('report_deliveries.errors.too_many_recipients', ['max' => DeliveryRecipients::MAX]));
            }

            if ($ids !== [] && DeliveryRecipients::eligibleUsers()->whereKey($ids)->count() !== count($ids)) {
                $errors->add('recipient_user_ids', $this->text('report_deliveries.errors.recipient_inactive'));
            }
        }];
    }

    /**
     * El informe validado. Los parámetros de ruta, como enteros (son ids).
     */
    public function reportRequest(): ReportRequest
    {
        /** @var array<string, int|string> $params */
        $params = array_map('intval', (array) $this->validated('request.route_params', []));
        /** @var array<string, mixed> $query */
        $query = (array) $this->validated('request.query', []);

        return new ReportRequest(ReportKind::from((string) $this->validated('request.kind')), $params, $query);
    }

    /**
     * @return list<int>
     */
    public function recipientUserIds(): array
    {
        /** @var list<int> */
        return array_values(array_map('intval', (array) $this->validated('recipient_user_ids', [])));
    }

    /**
     * @return list<string>
     */
    public function recipientEmails(): array
    {
        /** @var list<string> */
        return array_values((array) $this->validated('recipient_emails', []));
    }

    /**
     * @return list<string>
     */
    public function formats(): array
    {
        $formats = (array) $this->validated('formats');

        // En el orden fijo de FORMATS (PDF y después Excel).
        return array_values(array_intersect(self::FORMATS, $formats));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'formats.required' => $this->text('report_deliveries.errors.formats'),
            'formats.min' => $this->text('report_deliveries.errors.formats'),
            'formats.*.in' => $this->text('report_deliveries.errors.formats'),
            'recipient_emails.*.email' => $this->text('report_deliveries.errors.email'),
            'recipient_emails.max' => $this->text('report_deliveries.errors.too_many_recipients', ['max' => DeliveryRecipients::MAX]),
            'recipient_user_ids.max' => $this->text('report_deliveries.errors.too_many_recipients', ['max' => DeliveryRecipients::MAX]),
            'request.required' => $this->text('report_deliveries.errors.request'),
            'request.array' => $this->text('report_deliveries.errors.request'),
            'request.kind.required' => $this->text('report_deliveries.errors.request'),
            'request.kind.enum' => $this->text('report_deliveries.errors.request'),
            'request.route_params.*.integer' => $this->text('report_deliveries.errors.request'),
            'request.route_params.*.min' => $this->text('report_deliveries.errors.request'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => $this->text('report_deliveries.attributes.title'),
            'subject' => $this->text('report_deliveries.attributes.subject'),
            'message' => $this->text('report_deliveries.attributes.message'),
            'recipient_emails.*' => $this->text('report_deliveries.attributes.email'),
        ];
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    protected function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
