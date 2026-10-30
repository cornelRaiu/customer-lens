<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

CustomerLens\Install\Uninstaller::uninstall();
