<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ApplicationRepository;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints appelés par les applications avec un jeton d'accès (Authorization: Bearer ...).
 */
#[Route('/api')]
class ApiController extends AbstractController
{
    /**
     * Informations sur l'utilisateur propriétaire du jeton (format inspiré d'OpenID Connect).
     */
    #[Route('/userinfo', name: 'api_userinfo', methods: ['GET'])]
    public function userinfo(Security $security, ApplicationRepository $applications): JsonResponse
    {
        $token = $security->getToken();
        $user = $this->getUser();

        if (!$token instanceof OAuth2Token || !$user instanceof User) {
            return new JsonResponse(['error' => 'invalid_token'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        // Vérifie à chaque appel que l'accès n'a pas été retiré entre-temps par un administrateur.
        $application = $applications->find($token->getOAuthClientId());
        if (null === $application || !$application->isActive() || !$user->hasAccessTo($application)) {
            return new JsonResponse(['error' => 'access_denied', 'error_description' => 'L\'utilisateur n\'a pas accès à cette application.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $scopes = $token->getScopes();
        // "sub" = identifiant de l'utilisateur dans le JWT (son e-mail) ; "id" = identifiant stable
        // (ne change pas si l'e-mail est modifié) : à utiliser comme clé côté application.
        $data = ['sub' => $user->getUserIdentifier(), 'id' => $user->getId()];

        if (\in_array('profile', $scopes, true)) {
            $data['preferred_username'] = $user->getUsername();
            // Doublon de preferred_username, conservé pour les applications qui lisent « name ».
            $data['name'] = $user->getUsername();
        }
        if (\in_array('email', $scopes, true)) {
            $data['email'] = $user->getEmail();
        }

        return new JsonResponse($data);
    }
}
