<?php
/**
 * Conteneur de services minimal, instanciation paresseuse.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging;

defined( 'ABSPATH' ) || exit;

final class Container {

	/** @var array<string, callable(Container): object> */
	private array $factories = array();

	/** @var array<string, object> */
	private array $instances = array();

	/**
	 * @param callable(Container): object $factory
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->instances[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \LogicException( sprintf( 'Service inconnu : %s', esc_html( $id ) ) );
			}
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		}
		/** @var T */
		return $this->instances[ $id ];
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}
