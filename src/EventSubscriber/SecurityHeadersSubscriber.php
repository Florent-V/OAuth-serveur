<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * En-têtes de sécurité HTTP. Interdit notamment l'affichage des pages dans une iframe
 * (anti-clickjacking / hameçonnage de la page de connexion).
 * HSTS est à configurer sur le reverse proxy HTTPS.
 */
#[AsEventListener]
final class SecurityHeadersSubscriber
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // CSP stricte sur nos pages ; l'admin EasyAdmin (scripts inline) garde seulement frame-ancestors.
        $path = $event->getRequest()->getPathInfo();
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', str_starts_with($path, '/admin') || str_starts_with($path, '/_')
                ? "frame-ancestors 'none'"
                : "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
        }
    }
}
