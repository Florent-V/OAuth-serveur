<?php

namespace App\Security\Discovery;

use App\Security\Jwt\Issuer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;

/**
 * Métadonnées du serveur (RFC 8414 / OpenID Connect Discovery), générées depuis la configuration
 * et les routes réellement montées. Seules des capacités réellement supportées sont déclarées.
 *
 * Absents volontairement : revocation_endpoint, introspection_endpoint (non implémentés),
 * end_session_endpoint (/logout n'est pas l'« RP-Initiated Logout » OIDC), id_token_signing_alg_values_supported
 * et subject_types_supported (le serveur n'émet pas d'id_token).
 */
final readonly class ServerMetadata
{
    /**
     * @param list<string> $grantTypes
     * @param list<string> $scopes
     */
    public function __construct(
        private Issuer $issuer,
        private RouterInterface $router,
        #[Autowire('%app.oauth.grant_types%')]
        private array $grantTypes,
        #[Autowire('%app.oauth.scopes%')]
        private array $scopes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $metadata = [
            'issuer' => $this->issuer->value(),
            'authorization_endpoint' => $this->endpoint('oauth2_authorize'),
            'token_endpoint' => $this->endpoint('oauth2_token'),
            'userinfo_endpoint' => $this->endpoint('api_userinfo'),
            'jwks_uri' => $this->endpoint('well_known_jwks'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => $this->grantTypes,
            // « plain » est refusé à la publication (RFC 9700), même si la bibliothèque l'accepte encore
            'code_challenge_methods_supported' => ['S256'],
            // « none » : clients publics (PKCE obligatoire)
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'scopes_supported' => $this->scopes,
            'backchannel_logout_supported' => true,
            // Les logout tokens ne contiennent pas de « sid »
            'backchannel_logout_session_supported' => false,
            // Le paramètre « iss » n'est pas renvoyé dans la redirection d'autorisation (RFC 9207)
            'authorization_response_iss_parameter_supported' => false,
        ];

        return array_filter($metadata, static fn (mixed $value): bool => null !== $value);
    }

    /**
     * URL absolue d'une route, ou null si elle n'est pas montée. Construite sur l'issuer configuré,
     * jamais sur l'hôte de la requête.
     */
    private function endpoint(string $route): ?string
    {
        $route = $this->router->getRouteCollection()->get($route);

        return null === $route ? null : $this->issuer->value().$route->getPath();
    }
}
