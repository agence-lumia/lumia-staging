import { __ } from '@wordpress/i18n';
import { adminUrl, config } from '../api';
import Icon from './Icon';

const ITEMS = [
	{
		key: 'dashboard',
		page: 'lumia-staging',
		icon: 'layers',
		label: __( 'Versions', 'lumia-staging' ),
		desc: __( 'Versions de travail en cours', 'lumia-staging' ),
		cap: 'create',
		also: [ 'history' ],
	},
	{
		key: 'log',
		page: 'lumia-staging-log',
		icon: 'list',
		label: __( 'Journal', 'lumia-staging' ),
		desc: __( 'Qui a fait quoi, et quand', 'lumia-staging' ),
		cap: 'settings',
	},
	{
		key: 'settings',
		page: 'lumia-staging-settings',
		icon: 'settings',
		label: __( 'Réglages', 'lumia-staging' ),
		desc: __( 'Contenus, liens, rétention', 'lumia-staging' ),
		cap: 'settings',
	},
];

/**
 * Coquille des écrans : barre latérale + page en carte.
 *
 * @param {Object}  props
 * @param {string}  props.screen   Écran courant.
 * @param {Element} props.children Contenu de la page.
 */
export default function Shell( { screen, children } ) {
	return (
		<div className="lmv-app">
			<aside className="lmv-sidebar">
				<div className="lmv-sidebar__header">
					<span className="lmv-sidebar__logo" aria-hidden="true">
						<Icon name="layers" size={ 16 } />
					</span>
					<span className="lmv-sidebar__title">Lümia Staging</span>
				</div>
				<nav
					className="lmv-sidebar__nav"
					aria-label={ __( 'Lümia Staging', 'lumia-staging' ) }
				>
					<ul>
						{ ITEMS.filter(
							( item ) => config.caps?.[ item.cap ]
						).map( ( item ) => {
							const active =
								item.key === screen ||
								( item.also || [] ).includes( screen );
							return (
								<li key={ item.key }>
									<a
										className={ `lmv-sidebar__link${
											active ? ' is-active' : ''
										}` }
										href={ adminUrl(
											`page=${ item.page }`
										) }
										aria-current={
											active ? 'page' : undefined
										}
									>
										<span className="lmv-sidebar__icon">
											<Icon name={ item.icon } />
										</span>
										<span className="lmv-sidebar__text">
											<span className="lmv-sidebar__label">
												{ item.label }
											</span>
											<span className="lmv-sidebar__desc">
												{ item.desc }
											</span>
										</span>
									</a>
								</li>
							);
						} ) }
					</ul>
				</nav>
			</aside>
			<main className="lmv-main">{ children }</main>
		</div>
	);
}
