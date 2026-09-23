<?php
/**
 * R2 : remplace les références à l'ID de la version par l'ID de l'original
 * dans les données Bricks avant publication.
 *
 * Classe pure (aucune dépendance WordPress) pour être testée unitairement.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Diff;

final class ReferenceReplacer {

	/**
	 * @param list<string> $id_keys Clés de réglages contenant un ID de contenu.
	 */
	public function __construct(
		private array $id_keys = array( 'postId', 'post_id', 'pageId', 'page_id', 'objectId', 'templateId' )
	) {}

	/**
	 * @param array<string, string> $url_pairs URL de la version => URL de l'original.
	 */
	public function replace( mixed $data, int $from, int $to, array $url_pairs = array() ): mixed {
		if ( $from === $to || $from <= 0 ) {
			return $data;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( is_string( $key ) && in_array( $key, $this->id_keys, true ) && $this->is_id( $value, $from ) ) {
					$data[ $key ] = is_int( $value ) ? $to : (string) $to;
					continue;
				}
				$data[ $key ] = $this->replace( $value, $from, $to, $url_pairs );
			}
			return $data;
		}
		if ( is_string( $data ) && '' !== $data ) {
			return $this->replace_in_string( $data, $from, $to, $url_pairs );
		}
		return $data;
	}

	/**
	 * @param array<string, string> $url_pairs
	 */
	private function replace_in_string( string $text, int $from, int $to, array $url_pairs ): string {
		foreach ( $url_pairs as $search => $replacement ) {
			if ( '' !== $search && str_contains( $text, $search ) ) {
				$text = str_replace( $search, $replacement, $text );
			}
		}
		// Liens de type ?page_id=123 ou ?p=123 (versions non publiques).
		$result = preg_replace( '/([?&](?:amp;)?(?:page_id|p)=)' . $from . '(?![0-9])/', '${1}' . $to, $text );
		// Données dynamiques Bricks ciblant un contenu : {post_title:123}.
		$result = preg_replace( '/(\{[a-z_]+:)' . $from . '([}:|])/', '${1}' . $to . '${2}', (string) $result );
		return (string) $result;
	}

	private function is_id( mixed $value, int $id ): bool {
		return ( is_int( $value ) && $value === $id ) || ( is_string( $value ) && (string) $id === $value );
	}
}
