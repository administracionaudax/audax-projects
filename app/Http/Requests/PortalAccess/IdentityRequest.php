<?php

namespace App\Http\Requests\PortalAccess;

use App\Domain\Identity\CompanyIdentity;
use App\Http\Requests\Admin\Concerns\NormalizesInput;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Identidad de la empresa (/admin/identidad, D-067), solo admin: nombre y, opcionalmente, un logo
 * nuevo en PNG, JPG o WebP de hasta 1 MB. La extensión se filtra aquí; el tipo REAL (fileinfo sobre
 * el contenido), las dimensiones y que GD lo pueda leer los comprueba CompanyIdentity: un SVG, o
 * cualquier otra cosa con extensión .png, se rechaza igual.
 */
class IdentityRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['company_name']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'logo' => ['nullable', 'file', 'max:'.CompanyIdentity::MAX_KILOBYTES, 'extensions:png,jpg,jpeg,webp'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.extensions' => __('portal.identity.errors.logo_type'),
            'logo.file' => __('portal.identity.errors.logo_type'),
            'logo.max' => __('portal.identity.errors.logo_size'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_name' => __('portal.identity.attributes.company_name'),
            'logo' => __('portal.identity.attributes.logo'),
        ];
    }

    public function companyName(): string
    {
        return $this->string('company_name')->toString();
    }

    public function logo(): ?UploadedFile
    {
        $file = $this->file('logo');

        return $file instanceof UploadedFile ? $file : null;
    }
}
