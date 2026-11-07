<?php

namespace App\Security\Jwt;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Identifiant du serveur (« iss » des logout tokens, « issuer » des métadonnées) : son URL publique,
 * DEFAULT_URI sans « / » final. Jamais déduit de la requête (en-tête Host forgeable).
 */
final readonly class Issuer implements \Stringable
{
    public function __construct(
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $uri,
    ) {
    }

    public function value(): string
    {
        return rtrim($this->uri, '/');
    }

    /**
     * Chemin de l'issuer (« /oauth » pour https://auth.example.com/oauth), vide s'il n'y en a pas.
     */
    public function path(): string
    {
        return rtrim((string) parse_url($this->value(), \PHP_URL_PATH), '/');
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
