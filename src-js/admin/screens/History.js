import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Modal, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, config, errorMessage, formatDate } from '../api';
import Skeleton from '../components/Skeleton';
import StateBadge from '../components/StateBadge';
import UndoNotice from '../components/UndoNotice';

/**
 * Historique d'un contenu (P5, F8) : frise des publications, aperçu, restauration.
 */
export default function History() {
	const postId = config.params.post;
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const [ busy, setBusy ] = useState( 0 );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( () => {
		api( `/posts/${ postId }/history` )
			.then( setData )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ postId ] );

	useEffect( () => {
		if ( postId ) {
			load();
		}
	}, [ load, postId ] );

	const restore = ( item, confirmed = false ) => {
		setBusy( item.id );
		setConfirm( null );
		api( `/snapshots/${ item.id }/restore`, {
			method: 'POST',
			data: { confirm: confirmed },
		} )
			.then( ( res ) => {
				setBusy( 0 );
				setNotice( {
					message: __(
						'Version restaurée : elle est en ligne.',
						'lumia-staging'
					),
					snapshotId: res.snapshot_id,
				} );
				load();
			} )
			.catch( ( e ) => {
				setBusy( 0 );
				if ( e?.code === 'lmv_confirm_required' ) {
					setConfirm( { item, message: errorMessage( e ) } );
					return;
				}
				setError( errorMessage( e ) );
			} );
	};

	if ( ! postId ) {
		return (
			<div className="lmv-screen">
				<h1>{ __( 'Historique', 'lumia-staging' ) }</h1>
				<p>
					{ __(
						"Ouvrez l'historique depuis la liste des pages ou des templates (lien « Historique » sous le titre).",
						'lumia-staging'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="lmv-screen">
			<header className="lmv-screen__head">
				<h1>
					{ data
						? sprintf(
								/* translators: %s: title */
								__( 'Historique de « %s »', 'lumia-staging' ),
								data.post.title
						  )
						: __( 'Historique', 'lumia-staging' ) }
				</h1>
				{ data && (
					<p className="lmv-muted">
						<a
							href={ data.post.url }
							target="_blank"
							rel="noreferrer"
						>
							{ __( 'Voir la page en ligne', 'lumia-staging' ) }
						</a>
					</p>
				) }
			</header>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ data?.open_version && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						"Une version de travail est ouverte pour ce contenu. Restaurer une ancienne version modifiera l'original : la version de travail signalera un conflit à sa publication.",
						'lumia-staging'
					) }{ ' ' }
					<StateBadge version={ data.open_version } />
				</Notice>
			) }
			{ ! data && ! error && <Skeleton lines={ 5 } /> }
			{ data && data.items.length === 0 && (
				<div className="lmv-empty">
					<h2>
						{ __(
							"Aucune publication pour l'instant",
							'lumia-staging'
						) }
					</h2>
					<p>
						{ __(
							"Chaque publication d'une version conserve ici une sauvegarde de l'état précédent, restaurable en un clic.",
							'lumia-staging'
						) }
					</p>
				</div>
			) }
			{ data && data.items.length > 0 && (
				<ol className="lmv-timeline">
					<li className="lmv-timeline__item is-current">
						<div className="lmv-timeline__dot" aria-hidden="true" />
						<div>
							<strong>
								{ __(
									'En ligne actuellement',
									'lumia-staging'
								) }
							</strong>
							{ data.items[ 0 ].replaced_note && (
								<p className="lmv-muted">
									{ data.items[ 0 ].replaced_note }
								</p>
							) }
						</div>
					</li>
					{ data.items.map( ( item ) => (
						<li key={ item.id } className="lmv-timeline__item">
							<div
								className="lmv-timeline__dot"
								aria-hidden="true"
							/>
							<div className="lmv-timeline__body">
								<strong>
									{
										/* translators: %s: date */ sprintf(
											__(
												"En ligne jusqu'au %s",
												'lumia-staging'
											),
											formatDate( item.replaced_at )
										)
									}
								</strong>
								{ item.content_note && (
									<p>{ item.content_note }</p>
								) }
								<p className="lmv-muted">
									{
										/* translators: 1: user, 2: note */ sprintf(
											__(
												'Remplacée par %1$s%2$s',
												'lumia-staging'
											),
											item.replaced_by,
											item.replaced_note
												? ` — « ${ item.replaced_note } »`
												: ''
										)
									}
									{ item.validation &&
										` · ${
											/* translators: %s: name */ sprintf(
												__(
													'validée par %s',
													'lumia-staging'
												),
												item.validation.name
											)
										}` }
								</p>
								{ item.bricks_outdated && (
									<p className="lmv-muted">
										{
											/* translators: %s: Bricks version */ sprintf(
												__(
													"Sauvegardée sous Bricks %s : vérifiez l'aperçu avant de restaurer.",
													'lumia-staging'
												),
												item.bricks_version
											)
										}
									</p>
								) }
								<div className="lmv-card__actions">
									<Button
										variant="tertiary"
										href={ item.preview_url }
										target="_blank"
									>
										{ __( 'Aperçu', 'lumia-staging' ) }
									</Button>
									<Button
										variant="tertiary"
										href={ item.compare_url }
									>
										{ __(
											'Comparer avec la version en ligne',
											'lumia-staging'
										) }
									</Button>
									{ config.caps.restore && (
										<Button
											variant="secondary"
											isBusy={ busy === item.id }
											disabled={ busy > 0 }
											onClick={ () => restore( item ) }
										>
											{ __(
												'Revenir à cette version',
												'lumia-staging'
											) }
										</Button>
									) }
								</div>
							</div>
						</li>
					) ) }
				</ol>
			) }
			{ confirm && (
				<Modal
					title={ __( 'Confirmer la restauration', 'lumia-staging' ) }
					onRequestClose={ () => setConfirm( null ) }
					className="lmv-modal"
				>
					<p>{ confirm.message }</p>
					<div className="lmv-modal__actions">
						<Button
							variant="tertiary"
							onClick={ () => setConfirm( null ) }
						>
							{ __( 'Annuler', 'lumia-staging' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => restore( confirm.item, true ) }
						>
							{ __( 'Restaurer quand même', 'lumia-staging' ) }
						</Button>
					</div>
				</Modal>
			) }
			{ notice && (
				<UndoNotice
					key={ notice.snapshotId }
					message={ notice.message }
					snapshotId={ notice.snapshotId }
					onDone={ () => setNotice( null ) }
					onUndone={ load }
				/>
			) }
		</div>
	);
}
