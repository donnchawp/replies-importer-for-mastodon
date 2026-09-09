<?php
/**
 * Test runner.
 *
 * Each test file runs in its own PHP process. They have to: one file needs the
 * ActivityPub plugin to exist and another needs it not to, and PHP cannot forget a
 * function once it is declared.
 *
 * Usage: php tests/run.php [name]
 *
 * @package RepliesImporterForMastodon
 */

$filter = isset( $argv[1] ) ? $argv[1] : '';
$files  = glob( __DIR__ . '/test-*.php' );

sort( $files );

$passed = 0;
$failed = 0;
$ran    = 0;

foreach ( $files as $file ) {
	$name = basename( $file, '.php' );

	if ( '' !== $filter && false === strpos( $name, $filter ) ) {
		continue;
	}

	++$ran;

	echo "\n" . $name . "\n";

	$output = array();
	$status = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );

	foreach ( $output as $line ) {
		echo $line . "\n";
	}

	if ( preg_match( '/(\d+) passed, (\d+) failed/', implode( "\n", $output ), $matches ) ) {
		$passed += (int) $matches[1];
		$failed += (int) $matches[2];
	}

	if ( 0 !== $status && ! preg_match( '/\d+ passed/', implode( "\n", $output ) ) ) {
		echo "  the file did not run\n";
		++$failed;
	}
}

if ( 0 === $ran ) {
	echo "No test files matched.\n";
	exit( 1 );
}

echo "\n" . str_repeat( '-', 48 ) . "\n";
echo $ran . ' files, ' . $passed . ' passed, ' . $failed . " failed\n";

exit( $failed > 0 ? 1 : 0 );
