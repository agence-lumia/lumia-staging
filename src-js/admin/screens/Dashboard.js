import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SearchControl,
	TabPanel,
	TextareaControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	api,
	config,
	errorMessage,
	formatDate,
	relativeDays,
	TYPE_LABELS,
} from '../api';
import StateBadge from '../components/StateBadge';
import Skeleton from '../components/Skeleton';
import PublishModal from '../components/PublishModal';
import ShareModal from '../components/ShareModal';
import UndoNotice from '../components/UndoNotice';

const TABS = [
	{ name: '', title: __( 'Toutes', 'lumia-staging' ) },
	{ name: 'in_progress', title: __( 'En cours', 'lumia-staging' ) },
	{ name: 'in_review', title: __( 'En attente du client', 'lumia-staging' ) },
	{ name: 'approved', title: __( 'Validées', 'lumia-staging' ) },
	{ name: 'scheduled', title: __( 'Programmées', 'lumia-staging' ) },
];

function BatchModal( { versions, onClose, onDone } ) {
	const [ note, setNote ] = useState( '' );
	const [ when, setWhen ] = useState( '' );
	const [ later, setLater ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const submit = () => {
		setBusy( true );
		setError( null );
		api( '/batches', {
			method: 'POST',
			data: {
				version_ids: versions.map( ( v ) => v.id ),
				note,
				datetime: later ? when : '',
			},
		} )
			.then( ( res ) => onDone( res ) )
			.catch( ( e ) => {
				setBusy( false );
				setError( errorMessage( e ) );
			} );
	};

	return (
		<Modal
			title={ __( 'Publier la sélection', 'lumia-staging' ) }
			onRequestClose={ onClose }
			className="lmv-modal"
		>
			<p>
				{ __(
					"Tout est publié ou rien ne l'est : si une publication échoue, celles déjà faites sont restaurées. Les templates passent en premier, puis les pages.",
					'lumia-staging'
				) }
			</p>
			<ul className="lmv-summary">
				{ versions.map( ( v ) => (
					<li key={ v.id }>
						{ v.title }{ ' ' }
						{ v.is_template && (
							<em>
								(
								{ TYPE_LABELS[ v.template_type ] ||
									__( 'Template', 'lumia-staging' ) }
								)
							</em>
						) }
						{ v.conflict && (
							<strong className="lmv-danger">
								{ ' ' }
								—{ ' ' }
								{ __( "l'original a changé", 'lumia-staging' ) }
							</strong>
						) }
					</li>
				) ) }
			</ul>
			<TextareaControl
				label={ __(
					"Note (reprise dans l'historique)",
					'lumia-staging'
				) }
				value={ note }
				onChange={ setNote }
			/>
			<CheckboxControl
				label={ __( 'Programmer à une date', 'lumia-staging' ) }
				checked={ later }
				onChange={ setLater }
			/>
			{ later && (
				<label className="lmv-field" htmlFor="lmv-batch-when">
					<span>
						{ __(
							'Date et heure (fuseau du site)',
							'lumia-staging'
						) }
					</span>
					<input
						id="lmv-batch-when"
						type="datetime-local"
						value={ when }
						onChange={ ( e ) => setWhen( e.target.value ) }
					/>
				</label>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div className="lmv-modal__actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Annuler', 'lumia-staging' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ( later && ! when ) }
					onClick={ submit }
				>
					{ later
						? __( 'Programmer le lot', 'lumia-staging' )
						: __( 'Publier maintenant', 'lumia-staging' ) }
				</Button>
			</div>
		</Modal>
	);
}

function VersionCard( {
	version,
	selected,
	onSelect,
	onPublish,
	onShare,
	onAbandon,
} ) {
	return (
		<article
			className="lmv-card"
			data-state={
				version.conflict || version.orphan ? 'conflict' : version.state
			}
		>
			<div className="lmv-card__head">
				{ config.caps.publish && (
					<CheckboxControl
						__nextHasNoMarginBottom
						checked={ selected }
						onChange={ onSelect }
						label={
							<span className="screen-reader-text">
								{ sprintf(
									/* translators: %s: title */
									__(
										'Sélectionner « %s »',
										'lumia-staging'
									),
									version.title
								) }
							</span>
						}
						disabled={ version.orphan }
					/>
				) }
				<div className="lmv-card__title">
					<h3>{ version.title }</h3>
					<span className="lmv-muted">
						{ version.is_template
							? `${ __( 'Template', 'lumia-staging' ) } · ${
									TYPE_LABELS[ version.template_type ] ||
									version.template_type
							  }`
							: __( 'Page', 'lumia-staging' ) }
						{ ' · ' }
						{ version.author }
						{ ' · ' }
						{
							/* translators: %s: relative date */ sprintf(
								__( 'modifiée %s', 'lumia-staging' ),
								relativeDays( version.modified )
							)
						}
					</span>
				</div>
				<StateBadge version={ version } />
			</div>
			{ version.note && (
				<p className="lmv-card__note">{ version.note }</p>
			) }
			{ version.feedback && (
				<p className="lmv-card__feedback">
					<strong>{ version.feedback.name }</strong>{ ' ' }
					{ version.feedback.decision === 'approve'
						? __( 'a validé', 'lumia-staging' )
						: __(
								'demande des modifications',
								'lumia-staging'
						  ) }{ ' ' }
					<span className="lmv-muted">
						({ formatDate( version.feedback.date ) })
					</span>
					{ version.feedback.comment && (
						<blockquote>{ version.feedback.comment }</blockquote>
					) }
				</p>
			) }
			{ version.age_days >= ( config.staleDays || 14 ) &&
				version.state !== 'scheduled' && (
					<p className="lmv-muted">
						{
							/* translators: %d: days */ sprintf(
								_n(
									"Ouverte depuis %d jour : pensez à la publier ou à l'abandonner.",
									"Ouverte depuis %d jours : pensez à la publier ou à l'abandonner.",
									version.age_days,
									'lumia-staging'
								),
								version.age_days
							)
						}
					</p>
				) }
			<div className="lmv-card__actions">
				<Button
					variant="secondary"
					href={ version.edit_url }
					disabled={ version.orphan }
				>
					{ __( 'Ouvrir dans Bricks', 'lumia-staging' ) }
				</Button>
				{ config.caps.share && ! version.orphan && (
					<Button variant="tertiary" onClick={ onShare }>
						{ __( 'Aperçu client', 'lumia-staging' ) }
					</Button>
				) }
				{ ! version.orphan && (
					<Button variant="tertiary" href={ version.compare_url }>
						{ __( 'Comparer', 'lumia-staging' ) }
					</Button>
				) }
				{ version.orphan && (
					<Button
						variant="tertiary"
						onClick={ () => exportVersion( version ) }
					>
						{ __( 'Exporter', 'lumia-staging' ) }
					</Button>
				) }
				<span className="lmv-spacer" />
				<Button variant="tertiary" isDestructive onClick={ onAbandon }>
					{ __( 'Abandonner', 'lumia-staging' ) }
				</Button>
				{ config.caps.publish && ! version.orphan && (
					<Button variant="primary" onClick={ onPublish }>
						{ version.state === 'scheduled'
							? __( 'Modifier la publication', 'lumia-staging' )
							: __( 'Publier', 'lumia-staging' ) }
					</Button>
				) }
			</div>
		</article>
	);
}

function exportVersion( version ) {
	api( `/versions/${ version.id }/export` ).then( ( data ) => {
		const blob = new window.Blob( [ JSON.stringify( data, null, 2 ) ], {
			type: 'application/json',
		} );
		const a = document.createElement( 'a' );
		a.href = window.URL.createObjectURL( blob );
		a.download = `version-${ version.id }.json`;
		a.click();
	} );
}

export default function Dashboard() {
	const [ state, setState ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ publishing, setPublishing ] = useState( null );
	const [ sharing, setSharing ] = useState( null );
	const [ batch, setBatch ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( () => {
		const q = new URLSearchParams( { state, search } ).toString();
		api( `/versions?${ q }` )
			.then( ( res ) => {
				setData( res );
				setError( null );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ state, search ] );

	useEffect( () => {
		const t = setTimeout( load, search ? 250 : 0 );
		return () => clearTimeout( t );
	}, [ load, search ] );

	// Lien direct : ?page=lumia-staging&version=ID(&lmv_action=publish).
	useEffect( () => {
		if ( ! data || ! config.params.version ) {
			return;
		}
		const v = data.items.find( ( i ) => i.id === config.params.version );
		if ( v && config.params.action === 'publish' ) {
			setPublishing( v );
		}
		config.params.version = 0;
	}, [ data ] );

	const byId = useMemo(
		() =>
			Object.fromEntries(
				( data?.items || [] ).map( ( v ) => [ v.id, v ] )
			),
		[ data ]
	);

	const abandon = ( version ) => {
		// Irréversible : confirmation explicite.
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				__(
					'Abandonner cette version ? Elle sera supprimée ; la version en ligne ne change pas.',
					'lumia-staging'
				)
			)
		) {
			return;
		}
		const send = ( confirmFlag ) =>
			api( `/versions/${ version.id }/abandon`, {
				method: 'POST',
				data: { confirm: confirmFlag },
			} );
		send( false )
			.catch( ( e ) => {
				// eslint-disable-next-line no-alert
				if (
					e?.code === 'lmv_confirm_required' &&
					window.confirm( e.message )
				) {
					return send( true );
				}
				throw e;
			} )
			.then( ( res ) => {
				if ( res ) {
					setNotice( {
						message: __( 'Version abandonnée.', 'lumia-staging' ),
						snapshotId: 0,
					} );
					load();
				}
			} )
			.catch( ( e ) =>
				setNotice( { message: errorMessage( e ), snapshotId: 0 } )
			);
	};

	const cancelBatch = ( id ) => {
		api( `/batches/${ id }`, { method: 'DELETE' } ).then( load );
	};

	const items = data?.items || [];

	return (
		<div className="lmv-screen">
			<header className="lmv-screen__head">
				<h1>{ __( 'Versions de travail', 'lumia-staging' ) }</h1>
				<p className="lmv-muted">
					{ __(
						"Les visiteurs voient toujours la version en ligne tant qu'une version n'est pas publiée.",
						'lumia-staging'
					) }
				</p>
			</header>

			<div className="lmv-toolbar">
				<TabPanel
					className="lmv-tabs"
					tabs={ TABS }
					onSelect={ ( name ) => setState( name ) }
				>
					{ () => null }
				</TabPanel>
				<SearchControl
					__nextHasNoMarginBottom
					value={ search }
					onChange={ setSearch }
					label={ __( 'Rechercher une version', 'lumia-staging' ) }
				/>
			</div>

			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Réessayer', 'lumia-staging' ),
							onClick: load,
						},
					] }
				>
					{ error }
				</Notice>
			) }

			{ data?.batches?.length > 0 && (
				<section className="lmv-batches">
					<h2>{ __( 'Lots programmés', 'lumia-staging' ) }</h2>
					{ data.batches.map( ( b ) => (
						<div key={ b.id } className="lmv-batch">
							<span>
								{
									/* translators: 1: date, 2: count */ sprintf(
										_n(
											'En ligne le %1$s — %2$d contenu',
											'En ligne le %1$s — %2$d contenus',
											b.versions.length,
											'lumia-staging'
										),
										formatDate( b.scheduled_at ),
										b.versions.length
									)
								}
								{ b.note && ` — ${ b.note }` }
							</span>
							{ config.caps.publish && (
								<Button
									variant="link"
									isDestructive
									onClick={ () => cancelBatch( b.id ) }
								>
									{ __( 'Annuler le lot', 'lumia-staging' ) }
								</Button>
							) }
						</div>
					) ) }
				</section>
			) }

			{ ! data && ! error && (
				<div className="lmv-cards">
					{ [ 1, 2, 3 ].map( ( i ) => (
						<div key={ i } className="lmv-card">
							<Skeleton lines={ 3 } />
						</div>
					) ) }
				</div>
			) }

			{ data && items.length === 0 && (
				<div className="lmv-empty">
					<h2>
						{ search || state
							? __(
									'Aucune version ne correspond.',
									'lumia-staging'
							  )
							: __( 'Aucune version en cours', 'lumia-staging' ) }
					</h2>
					<p>
						{ __(
							"Pour modifier une page en ligne sans que les visiteurs voient le travail en cours, cliquez sur « Créer une version » dans la liste des pages, dans la barre d'admin ou dans Bricks.",
							'lumia-staging'
						) }
					</p>
					<Button
						variant="primary"
						href={
							( config.adminUrl || '' ) +
							'edit.php?post_type=page'
						}
					>
						{ __( 'Aller aux pages', 'lumia-staging' ) }
					</Button>
				</div>
			) }

			{ items.length > 0 && (
				<div className="lmv-cards">
					{ items.map( ( v ) => (
						<VersionCard
							key={ v.id }
							version={ v }
							selected={ selected.includes( v.id ) }
							onSelect={ ( on ) =>
								setSelected(
									on
										? [ ...selected, v.id ]
										: selected.filter(
												( id ) => id !== v.id
										  )
								)
							}
							onPublish={ () => setPublishing( v ) }
							onShare={ () => setSharing( v ) }
							onAbandon={ () => abandon( v ) }
						/>
					) ) }
				</div>
			) }

			{ selected.length > 0 && (
				<div
					className="lmv-selection"
					role="region"
					aria-label={ __( 'Sélection', 'lumia-staging' ) }
				>
					<span>
						{
							/* translators: %d: count */ sprintf(
								_n(
									'%d version sélectionnée',
									'%d versions sélectionnées',
									selected.length,
									'lumia-staging'
								),
								selected.length
							)
						}
					</span>
					<Button
						variant="tertiary"
						onClick={ () => setSelected( [] ) }
					>
						{ __( 'Désélectionner', 'lumia-staging' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => setBatch( true ) }
					>
						{ __( 'Publier la sélection', 'lumia-staging' ) }
					</Button>
				</div>
			) }

			{ publishing && (
				<PublishModal
					version={ publishing }
					onClose={ () => setPublishing( null ) }
					onChanged={ ( message ) => {
						setNotice( { message, snapshotId: 0 } );
						load();
					} }
					onPublished={ ( res, v ) => {
						setPublishing( null );
						setNotice( {
							message: sprintf(
								/* translators: %s: title */
								__( '« %s » est publié.', 'lumia-staging' ),
								v.title
							),
							snapshotId: res.snapshot_id,
						} );
						load();
					} }
				/>
			) }
			{ sharing && (
				<ShareModal
					version={ sharing }
					onClose={ () => setSharing( null ) }
					onChanged={ load }
				/>
			) }
			{ batch && (
				<BatchModal
					versions={ selected
						.map( ( id ) => byId[ id ] )
						.filter( Boolean ) }
					onClose={ () => setBatch( false ) }
					onDone={ ( res ) => {
						setBatch( false );
						setSelected( [] );
						setNotice( {
							message: res.scheduled
								? __( 'Lot programmé.', 'lumia-staging' )
								: __( 'Sélection publiée.', 'lumia-staging' ),
							snapshotId: 0,
						} );
						load();
					} }
				/>
			) }
			{ notice && (
				<UndoNotice
					key={ notice.message + notice.snapshotId }
					message={ notice.message }
					snapshotId={ notice.snapshotId }
					onDone={ () => setNotice( null ) }
					onUndone={ load }
				/>
			) }
		</div>
	);
}
