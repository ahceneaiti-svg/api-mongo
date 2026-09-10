<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Normalise toute exception levee sous /api ou /health en reponse JSON.
 */
#[AsEventListener(event: 'kernel.exception')]
final class ApiExceptionListener
{
    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api') && !str_starts_with($path, '/health')) {
            return;
        }

        $throwable = $event->getThrowable();
        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;

        $payload = [
            'error' => [
                'status' => $status,
                'message' => $status < 500 || $this->kernel->isDebug()
                    ? ($throwable->getMessage() ?: 'Erreur')
                    : 'Erreur interne du serveur.',
            ],
        ];

        if ($this->kernel->isDebug() && $status >= 500) {
            $payload['error']['exception'] = $throwable::class;
            $payload['error']['file'] = $throwable->getFile().':'.$throwable->getLine();
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }
}
