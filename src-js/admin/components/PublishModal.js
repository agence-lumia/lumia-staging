import { useEffect, useState } from '@wordpress/element';
import { Button, Modal, Notice, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import Skeleton from './Skeleton';

const FIELD_LABELS = {
	title: __( 'titre', 'lumia-staging' ),
	excerpt: __( 'extrait', 'lumia-staging' ),
	thumbnail: __( 'image mise en avant', 'lumia-staging' ),
};

export function ChangeList( { diff } ) {
	if ( ! diff ) {
		return null;
	}
	const items = [];
	if ( ! diff.has_changes && ! diff.fields?.length ) {
		items.push(
			__(
				'Aucune différence de mise en page avec la version en ligne.',
				'lumia-staging'
			)
		);
	} else {
		items.push(
			sprintf(
				/* translators: 1: added, 2: removed, 3: modified */
				__(
					'%1$d élément(s) ajouté(s), %2$d supprimé(s), %3$d modifié(s)',
					'lumia-staging'
				),
				diff.elements.added,
				diff.elements.removed,
				diff.elements.modified
			)
		);
		if ( diff.css_changed ) {
			items.push( __( 'CSS de la page modifié', 'lumia-staging' ) );
		}
		if ( diff.settings_changed ) {
			items.push( __( 'Réglages de la page modifiés', 'lumia-staging' ) );
		}
		if ( diff.fields?.length ) {
			items.push(
				sprintf(
					/* translators: %s: field list */
					__( 'Champs modifiés : %s', 'lumia-staging' ),
					diff.fields
						.map( ( f ) => FIELD_LABELS[ f ] || f )
						.join( ', ' )
				)
			);
		}
	}
	return (
		<ul className="lmv-summary">
			{ items.map( ( text ) => (
				<li key={ text }>{ text }</li>
			) ) }
		</ul>
	);
}

export function Alerts( { alerts } ) {
	return ( alerts || [] ).map( ( a ) => (
		<Notice
			key={ a.code }
			status={ a.level === 'warning' ? 'warning' : 'error' }
			isDismissible={ false }
		>
			{ a.message }
		</Notice>
	) );
}

/**
 * Fenêtre de publication (F5) : résumé, alertes, note, publier ou programmer.
 *
 * @param {Object}   props
 * @param {Object}   props.version     Version concernée.
 * @param {Function} props.onClose     Fermeture.
 * @param {Function} props.onPublished Appelé avec la réponse de publication.
 * @param {Function} props.onChanged   Appelé après une programmation.
 */
export default function PublishModal( {
	version,
	onClose,
	onPublished,
	onChanged,
} ) {
	const [ summary, setSummary ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ note, setNote ] = useState( version.note || '' );
	const [ when, setWhen ] = useState( '' );
	const [ scheduling, setScheduling ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ conflict, setConflict ] = useState( null );

	useEffect( () => {
		api( `/versions/${ version.id }/summary` )
			.then( setSummary )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ version.id ] );

	const publish = ( force = false ) => {
		setBusy( true );
		setError( null );
		api( `/versions/${ version.id }/publish`, {
			method: 'POST',
			data: { note, force },
		} )
			.then( ( res ) => onPublished( res, version ) )
			.catch( ( e ) => {
				setBusy( false );
				if ( e?.code === 'lmv_conflict' ) {
					setConflict( errorMessage( e ) );
					return;
				}
				setError( errorMessage( e ) );
			} );
	};

	const schedule = () => {
		if ( ! when ) {
			return;
		}
		setBusy( true );
		api( `/versions/${ version.id }/schedule`, {
			method: 'POST',
			data: { datetime: when, note },
		} )
			.then( () => {
				onChanged( __( 'Publication programmée.', 'lumia-staging' ) );
				onClose();
			} )
			.catch( ( e ) => {
				setBusy( false );
				setError( errorMessage( e ) );
			} );
	};

	const unschedule = () => {
		setBusy( true );
		api( `/versions/${ version.id }/schedule`, { method: 'DELETE' } )
			.then( () => {
				onChanged( __( 'Programmation annulée.', 'lumia-staging' ) );
				onClose();
			} )
			.catch( ( e ) => {
				setBusy( false );
				setError( errorMessage( e ) );
			} );
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: content title */
				__( 'Publier « %s »', 'lumia-staging' ),
				version.title
			) }
			onRequestClose={ onClose }
			className="lmv-modal"
		>
			{ ! summary && ! error && <Skeleton lines={ 4 } /> }
			{ summary && (
				<>
					<ChangeList diff={ summary.diff } />
					<Alerts alerts={ summary.alerts } />
					<TextareaControl
						label={ __(
							"Note (reprise dans l'historique)",
							'lumia-staging'
						) }
						placeholder={ __(
							'Ex. : refonte du hero + nouvelle section avis',
							'lumia-staging'
						) }
						value={ note }
						onChange={ setNote }
						maxLength={ 500 }
					/>
					{ scheduling && (
						<label className="lmv-field" htmlFor="lmv-when">
							<span>
								{ __(
									'Date et heure de mise en ligne (fuseau du site)',
									'lumia-staging'
								) }
							</span>
							<input
								id="lmv-when"
								type="datetime-local"
								value={ when }
								onChange={ ( e ) => setWhen( e.target.value ) }
							/>
							<small>
								{ __(
									'Précision : à 5 minutes près.',
									'lumia-staging'
								) }
							</small>
						</label>
					) }
				</>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ conflict && (
				<Notice status="error" isDismissible={ false }>
					{ conflict }
				</Notice>
			) }
			<div className="lmv-modal__actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Annuler', 'lumia-staging' ) }
				</Button>
				{ conflict ? (
					<>
						<Button
							variant="secondary"
							href={ version.compare_url }
							target="_blank"
						>
							{ __( 'Voir les différences', 'lumia-staging' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => publish( true ) }
						>
							{ __(
								'Publier quand même (écrase)',
								'lumia-staging'
							) }
						</Button>
					</>
				) : (
					<>
						{ version.state === 'scheduled' && (
							<Button
								variant="secondary"
								disabled={ busy }
								onClick={ unschedule }
							>
								{ __(
									'Annuler la programmation',
									'lumia-staging'
								) }
							</Button>
						) }
						<Button
							variant="secondary"
							disabled={
								busy ||
								! summary ||
								summary.blocking ||
								( scheduling && ! when )
							}
							onClick={ () =>
								scheduling ? schedule() : setScheduling( true )
							}
						>
							{ scheduling
								? __(
										'Programmer à cette date',
										'lumia-staging'
								  )
								: __( 'Programmer', 'lumia-staging' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy || ! summary || summary.blocking }
							onClick={ () => publish( false ) }
						>
							{ __( 'Publier maintenant', 'lumia-staging' ) }
						</Button>
					</>
				) }
			</div>
		</Modal>
	);
}
