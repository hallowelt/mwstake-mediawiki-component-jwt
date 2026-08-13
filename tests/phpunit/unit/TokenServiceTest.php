<?php

namespace MWStake\MediaWiki\Component\JWT\Tests\Unit;

use MWStake\MediaWiki\Component\JWT\JwtProfileRegistry;
use MWStake\MediaWiki\Component\JWT\KeyManager;
use MWStake\MediaWiki\Component\JWT\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MWStake\MediaWiki\Component\JWT\TokenService
 */
class TokenServiceTest extends TestCase {

	private KeyManager $keyManager;
	private JwtProfileRegistry $profileRegistry;
	private string $privateKeyPem = '';
	private string $previousKeyPem = '';

	protected function setUp(): void {
		parent::setUp();

		// Generate real RSA keys for testing
		$res = openssl_pkey_new( [
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		] );
		$pem = '';
		openssl_pkey_export( $res, $pem );
		$this->privateKeyPem = $pem;

		$res2 = openssl_pkey_new( [
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		] );
		$pem2 = '';
		openssl_pkey_export( $res2, $pem2 );
		$this->previousKeyPem = $pem2;

		$this->keyManager = $this->createMock( KeyManager::class );
		$this->keyManager->method( 'getCurrentPrivateKey' )
			->willReturn( $this->privateKeyPem );
		$this->keyManager->method( 'getAllKeys' )
			->willReturn( [ $this->privateKeyPem ] );

		$this->profileRegistry = new JwtProfileRegistry();
	}

	private function createService( ?KeyManager $keyManager = null, int $ttl = 3600 ): TokenService {
		return new TokenService(
			$keyManager ?? $this->keyManager,
			$this->profileRegistry,
			$ttl
		);
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mint
	 */
	public function testMintReturnsValidJwtString(): void {
		$service = $this->createService();

		$token = $service->mint( [
			'sub' => 'TestUser',
			'email' => 'test@example.com',
		] );

		// JWT has 3 base64url-encoded parts separated by dots
		$parts = explode( '.', $token );
		$this->assertCount( 3, $parts );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mint
	 */
	public function testMintIncludesCustomClaims(): void {
		$service = $this->createService();

		$token = $service->mint( [
			'sub' => 'TestUser',
			'email' => 'test@example.com',
			'groups' => [ 'sysop', 'editor' ],
		] );

		$payload = $this->decodePayload( $token );

		$this->assertEquals( 'TestUser', $payload['sub'] );
		$this->assertEquals( 'test@example.com', $payload['email'] );
		$this->assertEquals( [ 'sysop', 'editor' ], $payload['groups'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mint
	 */
	public function testMintSetsExpiration(): void {
		$service = $this->createService( null, 60 );

		$token = $service->mint( [ 'sub' => 'TestUser' ] );
		$payload = $this->decodePayload( $token );

		$this->assertArrayHasKey( 'exp', $payload );
		$this->assertArrayHasKey( 'iat', $payload );
		$this->assertEquals( 60, $payload['exp'] - $payload['iat'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mint
	 */
	public function testMintRespectsCustomTtl(): void {
		$service = $this->createService();

		$token = $service->mint( [ 'sub' => 'TestUser' ], 120 );
		$payload = $this->decodePayload( $token );

		$this->assertEquals( 120, $payload['exp'] - $payload['iat'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mint
	 */
	public function testMintIncludesKidInHeader(): void {
		$service = $this->createService();

		$token = $service->mint( [ 'sub' => 'TestUser' ] );
		$header = $this->decodeHeader( $token );

		$this->assertArrayHasKey( 'kid', $header );
		$this->assertNotEmpty( $header['kid'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mint
	 */
	public function testMintHandlesRegisteredClaims(): void {
		$service = $this->createService();

		// Should not throw RegisteredClaimGiven exception
		$token = $service->mint( [
			'sub' => 'TestUser',
			'iss' => 'https://wiki.example.com',
			'aud' => 'grafana',
			'jti' => 'unique-id-123',
		] );

		$payload = $this->decodePayload( $token );

		$this->assertEquals( 'TestUser', $payload['sub'] );
		$this->assertEquals( 'https://wiki.example.com', $payload['iss'] );
		$this->assertEquals( 'grafana', $payload['aud'] );
		$this->assertEquals( 'unique-id-123', $payload['jti'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::getJWKS
	 */
	public function testGetJWKSReturnsValidStructure(): void {
		$service = $this->createService();

		$jwks = $service->getJWKS();

		$this->assertArrayHasKey( 'keys', $jwks );
		$this->assertCount( 1, $jwks['keys'] );

		$key = $jwks['keys'][0];
		$this->assertEquals( 'RSA', $key['kty'] );
		$this->assertEquals( 'sig', $key['use'] );
		$this->assertEquals( 'RS256', $key['alg'] );
		$this->assertNotEmpty( $key['kid'] );
		$this->assertNotEmpty( $key['n'] );
		$this->assertNotEmpty( $key['e'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::getJWKS
	 */
	public function testGetJWKSIncludesBothKeysAfterRotation(): void {
		$keyManager = $this->createMock( KeyManager::class );
		$keyManager->method( 'getCurrentPrivateKey' )->willReturn( $this->privateKeyPem );
		$keyManager->method( 'getAllKeys' )
			->willReturn( [ $this->privateKeyPem, $this->previousKeyPem ] );

		$service = $this->createService( $keyManager );
		$jwks = $service->getJWKS();

		$this->assertCount( 2, $jwks['keys'] );
		$this->assertNotEquals( $jwks['keys'][0]['kid'], $jwks['keys'][1]['kid'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mint
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::getJWKS
	 */
	public function testKidInTokenMatchesJWKS(): void {
		$service = $this->createService();

		$token = $service->mint( [ 'sub' => 'TestUser' ] );
		$header = $this->decodeHeader( $token );

		$jwks = $service->getJWKS();
		$jwksKids = array_column( $jwks['keys'], 'kid' );

		$this->assertContains( $header['kid'], $jwksKids );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mintForProfile
	 */
	public function testMintForProfileUsesProviderClaims(): void {
		$provider = $this->createMock( \MWStake\MediaWiki\Component\JWT\IPayloadProvider::class );
		$provider->method( 'getClaims' )->willReturn( [
			'sub' => 'ProfileUser',
			'role' => 'admin',
		] );
		$provider->method( 'getTtl' )->willReturn( 45 );

		$this->profileRegistry->register( 'test-profile', $provider );
		$service = $this->createService();

		$user = $this->createMock( \MediaWiki\User\User::class );
		$token = $service->mintForProfile( 'test-profile', $user );
		$payload = $this->decodePayload( $token );

		$this->assertEquals( 'ProfileUser', $payload['sub'] );
		$this->assertEquals( 'admin', $payload['role'] );
		$this->assertEquals( 45, $payload['exp'] - $payload['iat'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mintForProfile
	 */
	public function testMintForProfileUsesDefaultTtlWhenProviderReturnsNull(): void {
		$provider = $this->createMock( \MWStake\MediaWiki\Component\JWT\IPayloadProvider::class );
		$provider->method( 'getClaims' )->willReturn( [ 'sub' => 'User' ] );
		$provider->method( 'getTtl' )->willReturn( null );

		$this->profileRegistry->register( 'null-ttl', $provider );
		$service = $this->createService( null, 900 );

		$user = $this->createMock( \MediaWiki\User\User::class );
		$token = $service->mintForProfile( 'null-ttl', $user );
		$payload = $this->decodePayload( $token );

		$this->assertEquals( 900, $payload['exp'] - $payload['iat'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\TokenService::mintForProfile
	 */
	public function testMintForProfileThrowsOnUnknownProfile(): void {
		$service = $this->createService();
		$this->expectException( \InvalidArgumentException::class );
		$user = $this->createMock( \MediaWiki\User\User::class );
		$service->mintForProfile( 'unknown', $user );
	}

	/**
	 * Decode JWT payload (without verification — for testing only).
	 */
	private function decodePayload( string $token ): array {
		$parts = explode( '.', $token );
		$payload = base64_decode( strtr( $parts[1], '-_', '+/' ) );
		return json_decode( $payload, true );
	}

	/**
	 * Decode JWT header (without verification — for testing only).
	 */
	private function decodeHeader( string $token ): array {
		$parts = explode( '.', $token );
		$header = base64_decode( strtr( $parts[0], '-_', '+/' ) );
		return json_decode( $header, true );
	}
}
