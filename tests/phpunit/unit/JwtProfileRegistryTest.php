<?php

namespace MWStake\MediaWiki\Component\JWT\Tests\Unit;

use InvalidArgumentException;
use MWStake\MediaWiki\Component\JWT\IPayloadProvider;
use MWStake\MediaWiki\Component\JWT\JwtProfileRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MWStake\MediaWiki\Component\JWT\JwtProfileRegistry
 */
class JwtProfileRegistryTest extends TestCase {

	private function createMockProvider(): IPayloadProvider {
		return $this->createMock( IPayloadProvider::class );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\JwtProfileRegistry::register
	 * @covers \MWStake\MediaWiki\Component\JWT\JwtProfileRegistry::get
	 */
	public function testRegisterAndGet(): void {
		$registry = new JwtProfileRegistry();
		$provider = $this->createMockProvider();

		$registry->register( 'my-app', $provider );
		$this->assertSame( $provider, $registry->get( 'my-app' ) );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\JwtProfileRegistry::get
	 */
	public function testGetUnknownProfileThrows(): void {
		$registry = new JwtProfileRegistry();
		$this->expectException( InvalidArgumentException::class );
		$registry->get( 'nonexistent' );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\JwtProfileRegistry::register
	 */
	public function testDuplicateRegistrationThrows(): void {
		$registry = new JwtProfileRegistry();
		$provider = $this->createMockProvider();

		$registry->register( 'dup', $provider );
		$this->expectException( InvalidArgumentException::class );
		$registry->register( 'dup', $provider );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\JwtProfileRegistry::hasProfile
	 */
	public function testHasProfile(): void {
		$registry = new JwtProfileRegistry();
		$this->assertFalse( $registry->hasProfile( 'foo' ) );

		$registry->register( 'foo', $this->createMockProvider() );
		$this->assertTrue( $registry->hasProfile( 'foo' ) );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\JwtProfileRegistry::getProfileNames
	 */
	public function testGetProfileNames(): void {
		$registry = new JwtProfileRegistry();
		$registry->register( 'alpha', $this->createMockProvider() );
		$registry->register( 'beta', $this->createMockProvider() );

		$names = $registry->getProfileNames();
		$this->assertCount( 2, $names );
		$this->assertContains( 'alpha', $names );
		$this->assertContains( 'beta', $names );
	}
}
