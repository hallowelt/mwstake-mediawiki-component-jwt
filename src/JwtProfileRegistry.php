<?php

namespace MWStake\MediaWiki\Component\JWT;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Registry of JWT payload providers.
 *
 * Extensions register named profiles with an IPayloadProvider.
 * This is a pure lookup service — minting is done via TokenService::mintForProfile().
 */
class JwtProfileRegistry {

	private LoggerInterface $logger;

	/** @var array<string, IPayloadProvider> */
	private array $profiles = [];

	/**
	 * @param LoggerInterface|null $logger
	 */
	public function __construct( ?LoggerInterface $logger = null ) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Register a JWT profile.
	 *
	 * @param string $profileName Unique identifier (e.g. 'statistics-grafana')
	 * @param IPayloadProvider $provider Builds claims for this profile
	 * @throws InvalidArgumentException if profile already registered
	 */
	public function register( string $profileName, IPayloadProvider $provider ): void {
		if ( isset( $this->profiles[$profileName] ) ) {
			throw new InvalidArgumentException(
				"JWT profile '{$profileName}' is already registered"
			);
		}
		$this->profiles[$profileName] = $provider;
	}

	/**
	 * Get a registered profile's payload provider.
	 *
	 * @param string $profileName
	 * @return IPayloadProvider
	 * @throws InvalidArgumentException if profile is not registered
	 */
	public function get( string $profileName ): IPayloadProvider {
		if ( !isset( $this->profiles[$profileName] ) ) {
			throw new InvalidArgumentException(
				"JWT profile '{$profileName}' is not registered"
			);
		}
		return $this->profiles[$profileName];
	}

	/**
	 * Check whether a profile is registered.
	 *
	 * @param string $profileName
	 * @return bool
	 */
	public function hasProfile( string $profileName ): bool {
		return isset( $this->profiles[$profileName] );
	}

	/**
	 * Get all registered profile names.
	 *
	 * @return string[]
	 */
	public function getProfileNames(): array {
		return array_keys( $this->profiles );
	}
}
