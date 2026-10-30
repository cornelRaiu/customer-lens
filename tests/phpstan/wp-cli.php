<?php
/**
 * The parts of WP-CLI the plugin calls, for PHPStan. php-stubs/wp-cli-stubs does not support the
 * WordPress 7 stubs yet.
 *
 * @package CustomerLens
 */

namespace {
	class WP_CLI {
		/** @param string|callable|class-string $callable */
		public static function add_command( string $name, $callable, array $args = [] ): bool {}

		public static function success( string $message ): void {}

		/** @return never */
		public static function error( string $message, bool $exit = true ): void {}

		public static function confirm( string $question, array $assoc_args = [] ): void {}

		public static function log( string $message ): void {}
	}
}

namespace WP_CLI\Utils {
	/** @return \cli\progress\Bar */
	function make_progress_bar( string $message, int $count, int $interval = 100 ) {}

	function format_items( string $format, array $items, $fields ): void {}
}

namespace cli\progress {
	class Bar {
		public function tick( int $increment = 1, ?string $msg = null ): void {}

		public function finish(): void {}
	}
}
