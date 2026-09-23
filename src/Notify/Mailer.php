<?php
/**
 * E-mails (F10), envoyés via wp_mail donc par le plugin SMTP du site.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Notify;

use Lumia\Staging\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Mailer {

	public function __construct( private Settings $settings ) {}

	public function feedback( int $author_id, string $title, string $name, string $decision, string $comment, string $dashboard_url ): void {
		$subject = 'approve' === $decision
			/* translators: 1: site name, 2: client name, 3: content title */
			? sprintf( __( '[%1$s] %2$s a validé « %3$s »', 'lumia-staging' ), $this->site(), $name, $title )
			/* translators: 1: site name, 2: client name, 3: content title */
			: sprintf( __( '[%1$s] %2$s demande des modifications sur « %3$s »', 'lumia-staging' ), $this->site(), $name, $title );

		$body = 'approve' === $decision
			/* translators: 1: client name, 2: content title */
			? sprintf( __( '%1$s a validé la version de travail de « %2$s ».', 'lumia-staging' ), $name, $title )
			/* translators: 1: client name, 2: content title */
			: sprintf( __( '%1$s demande des modifications sur la version de travail de « %2$s ».', 'lumia-staging' ), $name, $title );
		if ( '' !== $comment ) {
			$body .= "\n\n" . __( 'Commentaire :', 'lumia-staging' ) . "\n" . $comment;
		}
		$body .= "\n\n" . __( 'Voir la version :', 'lumia-staging' ) . ' ' . $dashboard_url;

		$this->send( $author_id, $subject, $body );
	}

	public function scheduled_result( int $author_id, string $title, bool $success, string $detail, string $url ): void {
		$subject = $success
			/* translators: 1: site name, 2: content title */
			? sprintf( __( '[%1$s] « %2$s » a été publié comme prévu', 'lumia-staging' ), $this->site(), $title )
			/* translators: 1: site name, 2: content title */
			: sprintf( __( '[%1$s] Échec de la publication programmée de « %2$s »', 'lumia-staging' ), $this->site(), $title );
		$body = $success
			/* translators: %s: content title */
			? sprintf( __( 'La publication programmée de « %s » a été effectuée.', 'lumia-staging' ), $title )
			/* translators: %s: content title */
			: sprintf( __( 'La publication programmée de « %s » n\'a pas pu être effectuée. La version en ligne est inchangée et la version de travail est conservée.', 'lumia-staging' ), $title );
		if ( '' !== $detail ) {
			$body .= "\n\n" . $detail;
		}
		$body .= "\n\n" . $url;
		$this->send( $author_id, $subject, $body );
	}

	private function send( int $user_id, string $subject, string $body ): void {
		if ( ! $this->settings->bool( 'notify_emails' ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		wp_mail( $user->user_email, wp_specialchars_decode( $subject, ENT_QUOTES ), $body );
	}

	private function site(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
