import { __, sprintf } from '@wordpress/i18n';
import { formatDate } from '../api';

const ICONS = {
	in_progress: <path d="M4 20h4L19 9l-4-4L4 16v4z" />,
	in_review: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M12 7v5l3 2" />
		</>
	),
	approved: <path d="M5 12l5 5 9-10" />,
	scheduled: (
		<>
			<rect x="4" y="5" width="16" height="15" rx="2" />
			<path d="M8 3v4M16 3v4M4 10h16" />
		</>
	),
	conflict: (
		<>
			<path d="M12 3l9 16H3z" />
			<path d="M12 10v4M12 17v.5" />
		</>
	),
};

/**
 * État d'une version : couleur + icône + libellé (jamais la couleur seule).
 *
 * @param {Object} props
 * @param {Object} props.version Version telle que renvoyée par l'API.
 */
export default function StateBadge( { version } ) {
	let key = version.state;
	let label = version.state_label;

	if ( version.orphan ) {
		key = 'conflict';
		label = __( "L'original a été supprimé", 'lumia-staging' );
	} else if ( version.conflict ) {
		key = 'conflict';
		label = __( "L'original a changé", 'lumia-staging' );
	} else if ( version.state === 'in_progress' ) {
		label = __( 'Version de travail', 'lumia-staging' );
	} else if ( version.state === 'in_review' ) {
		label = __( 'En attente du client', 'lumia-staging' );
	} else if (
		version.state === 'approved' &&
		version.feedback?.decision === 'approve'
	) {
		label = sprintf(
			/* translators: %s: client name */
			__( 'Validée par %s', 'lumia-staging' ),
			version.feedback.name
		);
	} else if ( version.state === 'scheduled' && version.scheduled_at ) {
		label = sprintf(
			/* translators: %s: date */
			__( 'En ligne le %s', 'lumia-staging' ),
			formatDate( version.scheduled_at )
		);
	}

	return (
		<span className="lmv-badge" data-state={ key }>
			<svg
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
				aria-hidden="true"
			>
				{ ICONS[ key ] }
			</svg>
			{ label }
		</span>
	);
}
