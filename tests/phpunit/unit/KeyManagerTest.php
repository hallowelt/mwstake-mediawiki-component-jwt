<?php

namespace MWStake\MediaWiki\Component\JWT\Tests\Unit;

use MWStake\MediaWiki\Component\JWT\IKeyStorage;
use MWStake\MediaWiki\Component\JWT\KeyManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \MWStake\MediaWiki\Component\JWT\KeyManager
 */
class KeyManagerTest extends TestCase {

	/**
	 * @param array $files Map of filename => content
	 * @return IKeyStorage&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function createMockStorage( array $files = [] ): IKeyStorage {
		$storage = $this->createMock( IKeyStorage::class );

		$storage->method( 'read' )->willReturnCallback(
			static function ( string $filename ) use ( $files ) {
				return $files[$filename] ?? null;
			}
		);

		$storage->method( 'exists' )->willReturnCallback(
			static function ( string $filename ) use ( $files ) {
				return isset( $files[$filename] );
			}
		);

		return $storage;
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\KeyManager::isConfigured
	 */
	public function testIsConfiguredReturnsFalseWhenNoKey(): void {
		$storage = $this->createMockStorage();
		$manager = new KeyManager( $storage );
		$this->assertFalse( $manager->isConfigured() );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\KeyManager::isConfigured
	 */
	public function testIsConfiguredReturnsTrueWhenKeyExists(): void {
		$storage = $this->createMockStorage( [
			'jwt-key-current.pem' => 'key-content',
		] );
		$manager = new KeyManager( $storage );
		$this->assertTrue( $manager->isConfigured() );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\KeyManager::getCurrentPrivateKey
	 */
	public function testGetCurrentPrivateKeyReturnsContent(): void {
		$pem = "-----BEGIN PRIVATE KEY-----\nfakekey\n-----END PRIVATE KEY-----";
		$storage = $this->createMockStorage( [
			'jwt-key-current.pem' => $pem,
		] );
		$manager = new KeyManager( $storage );
		$this->assertEquals( $pem, $manager->getCurrentPrivateKey() );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\KeyManager::getCurrentPrivateKey
	 */
	public function testGetCurrentPrivateKeyThrowsWhenNoFile(): void {
		$storage = $this->createMockStorage();
		$manager = new KeyManager( $storage );
		$this->expectException( RuntimeException::class );
		$manager->getCurrentPrivateKey();
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\KeyManager::getPreviousPrivateKey
	 */
	public function testGetPreviousPrivateKeyReturnsNullWhenMissing(): void {
		$storage = $this->createMockStorage();
		$manager = new KeyManager( $storage );
		$this->assertNull( $manager->getPreviousPrivateKey() );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\KeyManager::getPreviousPrivateKey
	 */
	public function testGetPreviousPrivateKeyReturnsContent(): void {
		$pem = "-----BEGIN PRIVATE KEY-----\nprevkey\n-----END PRIVATE KEY-----";
		$storage = $this->createMockStorage( [
			'jwt-key-previous.pem' => $pem,
		] );
		$manager = new KeyManager( $storage );
		$this->assertEquals( $pem, $manager->getPreviousPrivateKey() );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\KeyManager::getAllKeys
	 */
	public function testGetAllKeysReturnsBothKeys(): void {
		$current = "-----BEGIN PRIVATE KEY-----\ncurrent\n-----END PRIVATE KEY-----";
		$previous = "-----BEGIN PRIVATE KEY-----\nprevious\n-----END PRIVATE KEY-----";
		$storage = $this->createMockStorage( [
			'jwt-key-current.pem' => $current,
			'jwt-key-previous.pem' => $previous,
		] );
		$manager = new KeyManager( $storage );
		$keys = $manager->getAllKeys();
		$this->assertCount( 2, $keys );
		$this->assertEquals( $current, $keys[0] );
		$this->assertEquals( $previous, $keys[1] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\JWT\KeyManager::getAllKeys
	 */
	public function testGetAllKeysReturnsOnlyCurrentWhenNoPrevious(): void {
		$storage = $this->createMockStorage( [
			'jwt-key-current.pem' => 'current-key',
		] );
		$manager = new KeyManager( $storage );
		$keys = $manager->getAllKeys();
		$this->assertCount( 1, $keys );
	}
}
