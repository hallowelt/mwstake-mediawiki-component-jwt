<?php

namespace MWStake\MediaWiki\Component\JWT;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Service for minting RS256 JWT tokens and generating JWKS.
 */
class TokenService {

	private KeyManager $keyManager;
	private JwtProfileRegistry $profileRegistry;
	private int $defaultTtl;
	private LoggerInterface $logger;

	/**
	 * @param KeyManager $keyManager
	 * @param JwtProfileRegistry $profileRegistry
	 * @param int $defaultTtl Default token lifetime in seconds
	 * @param LoggerInterface|null $logger
	 */
	public function __construct(
		KeyManager $keyManager,
		JwtProfileRegistry $profileRegistry,
		int $defaultTtl,
		?LoggerInterface $logger = null
	) {
		$this->keyManager = $keyManager;
		$this->profileRegistry = $profileRegistry;
		$this->defaultTtl = $defaultTtl;
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Mint a JWT with a custom payload.
	 *
	 * Registered claims (sub, iss, aud, jti, nbf) are handled via dedicated
	 * builder methods as required by lcobucci/jwt.
	 *
	 * @param array $claims Associative array of claims to include in the payload
	 * @param int|null $ttl Token lifetime in seconds (null = use default)
	 * @return string Encoded JWT string
	 */
	public function mint( array $claims, ?int $ttl = null ): string {
		$ttl = $ttl ?? $this->defaultTtl;
		$privateKeyPem = $this->keyManager->getCurrentPrivateKey();
		$kid = $this->computeKid( $privateKeyPem );

		$config = Configuration::forAsymmetricSigner(
			new Sha256(),
			InMemory::plainText( $privateKeyPem ),
			InMemory::plainText( '' )
		);

		$now = new DateTimeImmutable();

		$builder = $config->builder()
			->issuedAt( $now )
			->expiresAt( $now->modify( "+{$ttl} seconds" ) )
			->withHeader( 'kid', $kid );

		// Registered claims must use dedicated builder methods
		$registeredClaimMap = [
			'sub' => 'relatedTo',
			'iss' => 'issuedBy',
			'aud' => 'permittedFor',
			'jti' => 'identifiedBy',
			'nbf' => 'canOnlyBeUsedAfter',
		];

		foreach ( $claims as $key => $value ) {
			if ( isset( $registeredClaimMap[$key] ) ) {
				$method = $registeredClaimMap[$key];
				$builder = $builder->$method( $value );
			} else {
				$builder = $builder->withClaim( $key, $value );
			}
		}

		/** @var Plain $token */
		$token = $builder->getToken( $config->signer(), $config->signingKey() );

		$this->logger->debug( 'JWT minted', [
			'sub' => $claims['sub'] ?? '(none)',
			'ttl' => $ttl,
			'kid' => $kid,
		] );

		return $token->toString();
	}

	/**
	 * Mint a JWT using a registered profile.
	 *
	 * Looks up the profile by name, builds claims from its IPayloadProvider,
	 * and mints a token with the profile's TTL (or global default if null).
	 *
	 * @param string $profileName Registered profile name
	 * @param User $user User object passed to the payload provider
	 * @return string Encoded JWT string
	 */
	public function mintForProfile( string $profileName, User $user ): string {
		$provider = $this->profileRegistry->get( $profileName );
		$claims = $provider->getClaims( $user );
		$ttl = $provider->getTtl();

		$this->logger->debug( 'Minting JWT for profile', [
			'profile' => $profileName,
			'sub' => $claims['sub'] ?? '(none)',
			'ttl' => $ttl ?? 'default',
		] );

		return $this->mint( $claims, $ttl );
	}

	/**
	 * Get the JWKS (JSON Web Key Set) containing public keys for all active keys.
	 *
	 * @return array JWKS structure
	 */
	public function getJWKS(): array {
		$jwksKeys = [];

		foreach ( $this->keyManager->getAllKeys() as $privateKeyPem ) {
			$jwkEntry = $this->buildJwkEntry( $privateKeyPem );
			if ( $jwkEntry !== null ) {
				$jwksKeys[] = $jwkEntry;
			}
		}

		$this->logger->debug( 'JWKS generated', [
			'keyCount' => count( $jwksKeys ),
		] );

		return [ 'keys' => $jwksKeys ];
	}

	/**
	 * Build a JWK entry from an RSA private key.
	 *
	 * @param string $privateKeyPem
	 * @return array|null Null if the key cannot be parsed (corrupt/invalid)
	 * @throws RuntimeException if the key is not a valid RSA key
	 */
	private function buildJwkEntry( string $privateKeyPem ): ?array {
		$keyResource = openssl_pkey_get_private( $privateKeyPem );
		if ( $keyResource === false ) {
			$this->logger->error( 'Failed to parse RSA private key for JWKS entry' );
			throw new RuntimeException( 'Failed to parse RSA private key for JWKS entry' );
		}

		$details = openssl_pkey_get_details( $keyResource );
		if ( $details === false || ( $details['type'] ?? -1 ) !== OPENSSL_KEYTYPE_RSA ) {
			$this->logger->error( 'Key is not a valid RSA key' );
			throw new RuntimeException( 'Key is not a valid RSA key' );
		}

		$n = $this->base64urlEncode( $details['rsa']['n'] );
		$e = $this->base64urlEncode( $details['rsa']['e'] );
		$kid = $this->computeKid( $privateKeyPem );

		return [
			'kty' => 'RSA',
			'use' => 'sig',
			'alg' => 'RS256',
			'kid' => $kid,
			'n' => $n,
			'e' => $e,
		];
	}

	/**
	 * Compute a key ID (kid) as the RFC 7638 JWK Thumbprint (SHA-256, base64url).
	 *
	 * @param string $privateKeyPem
	 * @return string
	 * @throws RuntimeException
	 */
	private function computeKid( string $privateKeyPem ): string {
		$keyResource = openssl_pkey_get_private( $privateKeyPem );
		if ( $keyResource === false ) {
			throw new RuntimeException( 'Failed to parse RSA private key for kid computation' );
		}

		$details = openssl_pkey_get_details( $keyResource );
		if ( $details === false || ( $details['type'] ?? -1 ) !== OPENSSL_KEYTYPE_RSA ) {
			throw new RuntimeException( 'Key is not a valid RSA key' );
		}

		// RFC 7638: thumbprint input is the JSON of the required members in lexicographic order
		$thumbprintInput = json_encode( [
			'e' => $this->base64urlEncode( $details['rsa']['e'] ),
			'kty' => 'RSA',
			'n' => $this->base64urlEncode( $details['rsa']['n'] ),
		], JSON_UNESCAPED_SLASHES );

		return $this->base64urlEncode( hash( 'sha256', $thumbprintInput, true ) );
	}

	/**
	 * @param string $data Raw binary data
	 * @return string base64url-encoded string (no padding)
	 */
	private function base64urlEncode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
