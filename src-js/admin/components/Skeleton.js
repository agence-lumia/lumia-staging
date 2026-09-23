/**
 * Squelette de chargement (pas de spinner plein écran).
 *
 * @param {Object} props
 * @param {number} [props.lines=3] Nombre de lignes.
 */
export default function Skeleton( { lines = 3 } ) {
	return (
		<div className="lmv-skeleton-group" aria-busy="true" aria-live="polite">
			{ Array.from( { length: lines } ).map( ( _, i ) => (
				<div
					key={ i }
					className="lmv-skeleton"
					style={ { width: `${ 92 - i * 14 }%` } }
				/>
			) ) }
		</div>
	);
}
