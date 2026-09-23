import { useEffect, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import Skeleton from '../components/Skeleton';
import {
	NumberField,
	Option,
	Section,
	SelectField,
	Toggle,
} from '../components/Form';

/**
 * Réglages : types de contenu, Bricks, liens client, rétention, e-mails, données.
 */
export default function Settings() {
	const [ data, setData ] = useState( null );
	const [ values, setValues ] = useState( null );
	const [ saved, setSaved ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ checking, setChecking ] = useState( false );

	// La bascule « mise à jour auto » vit dans l'option native de WordPress,
	// pas dans nos réglages : elle voyage avec le formulaire pour « Enregistrer ».
	const load = ( res ) => {
		const next = { ...res.settings, auto_update: res.updates.auto_update };
		setData( res );
		setValues( next );
		setSaved( JSON.stringify( next ) );
	};

	useEffect( () => {
		api( '/settings' )
			.then( load )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);
	}, [] );

	const dirty = values && saved !== JSON.stringify( values );

	const set = ( key ) => ( value ) =>
		setValues( { ...values, [ key ]: value } );
	const toggleType = ( type ) => ( on ) =>
		setValues( {
			...values,
			post_types: on
				? [ ...values.post_types, type ]
				: values.post_types.filter( ( t ) => t !== type ),
		} );

	const save = ( e ) => {
		e.preventDefault();
		setBusy( true );
		api( '/settings', { method: 'POST', data: values } )
			.then( ( res ) => {
				load( res );
				setNotice( {
					status: 'success',
					text: __( 'Réglages enregistrés.', 'lumia-staging' ),
				} );
			} )
			.catch( ( err ) =>
				setNotice( { status: 'error', text: errorMessage( err ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const checkUpdates = () => {
		setChecking( true );
		api( '/updates/check', { method: 'POST' } )
			.then( ( status ) => {
				setData( { ...data, updates: { ...data.updates, ...status } } );
				setNotice( {
					status: status.has_update ? 'warning' : 'success',
					text: status.has_update
						? __(
								'Une mise à jour est disponible : installez-la depuis l’écran Extensions.',
								'lumia-staging'
						  )
						: __( 'Le plugin est à jour.', 'lumia-staging' ),
				} );
			} )
			.catch( ( err ) =>
				setNotice( { status: 'error', text: errorMessage( err ) } )
			)
			.finally( () => setChecking( false ) );
	};

	const onUninstallToggle = ( on ) => {
		if (
			on &&
			data.open_versions.length > 0 &&
			// Suppression de données : confirmation explicite (§9).
			! window.confirm(
				__(
					'Des versions de travail sont ouvertes : elles seront supprimées à la désinstallation. Continuer ?',
					'lumia-staging'
				)
			)
		) {
			return;
		}
		set( 'delete_on_uninstall' )( on );
	};

	return (
		<form className="lmv-screen lmv-screen--form" onSubmit={ save }>
			<header className="lmv-screen__head">
				<div className="lmv-screen__head-content">
					<h1>{ __( 'Réglages', 'lumia-staging' ) }</h1>
					<p className="lmv-muted">
						{ __(
							'Ce qui peut être versionné, et combien de temps l’historique est gardé.',
							'lumia-staging'
						) }
					</p>
				</div>
				<div className="lmv-screen__head-actions">
					<button
						type="submit"
						className="lmv-btn lmv-btn--primary"
						disabled={ busy || ! values || ! dirty }
						aria-busy={ busy }
					>
						{ busy
							? __( 'Enregistrement…', 'lumia-staging' )
							: __( 'Enregistrer', 'lumia-staging' ) }
					</button>
				</div>
			</header>

			<div className="lmv-screen__scroll">
				{ notice && (
					<div className="lmv-screen__notice">
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
						>
							{ notice.text }
						</Notice>
					</div>
				) }

				{ ! values && ! notice && (
					<div className="lmv-section">
						<Skeleton lines={ 6 } />
					</div>
				) }

				{ values && (
					<>
						<Section
							title={ __(
								'Contenus versionnables',
								'lumia-staging'
							) }
							desc={ __(
								'Les types de contenu sur lesquels « Créer une version » est proposé.',
								'lumia-staging'
							) }
						>
							<Option
								htmlFor="lmv-pt-page"
								label={ __( 'Pages', 'lumia-staging' ) }
								desc={ __(
									'Pages éditées avec Bricks.',
									'lumia-staging'
								) }
							>
								<Toggle
									id="lmv-pt-page"
									checked={ values.post_types.includes(
										'page'
									) }
									onChange={ toggleType( 'page' ) }
								/>
							</Option>
							<Option
								htmlFor="lmv-pt-template"
								label={ __(
									'Templates Bricks',
									'lumia-staging'
								) }
								desc={ __(
									'Header, footer, section, fiche, archive, popup…',
									'lumia-staging'
								) }
							>
								<Toggle
									id="lmv-pt-template"
									checked={ values.post_types.includes(
										'bricks_template'
									) }
									onChange={ toggleType( 'bricks_template' ) }
								/>
							</Option>
							<Option
								htmlFor="lmv-fields"
								label={ __(
									'Publier le titre, l’extrait et l’image mise en avant',
									'lumia-staging'
								) }
								desc={ __(
									'Le slug et le SEO de la page ne sont jamais modifiés par une publication.',
									'lumia-staging'
								) }
							>
								<Toggle
									id="lmv-fields"
									checked={ values.publish_post_fields }
									onChange={ set( 'publish_post_fields' ) }
								/>
							</Option>
						</Section>

						<Section
							title={ __( 'Éditeur Bricks', 'lumia-staging' ) }
							desc={ __(
								'Ce que Lümia Staging affiche dans le builder.',
								'lumia-staging'
							) }
						>
							<Option
								htmlFor="lmv-builder-button"
								label={ __(
									'Bouton « Créer une version »',
									'lumia-staging'
								) }
								desc={ __(
									'Affiché en bas du builder sur les pages sans version. Chacun peut aussi le réduire en pastille depuis Bricks.',
									'lumia-staging'
								) }
							>
								<Toggle
									id="lmv-builder-button"
									checked={ values.builder_button }
									onChange={ set( 'builder_button' ) }
								/>
							</Option>
						</Section>

						<Section
							title={ __( 'Aperçu client', 'lumia-staging' ) }
							desc={ __(
								'Liens envoyés au client pour valider une version, sans compte WordPress.',
								'lumia-staging'
							) }
						>
							<Option
								htmlFor="lmv-preview-days"
								label={ __(
									'Durée de validité par défaut',
									'lumia-staging'
								) }
								desc={ __(
									'Entre 1 et 30 jours. Modifiable à chaque lien.',
									'lumia-staging'
								) }
							>
								<NumberField
									id="lmv-preview-days"
									min={ 1 }
									max={ 30 }
									value={ values.preview_days }
									onChange={ set( 'preview_days' ) }
									suffix={ __( 'jours', 'lumia-staging' ) }
								/>
							</Option>
						</Section>

						<Section
							title={ __(
								'Historique et journal',
								'lumia-staging'
							) }
							desc={ __(
								'La sauvegarde la plus récente de chaque contenu est toujours conservée : le retour à la version précédente reste possible.',
								'lumia-staging'
							) }
						>
							<Option
								htmlFor="lmv-ret-count"
								label={ __(
									'Sauvegardes par contenu',
									'lumia-staging'
								) }
							>
								<NumberField
									id="lmv-ret-count"
									min={ 1 }
									max={ 200 }
									value={ values.retention_count }
									onChange={ set( 'retention_count' ) }
								/>
							</Option>
							<Option
								htmlFor="lmv-ret-days"
								label={ __(
									'Conservation des sauvegardes',
									'lumia-staging'
								) }
								desc={ __(
									'30 jours minimum.',
									'lumia-staging'
								) }
							>
								<NumberField
									id="lmv-ret-days"
									min={ 30 }
									value={ values.retention_days }
									onChange={ set( 'retention_days' ) }
									suffix={ __( 'jours', 'lumia-staging' ) }
								/>
							</Option>
							<Option
								htmlFor="lmv-log-months"
								label={ __(
									'Conservation du journal',
									'lumia-staging'
								) }
							>
								<NumberField
									id="lmv-log-months"
									min={ 1 }
									max={ 60 }
									value={ values.log_months }
									onChange={ set( 'log_months' ) }
									suffix={ __( 'mois', 'lumia-staging' ) }
								/>
							</Option>
						</Section>

						<Section
							title={ __( 'Notifications', 'lumia-staging' ) }
						>
							<Option
								htmlFor="lmv-emails"
								label={ __( 'E-mails', 'lumia-staging' ) }
								desc={ __(
									'Validation client et publications programmées, envoyés par le plugin SMTP du site.',
									'lumia-staging'
								) }
							>
								<Toggle
									id="lmv-emails"
									checked={ values.notify_emails }
									onChange={ set( 'notify_emails' ) }
								/>
							</Option>
						</Section>

						<Section
							title={ __( 'Mises à jour', 'lumia-staging' ) }
							desc={ __(
								'Les nouvelles versions viennent des releases GitHub et sont vérifiées (SHA256) avant installation.',
								'lumia-staging'
							) }
						>
							<Option
								label={ __( 'Version', 'lumia-staging' ) }
								desc={
									data.updates.remote
										? __(
												'Dernière version connue sur le canal choisi.',
												'lumia-staging'
										  )
										: __(
												'Pas encore vérifiée sur ce canal.',
												'lumia-staging'
										  )
								}
							>
								<span className="lmv-inline">
									<span className="lmv-chip">
										{ __( 'Installée', 'lumia-staging' ) }{ ' ' }
										{ data.updates.installed }
									</span>
									{ data.updates.remote && (
										<span
											className={ `lmv-chip${
												data.updates.has_update
													? ' lmv-chip--accent'
													: ''
											}` }
										>
											{ data.updates.has_update
												? __(
														'Disponible',
														'lumia-staging'
												  )
												: __(
														'À jour',
														'lumia-staging'
												  ) }{ ' ' }
											{ data.updates.remote }
										</span>
									) }
									{ data.updates.can_check && (
										<button
											type="button"
											className="lmv-btn"
											onClick={ checkUpdates }
											disabled={ checking }
											aria-busy={ checking }
										>
											{ checking
												? __(
														'Vérification…',
														'lumia-staging'
												  )
												: __(
														'Vérifier',
														'lumia-staging'
												  ) }
										</button>
									) }
								</span>
							</Option>
							<Option
								htmlFor="lmv-auto-update"
								label={ __(
									'Mises à jour automatiques',
									'lumia-staging'
								) }
								desc={
									data.updates.auto_update_available
										? __(
												'Même réglage que la colonne « Mises à jour auto » de l’écran Extensions.',
												'lumia-staging'
										  )
										: __(
												'Désactivées sur ce site (ou droits insuffisants).',
												'lumia-staging'
										  )
								}
							>
								<Toggle
									id="lmv-auto-update"
									checked={ values.auto_update }
									onChange={ set( 'auto_update' ) }
									disabled={
										! data.updates.auto_update_available
									}
								/>
							</Option>
							<Option
								htmlFor="lmv-channel"
								label={ __(
									'Canal de mise à jour',
									'lumia-staging'
								) }
								desc={ __(
									'Le canal dev reçoit une pré-version à chaque modification, pour tester avant tout le monde.',
									'lumia-staging'
								) }
							>
								<SelectField
									id="lmv-channel"
									value={ values.update_channel }
									onChange={ set( 'update_channel' ) }
									options={ [
										{
											value: 'stable',
											label: __(
												'Stable',
												'lumia-staging'
											),
										},
										{
											value: 'dev',
											label: __(
												'Dev (pré-versions)',
												'lumia-staging'
											),
										},
									] }
								/>
							</Option>
						</Section>

						<Section
							title={ __( 'Données', 'lumia-staging' ) }
							desc={ __(
								'Par défaut, tout est conservé si le plugin est désinstallé.',
								'lumia-staging'
							) }
						>
							<Option
								danger
								htmlFor="lmv-uninstall"
								label={ __(
									'Tout supprimer à la désinstallation',
									'lumia-staging'
								) }
								desc={ __(
									'Versions de travail, sauvegardes, liens d’aperçu, retours clients et journal. Irréversible.',
									'lumia-staging'
								) }
							>
								<Toggle
									id="lmv-uninstall"
									checked={ values.delete_on_uninstall }
									onChange={ onUninstallToggle }
								/>
							</Option>
							{ values.delete_on_uninstall &&
								data.open_versions.length > 0 && (
									<Notice
										status="warning"
										isDismissible={ false }
									>
										<p>
											{ __(
												'Versions ouvertes qui seront supprimées :',
												'lumia-staging'
											) }
										</p>
										<ul className="lmv-bullets">
											{ data.open_versions.map( ( v ) => (
												<li key={ v.id }>
													{ v.title } — { v.state }
												</li>
											) ) }
										</ul>
									</Notice>
								) }
						</Section>

						<footer className="lmv-screen__meta">
							<span className="lmv-chip">
								{ __( 'Bricks', 'lumia-staging' ) }{ ' ' }
								{ data.bricks }
							</span>
							<span className="lmv-chip">
								{ __( 'CSS', 'lumia-staging' ) }{ ' ' }
								{ data.css_mode === 'file'
									? __( 'fichiers externes', 'lumia-staging' )
									: __( 'en ligne', 'lumia-staging' ) }
							</span>
							<span className="lmv-chip">
								{ __( 'Programmation', 'lumia-staging' ) } :{ ' ' }
								{ data.scheduler }
							</span>
						</footer>
					</>
				) }
			</div>
		</form>
	);
}
