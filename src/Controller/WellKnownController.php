<?php

namespace App\Controller;

use App\Security\Discovery\ServerMetadata;
use App\Security\Jwt\Issuer;
use App\Security\Jwt\SigningKeys;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Documents publics de découverte : métadonnées du serveur (RFC 8414, OpenID Connect Discovery) et JWKS.
 *
 * Si l'issuer contient un chemin (https://auth.example.com/oauth) :
 *  - RFC 8414 insère « /.well-known/… » entre l'hôte et le chemin : /.well-known/oauth-authorization-server/oauth
 *  - OIDC Discovery l'ajoute après le chemin : /oauth/.well-known/openid-configuration
 * Les deux formes sont servies ; les autres combinaisons répondent 404.
 */
final class WellKnownController extends AbstractController
{
    public function __construct(private readonly Issuer $issuer)
    {
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'well_known_oauth_authorization_server', methods: ['GET'], stateless: true)]
    #[Route('/.well-known/oauth-authorization-server/{path}', name: 'well_known_oauth_authorization_server_path', requirements: ['path' => '.+'], methods: ['GET'], stateless: true)]
    public function oauthAuthorizationServer(ServerMetadata $metadata, string $path = ''): Response
    {
        return $this->document($metadata, $path);
    }

    #[Route('/.well-known/openid-configuration', name: 'well_known_openid_configuration', methods: ['GET'], stateless: true)]
    #[Route('/{path}/.well-known/openid-configuration', name: 'well_known_openid_configuration_path', requirements: ['path' => '.+'], methods: ['GET'], stateless: true)]
    public function openidConfiguration(ServerMetadata $metadata, string $path = ''): Response
    {
        return $this->document($metadata, $path);
    }

    #[Route('/.well-known/jwks.json', name: 'well_known_jwks', methods: ['GET'], stateless: true)]
    public function jwks(SigningKeys $keys): Response
    {
        return $this->publicJson(['keys' => $keys->jwks()]);
    }

    private function document(ServerMetadata $metadata, string $path): Response
    {
        if ('' !== $path ? '/'.$path !== $this->issuer->path() : '' !== $this->issuer->path()) {
            throw $this->createNotFoundException();
        }

        return $this->publicJson($metadata->build());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function publicJson(array $data): JsonResponse
    {
        $response = new JsonResponse();
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES);
        $response->setData($data);
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
