import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, adminUrl, config, errorMessage } from '../api';
import { Alerts } from '../components/PublishModal';
import PagePicker from '../components/PagePicker';
import Skeleton from '../components/Skeleton';
import Icon from '../components/Icon';

function deviceIcon( key ) {
	if ( /mobile|phone/.test( key ) ) {
		return 'phone';
	}
	if ( /tablet/.test( key ) ) {
		return 'tablet';
	}
	return 'monitor';
}

function summaryChips( diff ) {
	if ( ! diff ) {
		return [];
	}
	const chips = [];
	const { added, removed, modified } = diff.elements;
	if ( ! diff.has_changes && ! diff.fields?.length ) {
		chips.push(
			__( 'Aucune différence de mise en page', 'lumia-staging' )
		);
		return chips;
	}
	if ( added ) {
		/* translators: %d: number of elements */
		chips.push( sprintf( __( '+%d ajouté(s)', 'lumia-staging' ), added ) );
	}
	if ( removed ) {
		chips.push(
			/* translators: %d: number of elements */
			sprintf( __( '−%d supprimé(s)', 'lumia-staging' ), removed )
		);
	}
	if ( modified ) {
		chips.push(
			/* translators: %d: number of elements */
			sprintf( __( '%d modifié(s)', 'lumia-staging' ), modified )
		);
	}
	if ( diff.css_changed ) {
		chips.push( __( 'CSS de page', 'lumia-staging' ) );
	}
	if ( diff.settings_changed ) {
		chips.push( __( 'Réglages de page', 'lumia-staging' ) );
	}
	return chips;
}

/**
 * Défilement synchronisé entre deux iframes de même origine.
 * Le panneau « maître » est celui que l'utilisateur manipule (molette,
 * clavier, toucher, barre de défilement) ; l'autre suit en proportion de la
 * hauteur, ce qui reste juste quand la version ajoute ou retire une section.
 *
 * @param {boolean} enabled Synchronisation active.
 * @return {Object} Refs des deux iframes et gestionnaire onLoad.
 */
function useScrollSync( enabled ) {
	const frames = [ useRef( null ), useRef( null ) ];
	const master = useRef( 0 );
	const enabledRef = useRef( enabled );
	enabledRef.current = enabled;

	const follow = useCallback( ( from ) => {
		const to = 1 - from;
		const fw = frames[ from ].current?.contentWindow;
		const tw = frames[ to ].current?.contentWindow;
		if ( ! fw || ! tw || ! enabledRef.current || master.current !== from ) {
			return;
		}
		try {
			const fd = fw.document.documentElement;
			const td = tw.document.documentElement;
			const fMax = Math.max( 1, fd.scrollHeight - fw.innerHeight );
			const tMax = Math.max( 0, td.scrollHeight - tw.innerHeight );
			// « instant » : le site peut imposer scroll-behavior: smooth (réglage
			// Bricks), qui ferait traîner ou annulerait l’alignement.
			tw.scrollTo( {
				top: Math.round( ( fw.scrollY / fMax ) * tMax ),
				behavior: 'instant',
			} );
		} catch ( e ) {
			// Autre origine : pas de synchronisation possible.
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const onLoad = useCallback(
		( index ) => () => {
			const win = frames[ index ].current?.contentWindow;
			if ( ! win ) {
				return;
			}
			try {
				const claim = () => ( master.current = index );
				[
					'wheel',
					'pointerdown',
					'keydown',
					'touchstart',
					'pointermove',
				].forEach( ( ev ) =>
					win.document.addEventListener( ev, claim, {
						passive: true,
						capture: true,
					} )
				);
				win.addEventListener( 'scroll', () => follow( index ), {
					passive: true,
				} );
				// Nouvelle page chargée : on se recale sur l'autre panneau.
				master.current = 1 - index;
				follow( 1 - index );
			} catch ( e ) {
				// Autre origine.
			}
		},
		[ follow ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	return { frames, onLoad };
}

/**
 * Comparatif plein écran (F4) : côte à côte ou superposé avec curseur,
 * largeur alignée sur les points de rupture Bricks du site.
 */
export default function Compare() {
	const { version, snapshot } = config.params;
	const [ onPage, setOnPage ] = useState( config.params.on_page || 0 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ mode, setMode ] = useState( 'side' );
	const [ device, setDevice ] = useState( 'desktop' );
	const [ sync, setSync ] = useState( true );
	const [ split, setSplit ] = useState( 50 );
	const [ size, setSize ] = useState( { w: 1200, h: 800 } );
	const stage = useRef( null );
	const { frames, onLoad } = useScrollSync( sync );

	useEffect( () => {
		const q = new URLSearchParams( {
			version: version || 0,
			snapshot: snapshot || 0,
			on_page: onPage || 0,
		} ).toString();
		setData( null );
		api( `/compare?${ q }` )
			.then( setData )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ version, snapshot, onPage ] );

	useEffect( () => {
		document.body.classList.add( 'lmv-fullscreen' );
		const measure = () =>
			stage.current &&
			setSize( {
				w: stage.current.clientWidth,
				h: stage.current.clientHeight,
			} );
		measure();
		const observer = new window.ResizeObserver( measure );
		if ( stage.current ) {
			observer.observe( stage.current );
		}
		return () => {
			observer.disconnect();
			document.body.classList.remove( 'lmv-fullscreen' );
		};
	}, [ data, mode ] );

	if ( ! version && ! snapshot ) {
		return <p>{ __( 'Rien à comparer.', 'lumia-staging' ) }</p>;
	}

	const breakpoints = data?.breakpoints || [
		{ key: 'desktop', label: __( 'Bureau', 'lumia-staging' ), width: 1440 },
	];
	const bp =
		breakpoints.find( ( b ) => b.key === device ) || breakpoints[ 0 ];
	const gutter = 32;
	const available =
		mode === 'side' ? ( size.w - gutter * 3 ) / 2 : size.w - gutter * 2;
	const scale = Math.min( 1, Math.max( 0.1, available / bp.width ) );
	const viewH = Math.max( 200, size.h - 44 - gutter );
	const frameStyle = {
		width: bp.width,
		height: viewH / scale,
		transform: `scale(${ scale })`,
	};
	const paneStyle = { width: bp.width * scale, height: viewH };
	const back = version
		? adminUrl( 'page=lumia-staging' )
		: adminUrl(
				`page=lumia-staging-history&post=${ data?.source_id || '' }`
		  );

	const frame = ( index, extra = {} ) => (
		<iframe
			ref={ frames[ index ] }
			title={ data.labels[ index ] }
			src={ index === 0 ? data.left : data.right }
			style={ { ...frameStyle, ...extra } }
			onLoad={ onLoad( index ) }
		/>
	);

	return (
		<div className="lmv-compare">
			<header className="lmv-compare__bar">
				<a
					className="lmv-icon-btn"
					href={ back }
					aria-label={ __( 'Fermer le comparatif', 'lumia-staging' ) }
					title={ __( 'Fermer', 'lumia-staging' ) }
				>
					<Icon name="x" />
				</a>
				<div className="lmv-compare__title">
					<strong>{ data?.title || '…' }</strong>
					<span className="lmv-compare__chips">
						{ summaryChips( data?.summary?.diff ).map( ( chip ) => (
							<span className="lmv-chip" key={ chip }>
								{ chip }
							</span>
						) ) }
					</span>
				</div>

				{ data?.is_template && (
					<div className="lmv-compare__picker">
						<PagePicker
							label={ __( 'Aperçu sur', 'lumia-staging' ) }
							onPick={ ( p ) => setOnPage( p.id ) }
						/>
					</div>
				) }

				<div
					className="lmv-segmented"
					role="group"
					aria-label={ __( 'Mode de comparaison', 'lumia-staging' ) }
				>
					<button
						type="button"
						aria-pressed={ mode === 'side' }
						onClick={ () => setMode( 'side' ) }
					>
						<Icon name="columns" size={ 16 } />
						{ __( 'Côte à côte', 'lumia-staging' ) }
					</button>
					<button
						type="button"
						aria-pressed={ mode === 'slider' }
						onClick={ () => setMode( 'slider' ) }
					>
						<Icon name="layers" size={ 16 } />
						{ __( 'Curseur', 'lumia-staging' ) }
					</button>
				</div>

				<div
					className="lmv-segmented"
					role="group"
					aria-label={ __( 'Format d’écran', 'lumia-staging' ) }
				>
					{ breakpoints.map( ( b ) => (
						<button
							type="button"
							key={ b.key }
							aria-pressed={ device === b.key }
							onClick={ () => setDevice( b.key ) }
							title={ `${ b.label } — ${ b.width }px` }
						>
							<Icon name={ deviceIcon( b.key ) } size={ 16 } />
							<span className="lmv-segmented__label">
								{ b.width }
							</span>
						</button>
					) ) }
				</div>

				<label className="lmv-compare__sync" htmlFor="lmv-sync">
					<span className="lmv-toggle">
						<input
							id="lmv-sync"
							type="checkbox"
							role="switch"
							checked={ sync }
							onChange={ ( e ) => setSync( e.target.checked ) }
						/>
						<span
							className="lmv-toggle__slider"
							aria-hidden="true"
						/>
					</span>
					{ __( 'Défilement lié', 'lumia-staging' ) }
				</label>
			</header>

			{ error && (
				<div className="lmv-compare__alerts">
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				</div>
			) }
			{ data?.summary?.alerts?.length > 0 && (
				<div className="lmv-compare__alerts">
					<Alerts alerts={ data.summary.alerts } />
				</div>
			) }

			<div className={ `lmv-compare__stage is-${ mode }` } ref={ stage }>
				{ ! data && ! error && (
					<div className="lmv-compare__loading">
						<Skeleton lines={ 6 } />
					</div>
				) }
				{ data && mode === 'side' && (
					<>
						{ [ 0, 1 ].map( ( i ) => (
							<figure
								className="lmv-compare__pane"
								key={ i }
								style={ { width: paneStyle.width } }
							>
								<figcaption>
									<span
										className={ `lmv-dot is-${
											i === 0 ? 'live' : 'draft'
										}` }
										aria-hidden="true"
									/>
									{ data.labels[ i ] }
									<a
										className="lmv-compare__open"
										href={
											i === 0 ? data.left : data.right
										}
										target="_blank"
										rel="noreferrer"
										aria-label={ __(
											'Ouvrir dans un nouvel onglet',
											'lumia-staging'
										) }
									>
										<Icon name="external" size={ 14 } />
									</a>
								</figcaption>
								<div
									className="lmv-compare__viewport"
									style={ { height: paneStyle.height } }
								>
									{ frame( i ) }
								</div>
							</figure>
						) ) }
					</>
				) }
				{ data && mode === 'slider' && (
					<figure
						className="lmv-compare__pane"
						style={ { width: paneStyle.width } }
					>
						<figcaption>
							<span
								className="lmv-dot is-live"
								aria-hidden="true"
							/>
							{ data.labels[ 0 ] }
							<span className="lmv-compare__vs">/</span>
							<span
								className="lmv-dot is-draft"
								aria-hidden="true"
							/>
							{ data.labels[ 1 ] }
						</figcaption>
						<div
							className="lmv-compare__viewport lmv-compare__overlay"
							style={ { height: paneStyle.height } }
						>
							{ frame( 0 ) }
							{ frame( 1, {
								clipPath: `inset(0 0 0 ${ split }%)`,
							} ) }
							<div
								className="lmv-compare__handle"
								style={ { left: `${ split }%` } }
								aria-hidden="true"
							>
								<span />
							</div>
							<input
								className="lmv-compare__range"
								type="range"
								min="0"
								max="100"
								value={ split }
								onChange={ ( e ) =>
									setSplit( Number( e.target.value ) )
								}
								aria-label={ `${ data.labels[ 0 ] } / ${ data.labels[ 1 ] }` }
							/>
						</div>
					</figure>
				) }
			</div>
		</div>
	);
}
