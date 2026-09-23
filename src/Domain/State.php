<?php
/**
 * Les six états d'une version et leurs transitions (cahier des charges §4).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Domain;

defined( 'ABSPATH' ) || exit;

enum State: string {

	case InProgress = 'in_progress';
	case InReview   = 'in_review';
	case Approved   = 'approved';
	case Scheduled  = 'scheduled';
	case Published  = 'published';
	case Abandoned  = 'abandoned';

	/**
	 * États atteignables depuis cet état.
	 *
	 * La publication depuis « En validation » est permise (validation
	 * facultative) mais la fenêtre de publication affiche une alerte.
	 *
	 * @return list<self>
	 */
	public function allowed(): array {
		return match ( $this ) {
			self::InProgress => array( self::InReview, self::Scheduled, self::Published, self::Abandoned ),
			self::InReview   => array( self::InProgress, self::Approved, self::Scheduled, self::Published, self::Abandoned ),
			self::Approved   => array( self::InReview, self::InProgress, self::Scheduled, self::Published, self::Abandoned ),
			self::Scheduled  => array( self::InProgress, self::Published, self::Abandoned ),
			self::Published, self::Abandoned => array(),
		};
	}

	public function can( self $to ): bool {
		return in_array( $to, $this->allowed(), true );
	}

	public function is_open(): bool {
		return self::Published !== $this && self::Abandoned !== $this;
	}

	public function is_publishable(): bool {
		return $this->can( self::Published );
	}

	public function label(): string {
		return match ( $this ) {
			self::InProgress => __( 'Version de travail', 'lumia-staging' ),
			self::InReview   => __( 'En attente du client', 'lumia-staging' ),
			self::Approved   => __( 'Validée', 'lumia-staging' ),
			self::Scheduled  => __( 'Programmée', 'lumia-staging' ),
			self::Published  => __( 'Publiée', 'lumia-staging' ),
			self::Abandoned  => __( 'Abandonnée', 'lumia-staging' ),
		};
	}

	public static function from_meta( mixed $value ): self {
		return self::tryFrom( is_string( $value ) ? $value : '' ) ?? self::InProgress;
	}
}
