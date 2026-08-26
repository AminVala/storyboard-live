<?php
/**
 * DEVELOPMENT BUILD AUTOLOADER.
 *
 * This tiny PSR-4 loader exists only because Composer CLI was not available in
 * the artifact build environment. Before a production release, replace the
 * vendor directory with Composer-generated files using:
 *
 * composer dump-autoload --no-dev -o
 */

spl_autoload_register(
	static function ( $class ) {
		$prefix   = 'ShahreHonar\\SequenceEngine\\';
		$base_dir = dirname( __DIR__ ) . '/src/';
		$length   = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $length ) ) {
			return;
		}

		$relative_class = substr( $class, $length );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

return true;
