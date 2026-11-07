<?php

namespace App\Security\Jwt;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clés de signature RS256 du serveur, publiées dans le JWKS.
 *
 * La clé active (OAUTH_PRIVATE_KEY) signe les jetons. Les clés listées dans OAUTH_ADDITIONAL_PUBLIC_KEYS
 * (chemins de fichiers PEM publics, séparés par des virgules) sont publiées en plus : nouvelle clé annoncée
 * avant son activation, ou ancienne clé conservée le temps que ses jetons expirent (rotation).
 *
 * Le « kid » est l'empreinte RFC 7638 (SHA-256, base64url) de la clé publique : stable, dérivé de la clé.
 */
final class SigningKeys
{
    /** @var list<array{kty: string, use: string, alg: string, kid: string, n: string, e: string}>|null */
    private ?array $keys = null;
    private ?string $activeKid = null;

    public function __construct(
        #[Autowire('%env(resolve:OAUTH_PRIVATE_KEY)%')]
        private readonly string $privateKeyPath,
        #[Autowire('%env(OAUTH_PASSPHRASE)%')]
        private readonly string $passphrase,
        #[Autowire('%env(resolve:OAUTH_ADDITIONAL_PUBLIC_KEYS)%')]
        private readonly string $additionalPublicKeys = '',
    ) {
    }

    /**
     * « kid » de la clé qui signe les jetons.
     */
    public function activeKid(): string
    {
        $this->load();

        return $this->activeKid;
    }

    /**
     * Clés publiques à publier : uniquement des composantes publiques (n, e).
     *
     * @return list<array{kty: string, use: string, alg: string, kid: string, n: string, e: string}>
     */
    public function jwks(): array
    {
        $this->load();

        return $this->keys;
    }

    private function load(): void
    {
        if (null !== $this->keys) {
            return;
        }

        $private = openssl_pkey_get_private((string) file_get_contents($this->privateKeyPath), $this->passphrase);
        if (false === $private) {
            throw new \RuntimeException('Clé privée de signature illisible (OAUTH_PRIVATE_KEY / OAUTH_PASSPHRASE).');
        }
        $active = $this->jwk($private);

        $keys = [$active];
        foreach (array_filter(array_map('trim', explode(',', $this->additionalPublicKeys))) as $path) {
            $public = openssl_pkey_get_public((string) file_get_contents($path));
            if (false === $public) {
                throw new \RuntimeException(\sprintf('Clé publique illisible : %s (OAUTH_ADDITIONAL_PUBLIC_KEYS).', $path));
            }
            $jwk = $this->jwk($public);
            if ($jwk['kid'] !== $active['kid']) {
                $keys[] = $jwk;
            }
        }

        $this->activeKid = $active['kid'];
        $this->keys = $keys;
    }

    /**
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    private function jwk(\OpenSSLAsymmetricKey $key): array
    {
        $rsa = openssl_pkey_get_details($key)['rsa'] ?? throw new \RuntimeException('Seules les clés RSA (RS256) sont prises en charge.');
        $n = self::base64Url($rsa['n']);
        $e = self::base64Url($rsa['e']);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            // RFC 7638 : membres requis, ordre lexicographique, sans espace
            'kid' => self::base64Url(hash('sha256', json_encode(['e' => $e, 'kty' => 'RSA', 'n' => $n], \JSON_UNESCAPED_SLASHES), true)),
            'n' => $n,
            'e' => $e,
        ];
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
