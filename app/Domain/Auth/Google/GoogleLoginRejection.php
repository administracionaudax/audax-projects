<?php

namespace App\Domain\Auth\Google;

/**
 * Por qué no se deja entrar con Google (D-165). El valor es la clave del mensaje para la persona
 * (lang/es/auth.php: google.errors.*) y de la etiqueta de la auditoría
 * (lang/es/audit.php: values.google_login_reasons.*).
 */
enum GoogleLoginRejection: string
{
    /** El `state` de la vuelta no es el de la sesión o ha caducado (CSRF del flujo OAuth). */
    case InvalidState = 'invalid_state';

    /** La persona canceló en la pantalla de Google. */
    case Denied = 'denied';

    /** Google no cambió el código por los tokens. */
    case ExchangeFailed = 'exchange_failed';

    /** El id_token no es de este cliente, ha caducado o su `nonce` no coincide. */
    case InvalidToken = 'invalid_token';

    /** Google no da el correo por verificado. */
    case Unverified = 'unverified';

    /** Cuenta que no es de Google Workspace de un dominio permitido. */
    case WrongDomain = 'wrong_domain';

    /** No hay ninguna persona en la app con ese correo (nunca se crea). */
    case NotFound = 'not_found';

    case Inactive = 'inactive';

    /** Los clientes entran al portal con su contraseña. */
    case Client = 'client';

    /** Los colaboradores externos (D-134) entran con su contraseña. */
    case Collaborator = 'collaborator';

    public function message(string $email = ''): string
    {
        return self::text("auth.google.errors.{$this->value}", [
            'email' => $email,
            'domain' => implode(', @', GoogleLogin::allowedDomains()),
        ]);
    }

    public function label(): string
    {
        return self::text("audit.values.google_login_reasons.{$this->value}");
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
