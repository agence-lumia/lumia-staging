<?php
/**
 * Transaction SQL, seulement si les tables concernées sont en InnoDB.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Support;

defined( 'ABSPATH' ) || exit;

final class Transaction {

	private ?bool $supported = null;

	private bool $active = false;

	public function __construct( private \wpdb $db ) {}

	public function supported(): bool {
		if ( null === $this->supported ) {
			$engines         = $this->db->get_col(
				$this->db->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
					$this->db->posts,
					$this->db->postmeta
				)
			);
			$engines         = array_map( 'strtolower', array_map( 'strval', $engines ) );
			$this->supported = array() !== $engines && array( 'innodb' ) === array_values( array_unique( $engines ) );
		}
		return $this->supported;
	}

	public function begin(): bool {
		if ( $this->active || ! $this->supported() ) {
			return false;
		}
		$this->active = false !== $this->db->query( 'START TRANSACTION' );
		return $this->active;
	}

	public function commit(): void {
		if ( $this->active ) {
			$this->db->query( 'COMMIT' );
			$this->active = false;
		}
	}

	public function rollback(): bool {
		if ( ! $this->active ) {
			return false;
		}
		$this->db->query( 'ROLLBACK' );
		$this->active = false;
		return true;
	}
}
