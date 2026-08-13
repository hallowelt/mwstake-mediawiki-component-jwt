<?php

namespace MWStake\MediaWiki\Component\JWT\Payload;

use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\WikiMap\WikiMap;
use MWStake\MediaWiki\Component\JWT\IPayloadProvider;

/**
 * Builds a standard user-info JWT payload with username, email and group memberships.
 */
class BasicUserInfo implements IPayloadProvider {

	private UserGroupManager $userGroupManager;
	private ?int $ttl;

	/**
	 * @param UserGroupManager $userGroupManager
	 * @param int|null $ttl Custom TTL in seconds, or null for global default
	 */
	public function __construct( UserGroupManager $userGroupManager, ?int $ttl = null ) {
		$this->userGroupManager = $userGroupManager;
		$this->ttl = $ttl;
	}

	/**
	 * Build claims array for the given user.
	 *
	 * @param User $user
	 * @return array Claims suitable for TokenService::mint()
	 */
	public function getClaims( User $user ): array {
		$email = $user->getEmail();
		if ( $email === '' ) {
			$email = $user->getName() . '@wiki.local';
		}

		$groups = $this->userGroupManager->getUserGroups( $user );

		return [
			'sub' => $user->getName(),
			'email' => $email,
			'groups' => $groups,
			'wiki_id' => WikiMap::getCurrentWikiId(),
		];
	}

	/** @inheritDoc */
	public function getTtl(): ?int {
		return $this->ttl;
	}
}
