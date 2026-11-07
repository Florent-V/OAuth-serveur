<?php

namespace App\Tests\Functional;

use App\Entity\Application;
use App\Security\Jwt\SigningKeys;
use App\Tests\Support\RecordingHttpClient;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Bundle\FrameworkBundle\Console\Application as Console;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Métadonnées du serveur (RFC 8414 / OpenID Connect Discovery) et JWKS.
 */
class DiscoveryTest extends FunctionalTestCase
{
    private const DOCUMENTS = ['/.well-known/oauth-authorization-server', '/.well-known/openid-configuration'];
    private const PRIVATE_COMPONENTS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'k'];

    public function testMetadataDocumentsAreIdenticalPublicAndCacheable(): void
    {
        $documents = [];
        foreach (self::DOCUMENTS as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(200);
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
            $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('public', $cacheControl);
            self::assertStringContainsString('max-age=3600', $cacheControl);
            $documents[] = $this->json();
        }
        self::assertSame($documents[0], $documents[1]);
    }

    public function testIssuerIsExactlyTheIssuerOfLogoutTokens(): void
    {
        $this->createUser(applications: [$this->createApplication()->setBackchannelLogoutUri('https://app1.mydomain.test/logout')]);
        $this->console(['command' => 'app:user:revoke', 'email' => 'alice@example.com', '--logout-only' => true]);
        $request = static::getContainer()->get(RecordingHttpClient::class)->requests[0];
        parse_str($request['body'], $form);
        $logoutClaims = $this->decode($form['logout_token'])[1];

        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        self::assertSame($logoutClaims['iss'], $this->json()['issuer']);
        self::assertSame(rtrim((string) $_SERVER['DEFAULT_URI'], '/'), $this->json()['issuer']);
    }

    public function testPublishedUrlsAreMounted(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        $metadata = $this->json();

        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $field) {
            self::assertStringStartsWith($metadata['issuer'].'/', $metadata[$field], $field);
            $this->client->request('GET', substr($metadata[$field], \strlen($metadata['issuer'])));
            self::assertNotSame(404, $this->client->getResponse()->getStatusCode(), $field);
        }
        // Routes d'après les chemins connus du serveur
        self::assertSame($metadata['issuer'].'/authorize', $metadata['authorization_endpoint']);
        self::assertSame($metadata['issuer'].'/token', $metadata['token_endpoint']);
        self::assertSame($metadata['issuer'].'/api/userinfo', $metadata['userinfo_endpoint']);
        self::assertSame($metadata['issuer'].'/.well-known/jwks.json', $metadata['jwks_uri']);
    }

    public function testNothingIsDeclaredThatTheServerDoesNotSupport(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        $metadata = $this->json();

        // Pas de révocation, d'introspection, de end_session ni d'id_token
        foreach (['revocation_endpoint', 'introspection_endpoint', 'end_session_endpoint', 'revocation_endpoint_auth_methods_supported', 'id_token_signing_alg_values_supported', 'subject_types_supported'] as $field) {
            self::assertArrayNotHasKey($field, $metadata, $field);
        }
        self::assertSame(['code'], $metadata['response_types_supported']);
        self::assertSame(['query'], $metadata['response_modes_supported']);
        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $metadata['grant_types_supported']);
        self::assertSame(['profile', 'email'], $metadata['scopes_supported']);
        self::assertSame(['client_secret_basic', 'client_secret_post', 'none'], $metadata['token_endpoint_auth_methods_supported']);
        self::assertTrue($metadata['backchannel_logout_supported']);
        self::assertFalse($metadata['backchannel_logout_session_supported']);
        self::assertFalse($metadata['authorization_response_iss_parameter_supported']);
    }

    public function testDeclaredCapabilitiesWork(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        $metadata = $this->json();
        $public = (new Application('SPA', 'spa', null))
            ->setRedirectUris(new RedirectUri(self::REDIRECT_URI))
            ->setGrants(...array_map(static fn (string $grant): Grant => new Grant($grant), $metadata['grant_types_supported']));
        $this->em()->persist($public);
        $this->em()->flush();
        $this->createUser(applications: [$this->createApplication(), $public]);
        $this->client->request('GET', '/login');
        $this->login();

        // response_type=code, response_mode=query, scopes déclarés, S256 (client public sans secret : « none »)
        $verifier = str_repeat('a1B2c3D4', 8);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->client->request('GET', '/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => 'spa', 'redirect_uri' => self::REDIRECT_URI,
            'scope' => implode(' ', $metadata['scopes_supported']), 'state' => 'xyz',
            'code_challenge' => $challenge, 'code_challenge_method' => $metadata['code_challenge_methods_supported'][0],
        ]));
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        self::assertNotEmpty($query['code']);

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code', 'client_id' => 'spa', 'redirect_uri' => self::REDIRECT_URI,
            'code' => $query['code'], 'code_verifier' => $verifier,
        ]);
        self::assertResponseIsSuccessful();
        $tokens = $this->json();
        self::assertEqualsCanonicalizing($metadata['scopes_supported'], $this->decode($tokens['access_token'])[1]['scopes']);

        $this->client->request('POST', '/token', ['grant_type' => 'refresh_token', 'client_id' => 'spa', 'refresh_token' => $tokens['refresh_token']]);
        self::assertResponseIsSuccessful();

        // « plain » n'est pas publié ; les grants non déclarés sont refusés
        foreach (['password', 'client_credentials'] as $grant) {
            self::assertNotContains($grant, $metadata['grant_types_supported']);
            $this->client->request('POST', '/token', ['grant_type' => $grant, 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET]);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('unsupported_grant_type', $this->json()['error']);
        }

        // client_secret_basic et client_secret_post
        $this->client->request('POST', '/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'x'], server: ['PHP_AUTH_USER' => self::CLIENT_ID, 'PHP_AUTH_PW' => self::CLIENT_SECRET]);
        self::assertNotSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'x', 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET]);
        self::assertNotSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'x', 'client_id' => self::CLIENT_ID, 'client_secret' => 'faux']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testForgedHostHeaderDoesNotChangeTheDocuments(): void
    {
        foreach (array_merge(self::DOCUMENTS, ['/.well-known/jwks.json']) as $url) {
            $this->client->request('GET', $url);
            $expected = (string) $this->client->getResponse()->getContent();

            $this->client->request('GET', $url, server: ['HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'evil.example']);
            self::assertSame($expected, (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('evil.example', $expected);
        }
    }

    public function testIssuerPathIsOnlyServedAtTheFormsOfTheSpecifications(): void
    {
        // L'issuer de test n'a pas de chemin : les formes avec chemin n'existent pas
        $this->client->request('GET', '/.well-known/oauth-authorization-server/oauth');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/oauth/.well-known/openid-configuration');
        self::assertResponseStatusCodeSame(404);
    }

    public function testJwksVerifiesIssuedTokensByKid(): void
    {
        $this->createUser(applications: [$this->createApplication()->setBackchannelLogoutUri('https://app1.mydomain.test/logout')]);
        $accessToken = $this->accessToken();
        $this->console(['command' => 'app:user:revoke', 'email' => 'alice@example.com', '--logout-only' => true]);
        parse_str(static::getContainer()->get(RecordingHttpClient::class)->requests[0]['body'], $form);

        $this->client->request('GET', '/.well-known/jwks.json');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
        self::assertStringContainsString('max-age=3600', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $keys = $this->json()['keys'];
        self::assertCount(1, $keys);

        foreach ([$accessToken, $form['logout_token']] as $jwt) {
            [$header] = $this->decode($jwt);
            self::assertSame('RS256', $header['alg']);
            $matching = array_values(array_filter($keys, static fn (array $key): bool => $key['kid'] === $header['kid']));
            self::assertCount(1, $matching);
            $this->decode($jwt, $matching[0]);
        }
    }

    public function testJwksNeverContainsPrivateComponents(): void
    {
        $this->client->request('GET', '/.well-known/jwks.json');
        foreach ($this->json()['keys'] as $key) {
            self::assertSame(['kty', 'use', 'alg', 'kid', 'n', 'e'], array_keys($key));
            self::assertSame([], array_intersect(self::PRIVATE_COMPONENTS, array_keys($key)));
            self::assertSame('sig', $key['use']);
        }
    }

    public function testSeveralKeysCanBePublishedAtOnce(): void
    {
        $dir = sys_get_temp_dir().'/jwks-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $other = openssl_pkey_new(['private_key_bits' => 2048]);
        file_put_contents($dir.'/other.pem', openssl_pkey_get_details($other)['key']);
        $project = static::getContainer()->getParameter('kernel.project_dir');

        $keys = new SigningKeys($project.'/var/test-keys/private.pem', 'test-passphrase', $dir.'/other.pem, '.$project.'/var/test-keys/public.pem');
        $jwks = $keys->jwks();

        // La clé active n'est pas dupliquée ; la seconde est publiée à côté, avec un kid distinct
        self::assertCount(2, $jwks);
        self::assertSame($keys->activeKid(), $jwks[0]['kid']);
        self::assertNotSame($jwks[0]['kid'], $jwks[1]['kid']);
        self::assertSame(array_keys($jwks[0]), array_keys($jwks[1]));

        // kid = empreinte RFC 7638
        $thumbprint = rtrim(strtr(base64_encode(hash('sha256', json_encode(['e' => $jwks[1]['e'], 'kty' => 'RSA', 'n' => $jwks[1]['n']]), true)), '+/', '-_'), '=');
        self::assertSame($thumbprint, $jwks[1]['kid']);

        unlink($dir.'/other.pem');
        rmdir($dir);
    }

    private function accessToken(): string
    {
        $this->client->request('GET', '/login');
        $this->login();
        $this->client->request('GET', $this->authorizeUrl());
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code', 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET,
            'redirect_uri' => self::REDIRECT_URI, 'code' => $query['code'],
        ]);
        self::assertResponseIsSuccessful();

        return $this->json()['access_token'];
    }

    /**
     * Décode un JWT ; vérifie sa signature RS256 avec la clé du JWKS si elle est fournie.
     *
     * @param array<string, string>|null $jwk
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function decode(string $jwt, ?array $jwk = null): array
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        $decode = static fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));
        if (null !== $jwk) {
            // Reconstruit la clé publique PEM à partir de n et e uniquement
            $der = static function (int $tag, string $content): string {
                $length = \strlen($content);
                $encoded = $length < 128 ? \chr($length) : (($l = ltrim(pack('N', $length), "\0")) ? \chr(0x80 | \strlen($l)).$l : '');

                return \chr($tag).$encoded.$content;
            };
            $int = static fn (string $bytes): string => $der(0x02, ("\x80" <= $bytes[0] ? "\0" : '').$bytes);
            $rsaKey = $der(0x30, $int($decode($jwk['n'])).$int($decode($jwk['e'])));
            $spki = $der(0x30, $der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00").$der(0x03, "\0".$rsaKey));
            $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64)."-----END PUBLIC KEY-----\n";
            self::assertSame(1, openssl_verify($header.'.'.$payload, $decode($signature), $pem, \OPENSSL_ALGO_SHA256));
        }

        return [json_decode($decode($header), true), json_decode($decode($payload), true)];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): ApplicationTester
    {
        $console = new Console(static::$kernel);
        $console->setAutoExit(false);
        $tester = new ApplicationTester($console);
        $tester->run($input);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
