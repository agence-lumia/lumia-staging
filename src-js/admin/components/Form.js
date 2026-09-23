/**
 * Primitives de formulaire (langage Studio Kyne) : section, ligne d'option,
 * interrupteur, champ numérique, liste déroulante.
 */

export function Section( { title, desc, children } ) {
	return (
		<section className="lmv-section">
			<header className="lmv-section__header">
				<h2 className="lmv-section__title">{ title }</h2>
				{ desc && <p className="lmv-section__desc">{ desc }</p> }
			</header>
			<div className="lmv-section__content">{ children }</div>
		</section>
	);
}

/**
 * Ligne : libellé et aide à gauche, contrôle à droite.
 *
 * @param {Object}  props
 * @param {string}  props.label     Libellé.
 * @param {string}  [props.desc]    Aide.
 * @param {string}  [props.htmlFor] Id du contrôle.
 * @param {Element} props.children  Contrôle.
 * @param {boolean} [props.danger]  Variante danger.
 */
export function Option( { label, desc, htmlFor, children, danger } ) {
	return (
		<div className={ `lmv-option${ danger ? ' is-danger' : '' }` }>
			<div className="lmv-option__content">
				<label className="lmv-option__label" htmlFor={ htmlFor }>
					{ label }
				</label>
				{ desc && <p className="lmv-option__desc">{ desc }</p> }
			</div>
			<div className="lmv-option__control">{ children }</div>
		</div>
	);
}

export function Toggle( { id, checked, onChange, label, disabled } ) {
	return (
		<span className="lmv-toggle">
			<input
				id={ id }
				type="checkbox"
				role="switch"
				checked={ !! checked }
				disabled={ disabled }
				aria-label={ label }
				onChange={ ( e ) => onChange( e.target.checked ) }
			/>
			<span className="lmv-toggle__slider" aria-hidden="true" />
		</span>
	);
}

export function NumberField( { id, value, onChange, min, max, suffix } ) {
	return (
		<span className="lmv-input-group">
			<input
				id={ id }
				className="lmv-input lmv-input--xs"
				type="number"
				min={ min }
				max={ max }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
			<span className="lmv-input-group__suffix">{ suffix }</span>
		</span>
	);
}

export function SelectField( { id, value, onChange, options } ) {
	return (
		<select
			id={ id }
			className="lmv-select"
			value={ value }
			onChange={ ( e ) => onChange( e.target.value ) }
		>
			{ options.map( ( o ) => (
				<option key={ o.value } value={ o.value }>
					{ o.label }
				</option>
			) ) }
		</select>
	);
}
