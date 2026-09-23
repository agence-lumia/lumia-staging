import { useEffect, useState } from '@wordpress/element';
import { ComboboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api } from '../api';

/**
 * Choix d'une page (aperçu d'un template en situation, R7).
 *
 * @param {Object}   props
 * @param {string}   props.label  Libellé.
 * @param {Function} props.onPick Appelé avec { id, title }.
 * @param {string}   [props.help] Aide.
 */
export default function PagePicker( { label, onPick, help } ) {
	const [ search, setSearch ] = useState( '' );
	const [ options, setOptions ] = useState( [] );
	const [ value, setValue ] = useState( null );

	useEffect( () => {
		const timer = setTimeout( () => {
			api( `/pages?search=${ encodeURIComponent( search ) }` ).then(
				( items ) =>
					setOptions(
						items.map( ( p ) => ( {
							value: String( p.id ),
							label: p.title,
						} ) )
					)
			);
		}, 250 );
		return () => clearTimeout( timer );
	}, [ search ] );

	return (
		<ComboboxControl
			label={ label }
			help={ help }
			value={ value }
			options={ options }
			placeholder={ __( 'Rechercher une page…', 'lumia-staging' ) }
			onFilterValueChange={ setSearch }
			onChange={ ( v ) => {
				setValue( v );
				const opt = options.find( ( o ) => o.value === v );
				if ( opt ) {
					onPick( {
						id: parseInt( opt.value, 10 ),
						title: opt.label,
					} );
				}
			} }
		/>
	);
}
