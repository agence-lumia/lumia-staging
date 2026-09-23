import { useEffect, useState } from '@wordpress/element';
import { Button, Modal, Notice, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, config, errorMessage, formatDate } from '../api';
import PagePicker from './PagePicker';

function tokenStatus( token ) {
	if ( token.revoked ) {
		return __( 'révoqué', 'lumia-staging' );
	}
	if ( token.expired ) {
		return __( 'expiré', 'lumia-staging' );
	}
	return sprintf(
		/* translators: %s: date */
		__( 'expire le %s', 'lumia-staging' ),
		formatDate( token.expires_at )
	);
}

/**
 * Partager au client (F3) : lien à durée limitée, révocable.
 *
 * @param {Object}   props
 * @param {Object}   props.version   Version (détaillée ou non).
 * @param {Function} props.onClose   Fermeture.
 * @param {Function} props.onChanged Rafraîchir la liste.
 */
export default function ShareModal( { version: initial, onClose, onChanged } ) {
	const [ version, setVersion ] = useState( initial );
	const [ days, setDays ] = useState( String( config.previewDays || 7 ) );
	const [ onPage, setOnPage ] = useState( 0 );
	const [ link, setLink ] = useState( null );
	const [ copied, setCopied ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		if ( ! initial.tokens ) {
			api( `/versions/${ initial.id }` )
				.then( setVersion )
				.catch( () => {} );
		}
	}, [ initial ] );

	const create = () => {
		setBusy( true );
		setError( null );
		api( `/versions/${ version.id }/share`, {
			method: 'POST',
			data: { days: parseInt( days, 10 ) || 7, on_page: onPage },
		} )
			.then( ( res ) => {
				setLink( res.url );
				setVersion( res.version );
				setBusy( false );
				onChanged();
				if ( window.navigator.clipboard ) {
					window.navigator.clipboard
						.writeText( res.url )
						.then( () => setCopied( true ) );
				}
			} )
			.catch( ( e ) => {
				setBusy( false );
				setError( errorMessage( e ) );
			} );
	};

	const revoke = ( tokenId ) => {
		api( `/versions/${ version.id }/share/${ tokenId }`, {
			method: 'DELETE',
		} ).then( setVersion );
	};

	return (
		<Modal
			title={ __( 'Partager au client', 'lumia-staging' ) }
			onRequestClose={ onClose }
			className="lmv-modal"
		>
			<p>
				{ __(
					"Le client voit la page comme en production, peut comparer avec la version en ligne, valider ou demander des modifications. Aucun compte WordPress n'est nécessaire.",
					'lumia-staging'
				) }
			</p>
			<TextControl
				type="number"
				min={ 1 }
				max={ 30 }
				label={ __(
					'Durée de validité du lien (jours)',
					'lumia-staging'
				) }
				value={ days }
				onChange={ setDays }
			/>
			{ version.is_template && (
				<PagePicker
					label={ __( "Page d'aperçu du template", 'lumia-staging' ) }
					onPick={ ( p ) => setOnPage( p.id ) }
					help={ __(
						"Par défaut : la page d'accueil. Le client peut ensuite naviguer sur le site.",
						'lumia-staging'
					) }
				/>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ link && (
				<div className="lmv-share-link">
					<TextControl
						label={ __( 'Lien client', 'lumia-staging' ) }
						value={ link }
						readOnly
						onFocus={ ( e ) => e.target.select() }
						onChange={ () => {} }
					/>
					<p className="lmv-muted">
						{ copied
							? __(
									'Lien copié dans le presse-papiers.',
									'lumia-staging'
							  ) + ' '
							: '' }
						{ __(
							'Il ne sera plus affiché : gardez-le ou créez-en un autre.',
							'lumia-staging'
						) }
					</p>
				</div>
			) }
			{ version.tokens?.length > 0 && (
				<>
					<h3 className="lmv-subtitle">
						{ __( 'Liens existants', 'lumia-staging' ) }
					</h3>
					<ul className="lmv-links">
						{ version.tokens.map( ( t ) => (
							<li key={ t.id }>
								<span>
									{ formatDate( t.created_at ) } —{ ' ' }
									{ tokenStatus( t ) }
								</span>
								{ ! t.revoked && ! t.expired && (
									<Button
										variant="link"
										isDestructive
										onClick={ () => revoke( t.id ) }
									>
										{ __( 'Révoquer', 'lumia-staging' ) }
									</Button>
								) }
							</li>
						) ) }
					</ul>
				</>
			) }
			{ version.feedbacks?.length > 0 && (
				<>
					<h3 className="lmv-subtitle">
						{ __( 'Retours du client', 'lumia-staging' ) }
					</h3>
					<ul className="lmv-links">
						{ version.feedbacks.map( ( f ) => (
							<li key={ f.id }>
								<span>
									<strong>{ f.name }</strong> —{ ' ' }
									{ f.decision === 'approve'
										? __( 'a validé', 'lumia-staging' )
										: __(
												'demande des modifications',
												'lumia-staging'
										  ) }{ ' ' }
									({ formatDate( f.date ) })
									{ f.comment && (
										<blockquote>{ f.comment }</blockquote>
									) }
								</span>
							</li>
						) ) }
					</ul>
				</>
			) }
			<div className="lmv-modal__actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Fermer', 'lumia-staging' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ create }
				>
					{ __( 'Créer le lien', 'lumia-staging' ) }
				</Button>
			</div>
		</Modal>
	);
}
