/**
 * « Créer une version » depuis la liste des pages/templates et la barre d'admin.
 * La création passe par un POST REST (jamais par un GET), puis ouvre Bricks.
 */
( function () {
	'use strict';
	const cfg = window.lmvActions;
	if ( ! cfg || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	let busy = false;

	function create( postId, trigger ) {
		if ( busy ) {
			return;
		}
		busy = true;
		const label = trigger.textContent;
		trigger.textContent = cfg.creating;
		trigger.setAttribute( 'aria-busy', 'true' );
		window.wp
			.apiFetch( {
				path: '/' + cfg.namespace + '/versions',
				method: 'POST',
				data: { source_id: postId },
			} )
			.then( function ( res ) {
				window.location.href = res.edit_url;
			} )
			.catch( function ( err ) {
				busy = false;
				trigger.textContent = label;
				trigger.removeAttribute( 'aria-busy' );
				// Version déjà ouverte : on la reprend.
				if (
					err &&
					err.code === 'lmv_version_exists' &&
					err.data &&
					err.data.edit_url
				) {
					window.location.href = err.data.edit_url;
					return;
				}
				window.alert( ( err && err.message ) || cfg.error );
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		const target =
			e.target && e.target.closest
				? e.target.closest( '.lmv-create, .lmv-create-node > .ab-item' )
				: null;
		if ( ! target ) {
			return;
		}
		const holder = target.hasAttribute( 'data-post' )
			? target
			: target.parentNode.querySelector( '[data-post]' );
		if ( ! holder ) {
			return;
		}
		e.preventDefault();
		create( parseInt( holder.getAttribute( 'data-post' ), 10 ), target );
	} );
} )();
