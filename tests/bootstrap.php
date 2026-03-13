<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

// WordPress function stubs for unit testing.
require_once __DIR__ . '/wp-stubs.php';

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
