<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\JWT\FileSystemKeyStorage;
use MWStake\MediaWiki\Component\JWT\JwtProfileRegistry;
use MWStake\MediaWiki\Component\JWT\KeyManager;
use MWStake\MediaWiki\Component\JWT\Payload\BasicUserInfo;
use MWStake\MediaWiki\Component\JWT\TokenService;

return [
	'MWStake.JWT.KeyStorage' => static function ( MediaWikiServices $services ) {
		return new FileSystemKeyStorage(
			$GLOBALS['mwsgJWTPrivateKeyFile'],
			$GLOBALS['mwsgJWTPrivateKeyPreviousFile'] ?? null
		);
	},
	'MWStake.JWT.KeyManager' => static function ( MediaWikiServices $services ) {
		return new KeyManager(
			$services->getService( 'MWStake.JWT.KeyStorage' ),
			LoggerFactory::getInstance( 'MWStake.JWT' )
		);
	},
	'MWStake.JWT.ProfileRegistry' => static function ( MediaWikiServices $services ) {
		return new JwtProfileRegistry(
			LoggerFactory::getInstance( 'MWStake.JWT' )
		);
	},
	'MWStake.JWT.TokenService' => static function ( MediaWikiServices $services ) {
		return new TokenService(
			$services->getService( 'MWStake.JWT.KeyManager' ),
			$services->getService( 'MWStake.JWT.ProfileRegistry' ),
			(int)$GLOBALS['mwsgJWTDefaultTTL'],
			LoggerFactory::getInstance( 'MWStake.JWT' )
		);
	},
	'MWStake.JWT.Payload.BasicUserInfo' => static function ( MediaWikiServices $services ) {
		return new BasicUserInfo(
			$services->getUserGroupManager()
		);
	},
];
