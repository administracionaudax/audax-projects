<?php

namespace App\Broadcasting;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * El servicio de push no responde (red, DNS, tiempo agotado): PSR-18 exige esta excepción para
 * que la librería lo cuente como envío fallido sin cortar los demás.
 */
final class PushTransportException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $request, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
