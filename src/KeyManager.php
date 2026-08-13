<?php

namespace MWStake\MediaWiki\Component\JWT;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Manages RSA key files for JWT signing.
 *
 * Keys are read from an IKeyStorage implementation. Key generation and rotation
 * are **not** handled by this component — they are the responsibility of the
 * external orchestrator (Kubernetes, Ansible, etc.).
 *
 * Expected key files:
 * - jwt-key-current.pem — the active signing key (required)
 * - jwt-key-previous.pem — the previous key, kept for JWKS validation window (optional)
 */
class KeyManager {

	private const CURRENT_KEY_FILE = 'jwt-key-current.pem';
	private const PREVIOUS_KEY_FILE = 'jwt-key-previous.pem';

	private IKeyStorage $storage;
	private LoggerInterface $logger;

	/**
	 * @param IKeyStorage $storage Key storage backend
	 * @param LoggerInterface|null $logger
	 */
	public function __construct( IKeyStorage $storage, ?LoggerInterface $logger = null ) {
		$this->storage = $storage;
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Get the current (active) private key for signing.
	 *
	 * @return string PEM-encoded RSA private key
	 * @throws RuntimeException if key file is not found
	 */
	public function getCurrentPrivateKey(): string {
		return $this->readKeyFile( self::CURRENT_KEY_FILE );
	}

	/**
	 * Get the previous private key (if it exists).
	 *
	 * Unlike getCurrentPrivateKey(), this returns null rather than throwing
	 * because the previous key is optional (only exists during rotation overlap).
	 *
	 * @return string|null PEM-encoded RSA private key, or null if no previous key
	 */
	public function getPreviousPrivateKey(): ?string {
		return $this->storage->read( self::PREVIOUS_KEY_FILE );
	}

	/**
	 * Get all available private keys (current + previous if exists).
	 *
	 * @return string[] Array of PEM-encoded private keys
	 */
	public function getAllKeys(): array {
		$keys = [ $this->getCurrentPrivateKey() ];
		$previous = $this->getPreviousPrivateKey();
		if ( $previous !== null ) {
			$keys[] = $previous;
		}
		return $keys;
	}

	/**
	 * Check whether a current key exists in storage.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return $this->storage->exists( self::CURRENT_KEY_FILE );
	}

	/**
	 * @param string $filename
	 * @return string File content
	 * @throws RuntimeException
	 */
	private function readKeyFile( string $filename ): string {
		$content = $this->storage->read( $filename );
		if ( $content === null ) {
			throw new RuntimeException( "Key file not found: {$filename}" );
		}
		return $content;
	}
}
