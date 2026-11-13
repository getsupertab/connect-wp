<?php
/**
 * Error raised by the connect API client.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

/**
 * A rejected or failed connect API call; `reason` picks the message shown to the merchant.
 */
final class Connect_Api_Error extends \RuntimeException {

	/**
	 * One of invalid_code, site_mismatch, failed or unreachable.
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason One of invalid_code, site_mismatch, failed or unreachable.
	 */
	public function __construct( string $reason ) {
		parent::__construct( $reason );
		$this->reason = $reason;
	}
}
