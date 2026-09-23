import { useEffect, useState } from '@wordpress/element';
import { Button, Snackbar } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';

/**
 * « Publié — Annuler » pendant 30 secondes (§9 : annuler plutôt que confirmer).
 *
 * @param {Object}   props
 * @param {string}   props.message    Texte affiché.
 * @param {number}   props.snapshotId Sauvegarde à restaurer pour annuler (0 = pas d'annulation).
 * @param {Function} props.onDone     Fermeture.
 * @param {Function} props.onUndone   Après annulation.
 */
export default function UndoNotice( {
	message,
	snapshotId,
	onDone,
	onUndone,
} ) {
	const [ seconds, setSeconds ] = useState( snapshotId ? 30 : 6 );
	const [ text, setText ] = useState( message );
	const [ undone, setUndone ] = useState( false );

	useEffect( () => {
		if ( seconds <= 0 ) {
			onDone();
			return undefined;
		}
		const timer = setTimeout( () => setSeconds( seconds - 1 ), 1000 );
		return () => clearTimeout( timer );
	}, [ seconds ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const undo = () => {
		setUndone( true );
		api( `/snapshots/${ snapshotId }/restore`, {
			method: 'POST',
			data: { confirm: true },
		} )
			.then( () => {
				setText(
					__(
						'Annulé : la version précédente est de nouveau en ligne.',
						'lumia-staging'
					)
				);
				setSeconds( 6 );
				onUndone?.();
			} )
			.catch( ( e ) => setText( errorMessage( e ) ) );
	};

	return (
		<div className="lmv-snackbar">
			<Snackbar>
				<span>{ text }</span>
				{ snapshotId > 0 && ! undone && (
					<Button
						variant="link"
						onClick={ undo }
						className="lmv-snackbar__undo"
					>
						{ __( 'Annuler', 'lumia-staging' ) } ({ seconds })
					</Button>
				) }
			</Snackbar>
		</div>
	);
}
