<?php

namespace MWStake\MediaWiki\Component\JWT;

/**
 * IKeyStorage implementation that reads PEM keys from local filesystem paths.
 *
 * Key lifecycle (generation, rotation) is managed externally by the orchestrator
 * (e.g. Kubernetes Secrets, Ansible). This class is read-only.
 */
class FileSystemKeyStorage implements IKeyStorage {

	private ?string $currentKeyFile;
	private ?string $previousKeyFile;

	/**
	 * @param string|null $currentKeyFile Absolute path to the current private key PEM file
	 * @param string|null $previousKeyFile Absolute path to the previous private key PEM file (optional)
	 */
	public function __construct( ?string $currentKeyFile, ?string $previousKeyFile = null ) {
		$this->currentKeyFile = $currentKeyFile;
		$this->previousKeyFile = $previousKeyFile;
	}

	/** @inheritDoc */
	public function read( string $filename ): ?string {
		$path = $this->resolveFilename( $filename );
		if ( $path === null || !is_file( $path ) || !is_readable( $path ) ) {
			return null;
		}
		$content = file_get_contents( $path );
		return $content !== false ? $content : null;
	}

	/** @inheritDoc */
	public function exists( string $filename ): bool {
		$path = $this->resolveFilename( $filename );
		return $path !== null && is_file( $path );
	}

	/**
	 * Map the canonical key filenames used by KeyManager to the configured file paths.
	 *
	 * @param string $filename
	 * @return string|null
	 */
	private function resolveFilename( string $filename ): ?string {
		if ( $filename === 'jwt-key-current.pem' ) {
			return $this->currentKeyFile;
		}
		if ( $filename === 'jwt-key-previous.pem' ) {
			return $this->previousKeyFile;
		}
		return null;
	}
}
