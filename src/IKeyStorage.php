<?php

namespace MWStake\MediaWiki\Component\JWT;

/**
 * Interface for reading key files.
 *
 * Abstracts the actual storage so that KeyManager is testable.
 * Write operations are intentionally excluded — key lifecycle
 * is managed by the external orchestrator.
 */
interface IKeyStorage {

	/**
	 * Read the content of a key file.
	 *
	 * @param string $filename
	 * @return string|null Content or null if file doesn't exist
	 */
	public function read( string $filename ): ?string;

	/**
	 * Check whether a file exists.
	 *
	 * @param string $filename
	 * @return bool
	 */
	public function exists( string $filename ): bool;
}
