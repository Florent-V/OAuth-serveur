<?php

namespace App\Security\BackchannelLogout;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Crée les « logout tokens » de l'OpenID Connect Back-Channel Logout 1.0 :
 * JWT signés RS256 avec la clé privée du serveur (celle des access tokens).
 *
 * @see https://openid.net/specs/openid-connect-backchannel-1_0.html#LogoutToken
 */
final class LogoutTokenFactory
{
    public const EVENT = 'http://schemas.openid.net/event/backchannel-logout';
    private const LIFETIME = '+2 minutes';

    private ?Configuration $configuration = null;

    public function __construct(
        #[Autowire('%env(resolve:OAUTH_PRIVATE_KEY)%')]
        private readonly string $privateKeyPath,
        #[Autowire('%env(OAUTH_PASSPHRASE)%')]
        private readonly string $passphrase,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $issuer,
    ) {
    }

    /**
     * @param string $subject identifiant de l'utilisateur, identique au « sub » des access tokens (son e-mail)
     * @param int    $uid     identifiant stable de l'utilisateur (« uid » des access tokens, « id » de /api/userinfo)
     */
    public function create(string $clientId, string $subject, int $uid): string
    {
        $configuration = $this->configuration();
        $now = new \DateTimeImmutable('@'.time());

        // Dates en secondes entières (NumericDate), comprises par toutes les bibliothèques JWT
        return $configuration->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('typ', 'logout+jwt')
            ->issuedBy($this->issuer())
            ->permittedFor($clientId)
            ->relatedTo($subject)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->expiresAt($now->modify(self::LIFETIME))
            ->withClaim('uid', $uid)
            // Objet JSON vide, exigé par la spécification
            ->withClaim('events', [self::EVENT => new \stdClass()])
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    /**
     * Identifiant du serveur (« iss ») : son URL publique, DEFAULT_URI.
     */
    public function issuer(): string
    {
        return rtrim($this->issuer, '/');
    }

    private function configuration(): Configuration
    {
        // Clé chargée à la première utilisation seulement
        return $this->configuration ??= Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::file($this->privateKeyPath, $this->passphrase),
            InMemory::plainText('unused'),
        );
    }
}
