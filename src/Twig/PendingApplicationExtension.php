<?php

namespace App\Twig;

use App\Entity\Application;
use App\Service\PendingApplicationResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * pending_application() : l'application depuis laquelle l'utilisateur est arrivé (pour la personnalisation
 * des pages qui ne la reçoivent pas du contrôleur, comme la page du code MFA).
 */
final class PendingApplicationExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly PendingApplicationResolver $resolver,
    ) {
    }

    #[AsTwigFunction('pending_application')]
    public function pendingApplication(): ?Application
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request ? $this->resolver->resolve($request) : null;
    }
}
