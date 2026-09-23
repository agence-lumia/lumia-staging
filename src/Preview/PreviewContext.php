<?php
/**
 * Contexte d'aperçu de la requête en cours.
 *
 * - client : lien partagé (`?lmv_preview=jeton`), barre de validation ;
 * - signed : URL signée à durée courte, pour le comparatif et l'aperçu
 *   admin (rendu anonyme, sans barre).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Preview;

defined( 'ABSPATH' ) || exit;

final class PreviewContext {

	/**
	 * @param array<string, string> $args Paramètres d'URL à propager dans les liens.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly int $version_id,
		public readonly int $source_id,
		public readonly int $snapshot_id,
		public readonly bool $live,
		public readonly int $token_id,
		public readonly string $expires_at,
		public readonly array $args
	) {}

	public function is_client(): bool {
		return 'client' === $this->mode;
	}

	/**
	 * Affiche-t-on la version de travail (et non la version en ligne) ?
	 */
	public function shows_version(): bool {
		return ! $this->live && $this->version_id > 0;
	}
}
