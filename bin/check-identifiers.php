<?php
/**
 * Fails when a plugin identifier is written as a string literal outside the constant that defines it.
 *
 * Identifiers are strings containing "mwtcl_" or "mwtcl/": post types, taxonomies, meta keys,
 * options, tables, capabilities, hooks and REST namespaces. Each must be declared once as a class
 * constant and referenced through that constant everywhere else.
 *
 * Also fails on unprefixed names of bundled libraries. Strauss copies them to vendor-prefixed/ under
 * CustomerLens\Vendor\, and the unprefixed names only resolve in development, through Strauss's aliases.
 *
 * Usage: php bin/check-identifiers.php
 *
 * @package CustomerLens
 */

$root  = dirname( __DIR__ );
$files = [ $root . '/customer-lens.php', $root . '/uninstall.php' ];

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}
sort( $files );

$errors  = [];
$defined = [];

foreach ( $files as $path ) {
	if ( ! is_file( $path ) ) {
		continue;
	}

	$relative = substr( $path, strlen( $root ) + 1 );
	$in_const = false;

	foreach ( token_get_all( file_get_contents( $path ) ) as $token ) {
		if ( ! is_array( $token ) ) {
			if ( ';' === $token ) {
				$in_const = false;
			}
			continue;
		}

		list( $type, $text, $line ) = $token;

		if ( T_CONST === $type ) {
			$in_const = true;
			continue;
		}

		$is_name = T_NAME_QUALIFIED === $type || T_NAME_FULLY_QUALIFIED === $type || T_CONSTANT_ENCAPSED_STRING === $type;
		if ( $is_name && preg_match( '#(?<!Vendor\\\\)\blibphonenumber\\\\#', $text ) ) {
			$errors[] = "{$relative}:{$line} uses {$text}. Use the prefixed CustomerLens\\Vendor\\libphonenumber namespace.";
			continue;
		}

		$is_string = T_CONSTANT_ENCAPSED_STRING === $type || T_ENCAPSED_AND_WHITESPACE === $type;
		if ( ! $is_string || ! preg_match( '#mwtcl[_/]#', $text ) ) {
			continue;
		}

		if ( ! $in_const || T_CONSTANT_ENCAPSED_STRING !== $type ) {
			$errors[] = "{$relative}:{$line} uses the literal {$text}. Reference the class constant that defines it.";
			continue;
		}

		$value = substr( $text, 1, -1 );
		if ( isset( $defined[ $value ] ) ) {
			$errors[] = "{$relative}:{$line} defines {$text} again. It is already defined at {$defined[ $value ]}.";
			continue;
		}
		$defined[ $value ] = "{$relative}:{$line}";
	}
}

if ( $errors ) {
	fwrite( STDERR, implode( PHP_EOL, $errors ) . PHP_EOL );
	exit( 1 );
}

echo 'Identifier check passed: ' . count( $defined ) . ' identifiers, each defined once.' . PHP_EOL;
