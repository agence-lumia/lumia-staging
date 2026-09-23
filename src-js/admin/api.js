import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

export const config = window.lmvAdmin || {};
const NS = '/' + ( config.namespace || 'lumia-staging/v1' );

/**
 * Appel à l'API interne du plugin.
 *
 * @param {string} path   Chemin relatif (ex. « /versions »).
 * @param {Object} [opts] Options apiFetch (method, data).
 * @return {Promise<any>} Réponse JSON.
 */
export function api( path, opts = {} ) {
	return apiFetch( { path: NS + path, ...opts } );
}

export function adminUrl( query ) {
	return ( config.adminUrl || '/wp-admin/' ) + 'admin.php?' + query;
}

export function formatDate( iso ) {
	if ( ! iso ) {
		return '';
	}
	try {
		return new Intl.DateTimeFormat(
			document.documentElement.lang || 'fr-FR',
			{
				dateStyle: 'medium',
				timeStyle: 'short',
				timeZone:
					config.timezone && config.timezone.includes( '/' )
						? config.timezone
						: undefined,
			}
		).format( new Date( iso ) );
	} catch ( e ) {
		return iso;
	}
}

export function relativeDays( iso ) {
	const days = Math.floor(
		( Date.now() - new Date( iso ).getTime() ) / 86400000
	);
	if ( days <= 0 ) {
		return __( "aujourd'hui", 'lumia-staging' );
	}
	if ( days === 1 ) {
		return __( 'hier', 'lumia-staging' );
	}
	/* translators: %d: number of days */
	return sprintf( __( 'il y a %d jours', 'lumia-staging' ), days );
}

export function errorMessage( error ) {
	return (
		( error && error.message ) ||
		__( 'Une erreur est survenue.', 'lumia-staging' )
	);
}

export const TYPE_LABELS = {
	header: __( 'Header', 'lumia-staging' ),
	footer: __( 'Footer', 'lumia-staging' ),
	section: __( 'Section', 'lumia-staging' ),
	content: __( 'Contenu', 'lumia-staging' ),
	archive: __( 'Archive', 'lumia-staging' ),
	popup: __( 'Popup', 'lumia-staging' ),
};
