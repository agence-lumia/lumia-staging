import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SearchControl,
	SelectControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, config, errorMessage, formatDate } from '../api';
import Skeleton from '../components/Skeleton';

const LEVELS = [
	{ value: '', label: __( 'Tous les niveaux', 'lumia-staging' ) },
	{ value: 'info', label: __( 'Information', 'lumia-staging' ) },
	{ value: 'warning', label: __( 'Alerte', 'lumia-staging' ) },
	{ value: 'error', label: __( 'Erreur', 'lumia-staging' ) },
];

/**
 * Journal d'activité (F10) : consultable, filtrable, exportable en CSV.
 */
export default function Log() {
	const [ page, setPage ] = useState( 1 );
	const [ level, setLevel ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		const t = setTimeout(
			() => {
				const q = new URLSearchParams( {
					page,
					level,
					search,
				} ).toString();
				api( `/log?${ q }` )
					.then( setData )
					.catch( ( e ) => setError( errorMessage( e ) ) );
			},
			search ? 250 : 0
		);
		return () => clearTimeout( t );
	}, [ page, level, search ] );

	const pages = data ? Math.max( 1, Math.ceil( data.total / 50 ) ) : 1;

	return (
		<div className="lmv-screen">
			<header className="lmv-screen__head">
				<h1>{ __( 'Journal', 'lumia-staging' ) }</h1>
				<p className="lmv-muted">
					{ __(
						'Qui a fait quoi, quand, sur quel contenu. Conservé 12 mois par défaut.',
						'lumia-staging'
					) }
				</p>
			</header>
			<div className="lmv-toolbar">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Niveau', 'lumia-staging' ) }
					hideLabelFromVision
					value={ level }
					options={ LEVELS }
					onChange={ ( v ) => {
						setLevel( v );
						setPage( 1 );
					} }
				/>
				<SearchControl
					__nextHasNoMarginBottom
					value={ search }
					onChange={ ( v ) => {
						setSearch( v );
						setPage( 1 );
					} }
					label={ __(
						'Rechercher dans le journal',
						'lumia-staging'
					) }
				/>
				<Button variant="secondary" href={ config.logExport }>
					{ __( 'Exporter en CSV', 'lumia-staging' ) }
				</Button>
			</div>
			{ error && <Notice status="error">{ error }</Notice> }
			{ ! data && ! error && <Skeleton lines={ 8 } /> }
			{ data && data.items.length === 0 && (
				<p className="lmv-empty">
					{ __( 'Aucune entrée.', 'lumia-staging' ) }
				</p>
			) }
			{ data && data.items.length > 0 && (
				<table className="widefat striped lmv-log">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Date', 'lumia-staging' ) }
							</th>
							<th scope="col">
								{ __( 'Utilisateur', 'lumia-staging' ) }
							</th>
							<th scope="col">
								{ __( 'Contenu', 'lumia-staging' ) }
							</th>
							<th scope="col">
								{ __( 'Action', 'lumia-staging' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( row ) => (
							<tr key={ row.id } data-level={ row.level }>
								<td>{ formatDate( row.date ) }</td>
								<td>{ row.user }</td>
								<td>
									{ row.object ||
										( row.object_id
											? `#${ row.object_id }`
											: '—' ) }
								</td>
								<td>
									{ row.level !== 'info' && (
										<span
											className={ `lmv-level is-${ row.level }` }
										>
											{ row.level === 'error'
												? __(
														'Erreur',
														'lumia-staging'
												  )
												: __(
														'Alerte',
														'lumia-staging'
												  ) }
										</span>
									) }{ ' ' }
									{ row.message }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ pages > 1 && (
				<div className="lmv-pagination">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Précédent', 'lumia-staging' ) }
					</Button>
					<span>
						{
							/* translators: 1: page, 2: pages */ sprintf(
								__( 'Page %1$d sur %2$d', 'lumia-staging' ),
								page,
								pages
							)
						}
					</span>
					<Button
						variant="secondary"
						disabled={ page >= pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Suivant', 'lumia-staging' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
