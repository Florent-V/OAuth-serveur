<?php

namespace App\Service;

use App\Entity\Application;
use App\Repository\ApplicationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Retrouve l'application depuis laquelle l'utilisateur arrive.
 *
 * Quand une application redirige vers /authorize alors que l'utilisateur n'est pas connecté,
 * Symfony mémorise l'URL /authorize?client_id=... en session (target path) avant d'afficher
 * la page de connexion. On en extrait le client_id pour personnaliser les pages
 * de connexion / inscription.
 */
final class PendingApplicationResolver
{
    use TargetPathTrait;

    public const FIREWALL = 'main';

    public function __construct(private readonly ApplicationRepository $applications)
    {
    }

    public function resolve(Request $request): ?Application
    {
        $clientId = $request->query->getString('client_id');

        if ('' === $clientId && $request->hasSession()) {
            $targetPath = $this->getTargetPath($request->getSession(), self::FIREWALL);
            if (null !== $targetPath) {
                parse_str((string) parse_url($targetPath, \PHP_URL_QUERY), $query);
                $clientId = \is_string($query['client_id'] ?? null) ? $query['client_id'] : '';
            }
        }

        if ('' === $clientId) {
            return null;
        }

        $application = $this->applications->find($clientId);

        return $application?->isActive() ? $application : null;
    }

    public function getTargetPathFromSession(Request $request): ?string
    {
        return $request->hasSession() ? $this->getTargetPath($request->getSession(), self::FIREWALL) : null;
    }
}
