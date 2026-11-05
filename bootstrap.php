<?php

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_JWT_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_JWT_VERSION', '2.0.1' );

MWStake\MediaWiki\ComponentLoader\Bootstrapper::getInstance()
	->register( 'jwt', static function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/ServiceWiring.php';

		// Absolute path to the current PEM-encoded RSA private key file (required)
		$GLOBALS['mwsgJWTPrivateKeyFile'] = $GLOBALS['mwsgJWTPrivateKeyFile'] ?? null;

		// Absolute path to the previous PEM private key file (optional, for rotation overlap)
		$GLOBALS['mwsgJWTPrivateKeyPreviousFile'] = $GLOBALS['mwsgJWTPrivateKeyPreviousFile'] ?? null;

		// Default token TTL in seconds
		$GLOBALS['mwsgJWTDefaultTTL'] = $GLOBALS['mwsgJWTDefaultTTL'] ?? 3600;

		$GLOBALS['wgExtensionFunctions'][] = static function () {
			// Register JWKS at /.well-known/jwks.json
			$registry = \MediaWiki\MediaWikiServices::getInstance()
				->getService( 'MWStake.WellKnown.FileRegistry' );

			$registry->register( 'jwks.json', static function () {
				$tokenService = \MediaWiki\MediaWikiServices::getInstance()
					->getService( 'MWStake.JWT.TokenService' );
				return json_encode( $tokenService->getJWKS() );
			}, 'application/json' );
		};
	} );
