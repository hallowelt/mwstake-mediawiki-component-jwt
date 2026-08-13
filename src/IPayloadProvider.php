<?php

namespace MWStake\MediaWiki\Component\JWT;

use MediaWiki\User\User;

/**
 * Interface for building JWT claims for a specific profile/consumer.
 *
 * Each extension that needs JWTs implements this to define what claims
 * are included in its tokens.
 */
interface IPayloadProvider {

	/**
	 * Build the claims array for the given user.
	 *
	 * The returned array may include registered claims (sub, iss, aud, etc.)
	 * as well as any custom claims.
	 *
	 * @param User $user
	 * @return array Associative array of claims
	 */
	public function getClaims( User $user ): array;

	/**
	 * Get the token TTL for this profile (in seconds).
	 * Return null to use the global default ($mwsgJWTDefaultTTL).
	 *
	 * @return int|null
	 */
	public function getTtl(): ?int;
}
