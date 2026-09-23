/**
 * Lümia Staging — interface dans l'éditeur Bricks et sur le front.
 * JavaScript sans framework (pas de conflit avec l'application Vue de Bricks).
 *
 * Modes :
 * - version  : bandeau de la version de travail, Publier / Partager / Comparer ;
 * - original : avertissement « Une version de travail est ouverte » ;
 * - free     : bouton « Créer une version » (builder uniquement).
 */
( function () {
	'use strict';
	const cfg = window.lmvUi;
	if ( ! cfg || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	const api = window.wp.apiFetch;
	const t = cfg.i18n;
	const NS = '/' + cfg.namespace;
	let version = cfg.version;
	const COLLAPSE_KEY = 'lmv-banner-collapsed';

	const ICONS = {
		in_progress: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/>',
		in_review: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		approved: '<path d="M5 12l5 5 9-10"/>',
		scheduled:
			'<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
		conflict: '<path d="M12 3l9 16H3z"/><path d="M12 10v4M12 17v.5"/>',
		link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		columns:
			'<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/>',
		eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
		send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
		more: '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
		down: '<path d="m6 9 6 6 6-6"/>',
		up: '<path d="m18 15-6-6-6 6"/>',
		x: '<path d="M18 6 6 18M6 6l12 12"/>',
		plus: '<path d="M5 12h14M12 5v14"/>',
		info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
		trash: '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		layers: '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
	};
	const CREATE_KEY = 'lmv-create-collapsed';

	/* ------------------------------------------------------------ */
	/* Outils                                                       */
	/* ------------------------------------------------------------ */

	function fmt( s ) {
		const args = Array.prototype.slice.call( arguments, 1 );
		let i = 0;
		return String( s ).replace( /%(\d\$)?[sd]/g, function ( m, pos ) {
			const v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
			return v === undefined ? '' : v;
		} );
	}
	function h( tag, attrs, children ) {
		const n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'on' ) {
				Object.keys( attrs.on ).forEach( function ( ev ) {
					n.addEventListener( ev, attrs.on[ ev ] );
				} );
			} else if ( k === 'html' ) {
				n.innerHTML = attrs.html;
			} else if (
				attrs[ k ] !== false &&
				attrs[ k ] !== null &&
				attrs[ k ] !== undefined
			) {
				n.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
			}
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach(
			function ( c ) {
				if ( c === null || c === undefined || c === false ) {
					return;
				}
				n.appendChild(
					typeof c === 'string' ? document.createTextNode( c ) : c
				);
			}
		);
		return n;
	}
	function icon( name ) {
		return h( 'span', {
			'aria-hidden': 'true',
			html:
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
				( ICONS[ name ] || '' ) +
				'</svg>',
		} );
	}
	function btn( label, onClick, cls, extra ) {
		const attrs = Object.assign(
			{
				type: 'button',
				class: 'lmv-btn ' + ( cls || '' ),
				on: { click: onClick },
			},
			extra || {}
		);
		return h( 'button', attrs, label );
	}
	function formatDate( iso ) {
		try {
			return new Intl.DateTimeFormat(
				document.documentElement.lang || 'fr-FR',
				{
					dateStyle: 'medium',
					timeStyle: 'short',
					timeZone:
						cfg.timezone && cfg.timezone.indexOf( '/' ) > 0
							? cfg.timezone
							: undefined,
				}
			).format( new Date( iso ) );
		} catch ( e ) {
			return iso;
		}
	}
	function store( v, key ) {
		key = key || COLLAPSE_KEY;
		try {
			if ( v === undefined ) {
				return window.localStorage.getItem( key ) === '1';
			}
			window.localStorage.setItem( key, v ? '1' : '0' );
		} catch ( e ) {}
		return false;
	}
	function iconBtn( name, label, onClick, cls ) {
		return h(
			'button',
			{
				type: 'button',
				class: 'lmv-icon-btn ' + ( cls || '' ),
				'aria-label': label,
				title: label,
				on: { click: onClick },
			},
			icon( name )
		);
	}
	function actionBtn( name, label, onClick, cls, extra ) {
		return h(
			'button',
			Object.assign(
				{
					type: 'button',
					class: 'lmv-btn ' + ( cls || 'lmv-btn--ghost' ),
					on: { click: onClick },
				},
				extra || {}
			),
			[ icon( name ), h( 'span', { class: 'lmv-btn__label' }, label ) ]
		);
	}

	/**
	 * Petit menu contextuel ancré sur un bouton.
	 *
	 * @param {Element} anchor Bouton déclencheur.
	 * @param {Array}   items  [{ icon, label, onClick, danger }].
	 */
	function openMenu( anchor, items ) {
		const existing = document.getElementById( 'lmv-menu' );
		if ( existing ) {
			existing.remove();
			return;
		}
		const menu = h( 'div', {
			id: 'lmv-menu',
			class: 'lmv-menu',
			role: 'menu',
		} );
		items.forEach( function ( item ) {
			menu.appendChild(
				h(
					'button',
					{
						type: 'button',
						role: 'menuitem',
						class:
							'lmv-menu__item' +
							( item.danger ? ' is-danger' : '' ),
						on: {
							click() {
								menu.remove();
								item.onClick();
							},
						},
					},
					[ icon( item.icon ), item.label ]
				)
			);
		} );
		root().appendChild( menu );
		const rect = anchor.getBoundingClientRect();
		menu.style.left = Math.max( 8, rect.right - menu.offsetWidth ) + 'px';
		menu.style.top = rect.top - menu.offsetHeight - 8 + 'px';
		anchor.setAttribute( 'aria-expanded', 'true' );
		const first = menu.querySelector( 'button' );
		if ( first ) {
			first.focus();
		}
		function close( e ) {
			if ( e.type === 'keydown' && e.key !== 'Escape' ) {
				return;
			}
			if ( e.type === 'mousedown' && menu.contains( e.target ) ) {
				return;
			}
			menu.remove();
			anchor.setAttribute( 'aria-expanded', 'false' );
			document.removeEventListener( 'mousedown', close, true );
			document.removeEventListener( 'keydown', close, true );
		}
		setTimeout( function () {
			document.addEventListener( 'mousedown', close, true );
			document.addEventListener( 'keydown', close, true );
		} );
	}
	function root() {
		let r = document.getElementById( 'lmv-ui-root' );
		if ( ! r ) {
			r = h( 'div', {
				id: 'lmv-ui-root',
				class:
					'lmv-ui' +
					( cfg.theme === 'dark' ? ' lmv-theme-dark' : '' ),
			} );
			document.body.appendChild( r );
		}
		return r;
	}
	function stateKey( v ) {
		if ( v.conflict || v.orphan ) {
			return 'conflict';
		}
		return v.state;
	}
	function stateLabel( v ) {
		if ( v.orphan ) {
			return t.orphan;
		}
		if ( v.conflict ) {
			return t.conflict;
		}
		if ( v.state === 'scheduled' && v.scheduled_at ) {
			return fmt( t.scheduledOn, formatDate( v.scheduled_at ) );
		}
		if (
			v.state === 'approved' &&
			v.feedback &&
			v.feedback.decision === 'approve'
		) {
			return fmt( t.approvedBy, v.feedback.name );
		}
		return t[ 'state_' + v.state ] || v.state_label;
	}
	function errMessage( e ) {
		return ( e && e.message ) || t.error;
	}

	/* ------------------------------------------------------------ */
	/* Fenêtres et notifications                                    */
	/* ------------------------------------------------------------ */

	let lastFocus = null;
	function dialog( title, build, opts ) {
		opts = opts || {};
		closeDialog();
		lastFocus = document.activeElement;
		const titleId = 'lmv-dialog-title';
		const box = h( 'div', {
			class: 'lmv-dialog',
			role: opts.alert ? 'alertdialog' : 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': titleId,
			tabindex: '-1',
		} );
		box.appendChild( h( 'h2', { id: titleId }, title ) );
		const body = h( 'div' );
		box.appendChild( body );
		const overlay = h(
			'div',
			{ class: 'lmv-overlay', id: 'lmv-overlay' },
			box
		);
		if ( ! opts.blocking ) {
			overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === overlay ) {
					closeDialog();
				}
			} );
		}
		overlay.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! opts.blocking ) {
				e.stopPropagation();
				closeDialog();
			}
			if ( e.key === 'Tab' ) {
				const f = box.querySelectorAll(
					'button, input, textarea, select, a[href]'
				);
				if ( ! f.length ) {
					return;
				}
				if ( e.shiftKey && document.activeElement === f[ 0 ] ) {
					e.preventDefault();
					f[ f.length - 1 ].focus();
				} else if (
					! e.shiftKey &&
					document.activeElement === f[ f.length - 1 ]
				) {
					e.preventDefault();
					f[ 0 ].focus();
				}
			}
		} );
		// Empêche les raccourcis de Bricks pendant la saisie.
		overlay.addEventListener( 'keydown', function ( e ) {
			e.stopPropagation();
		} );
		root().appendChild( overlay );
		build( body, box );
		// Contenu parfois chargé plus tard : le focus va au moins sur la fenêtre.
		const first = box.querySelector( 'input, textarea, button' );
		( first || box ).focus();
		if ( ! opts.blocking ) {
			escHandler = function ( e ) {
				if ( e.key === 'Escape' ) {
					e.stopPropagation();
					closeDialog();
				}
			};
			document.addEventListener( 'keydown', escHandler, true );
		}
		return body;
	}
	let escHandler = null;
	function closeDialog() {
		if ( escHandler ) {
			document.removeEventListener( 'keydown', escHandler, true );
			escHandler = null;
		}
		const o = document.getElementById( 'lmv-overlay' );
		if ( o ) {
			o.remove();
			if ( lastFocus && lastFocus.focus ) {
				lastFocus.focus();
			}
		}
	}
	function toast( text, action, duration ) {
		const old = document.getElementById( 'lmv-toast' );
		if ( old ) {
			old.remove();
		}
		const n = h(
			'div',
			{ class: 'lmv-toast', id: 'lmv-toast', role: 'status' },
			[ h( 'span', {}, text ), action || null ]
		);
		root().appendChild( n );
		setTimeout( function () {
			n.remove();
		}, duration || 5000 );
		return n;
	}
	function skeleton( parent, lines ) {
		for ( let i = 0; i < ( lines || 3 ); i++ ) {
			parent.appendChild(
				h( 'div', {
					class: 'lmv-skeleton',
					style: 'width:' + ( 90 - i * 15 ) + '%',
				} )
			);
		}
	}

	/* ------------------------------------------------------------ */
	/* Bandeau de version                                           */
	/* ------------------------------------------------------------ */

	function renderBanner() {
		const r = root();
		const old = document.getElementById( 'lmv-banner' );
		if ( old ) {
			old.remove();
		}
		if ( ! version ) {
			return;
		}
		const key = stateKey( version );
		if ( store() ) {
			r.appendChild(
				h(
					'button',
					{
						id: 'lmv-banner',
						type: 'button',
						class: 'lmv-pill',
						'data-state': key,
						'aria-label': t.expand,
						title: t.expand,
						on: {
							click() {
								store( false );
								renderBanner();
							},
						},
					},
					[
						h( 'span', { class: 'lmv-pill__state' }, icon( key ) ),
						h(
							'span',
							{ class: 'lmv-pill__label' },
							version.title
						),
						icon( 'up' ),
					]
				)
			);
			return;
		}

		const actions = h( 'div', { class: 'lmv-banner__actions' } );
		if ( cfg.caps.share && ! version.orphan ) {
			actions.appendChild( actionBtn( 'link', t.share, openShare ) );
		}
		actions.appendChild(
			actionBtn(
				'columns',
				t.compare,
				function () {
					window.open( version.compare_url, '_blank', 'noopener' );
				},
				null,
				{
					'aria-keyshortcuts': 'Control+Shift+D',
					title: t.compare + ' (Ctrl/⌘ + Maj + D)',
				}
			)
		);
		if ( version.is_template ) {
			actions.appendChild(
				actionBtn( 'eye', t.previewOn, openPreviewOn )
			);
		}
		if ( cfg.caps.publish && ! version.orphan ) {
			actions.appendChild(
				actionBtn( 'send', t.publish, openPublish, 'lmv-btn--primary', {
					'aria-keyshortcuts': 'Control+Shift+P',
					title: t.publish + ' (Ctrl/⌘ + Maj + P)',
				} )
			);
		}
		const more = iconBtn( 'more', t.more, function () {
			openMenu( more, [
				{
					icon: 'trash',
					label: t.abandon,
					onClick: abandon,
					danger: true,
				},
			] );
		} );
		more.setAttribute( 'aria-haspopup', 'menu' );
		more.setAttribute( 'aria-expanded', 'false' );
		actions.appendChild( more );
		actions.appendChild(
			iconBtn( 'down', t.collapse, function () {
				store( true );
				renderBanner();
			} )
		);

		const banner = h(
			'div',
			{
				id: 'lmv-banner',
				class: 'lmv-banner',
				role: 'region',
				'aria-label': fmt( t.banner, version.title ),
				'data-state': key,
			},
			[
				h( 'div', { class: 'lmv-banner__status' }, [
					h(
						'span',
						{ class: 'lmv-banner__state', 'data-state': key },
						icon( key )
					),
					h( 'div', { class: 'lmv-banner__text' }, [
						h(
							'span',
							{ class: 'lmv-banner__eyebrow', 'data-state': key },
							stateLabel( version )
						),
						h(
							'span',
							{
								class: 'lmv-banner__title',
								title: version.title,
							},
							version.title
						),
					] ),
					h(
						'span',
						{
							class: 'lmv-banner__info',
							tabindex: '0',
							role: 'note',
							'aria-label':
								fmt( t.banner, version.title ) + ' ' + t.tip,
						},
						[
							icon( 'info' ),
							h(
								'span',
								{ class: 'lmv-tooltip', role: 'tooltip' },
								[
									h( 'strong', {}, t.visitorsSee ),
									h(
										'span',
										{},
										version.note ? version.note : t.tip
									),
								]
							),
						]
					),
				] ),
				h( 'span', {
					class: 'lmv-banner__sep',
					'aria-hidden': 'true',
				} ),
				actions,
			]
		);
		r.appendChild( banner );
	}

	function refreshVersion() {
		return api( { path: NS + '/versions/' + version.id } ).then(
			function ( v ) {
				version = v;
				renderBanner();
				return v;
			}
		);
	}

	/* ------------------------------------------------------------ */
	/* Publier / programmer                                         */
	/* ------------------------------------------------------------ */

	function summaryList( s ) {
		const d = s.diff;
		const items = [];
		if ( ! d.has_changes && ( ! d.fields || ! d.fields.length ) ) {
			items.push( h( 'li', {}, t.noChanges ) );
		} else {
			items.push(
				h(
					'li',
					{},
					fmt(
						t.elements,
						d.elements.added,
						d.elements.removed,
						d.elements.modified
					)
				)
			);
			if ( d.css_changed ) {
				items.push( h( 'li', {}, t.cssChanged ) );
			}
			if ( d.settings_changed ) {
				items.push( h( 'li', {}, t.settingsChanged ) );
			}
			if ( d.fields && d.fields.length ) {
				items.push(
					h(
						'li',
						{},
						fmt(
							t.fieldsChanged,
							d.fields
								.map( function ( f ) {
									return t[ 'field_' + f ] || f;
								} )
								.join( ', ' )
						)
					)
				);
			}
		}
		return h( 'ul', { class: 'lmv-summary' }, items );
	}

	function openPublish() {
		if ( ! version || ! cfg.caps.publish ) {
			return;
		}
		dialog( t.publishTitle, function ( body ) {
			skeleton( body, 4 );
			api( { path: NS + '/versions/' + version.id + '/summary' } )
				.then( function ( s ) {
					version = s.version;
					body.innerHTML = '';
					buildPublish( body, s );
				} )
				.catch( function ( e ) {
					body.innerHTML = '';
					body.appendChild(
						h(
							'div',
							{
								class: 'lmv-alert lmv-alert--error',
								role: 'alert',
							},
							errMessage( e )
						)
					);
				} );
		} );
	}

	function buildPublish( body, s ) {
		if ( cfg.context === 'builder' ) {
			body.appendChild( h( 'p', { class: 'lmv-muted' }, t.saveFirst ) );
		}
		body.appendChild( summaryList( s ) );
		s.alerts.forEach( function ( a ) {
			body.appendChild(
				h(
					'div',
					{
						class: 'lmv-alert lmv-alert--' + a.level,
						role: a.level === 'warning' ? 'note' : 'alert',
					},
					a.message
				)
			);
		} );

		const note = h( 'textarea', {
			id: 'lmv-note',
			placeholder: t.notePh,
			maxlength: '500',
		} );
		note.value = version.note || '';
		body.appendChild( h( 'label', { for: 'lmv-note' }, [ t.note, note ] ) );

		const when = h( 'input', { type: 'datetime-local', id: 'lmv-when' } );
		const whenLabel = h( 'label', { for: 'lmv-when', hidden: true }, [
			t.scheduleAt,
			when,
		] );
		body.appendChild( whenLabel );

		const msg = h( 'div', { role: 'alert' } );
		body.appendChild( msg );
		const row = h( 'div', { class: 'lmv-row' } );
		body.appendChild( row );

		function fail( e ) {
			msg.innerHTML = '';
			msg.appendChild(
				h(
					'div',
					{ class: 'lmv-alert lmv-alert--error' },
					errMessage( e )
				)
			);
			row.querySelectorAll( 'button' ).forEach( function ( b ) {
				b.disabled = false;
			} );
		}
		function doPublish( force ) {
			row.querySelectorAll( 'button' ).forEach( function ( b ) {
				b.disabled = true;
			} );
			api( {
				path: NS + '/versions/' + version.id + '/publish',
				method: 'POST',
				data: { note: note.value, force: !! force },
			} )
				.then( published )
				.catch( function ( e ) {
					if ( e && e.code === 'lmv_conflict' ) {
						msg.innerHTML = '';
						msg.appendChild(
							h(
								'div',
								{ class: 'lmv-alert lmv-alert--conflict' },
								errMessage( e )
							)
						);
						row.innerHTML = '';
						row.appendChild( btn( t.cancel, closeDialog ) );
						row.appendChild(
							btn( t.seeDiff, function () {
								window.open(
									version.compare_url,
									'_blank',
									'noopener'
								);
							} )
						);
						row.appendChild(
							btn(
								t.forcePublish,
								function () {
									doPublish( true );
								},
								'lmv-btn--primary'
							)
						);
						return;
					}
					fail( e );
				} );
		}

		row.appendChild( btn( t.cancel, closeDialog ) );
		if ( version.state === 'scheduled' ) {
			row.appendChild(
				btn( t.unschedule, function () {
					api( {
						path: NS + '/versions/' + version.id + '/schedule',
						method: 'DELETE',
					} )
						.then( function ( v ) {
							version = v;
							closeDialog();
							refreshVersion();
						} )
						.catch( fail );
				} )
			);
		}
		var scheduleBtn = btn( t.schedule, function () {
			if ( whenLabel.hidden ) {
				whenLabel.hidden = false;
				when.focus();
				return;
			}
			if ( ! when.value ) {
				when.focus();
				return;
			}
			scheduleBtn.disabled = true;
			api( {
				path: NS + '/versions/' + version.id + '/schedule',
				method: 'POST',
				data: { datetime: when.value, note: note.value },
			} )
				.then( function () {
					closeDialog();
					toast( t.scheduleOk );
					refreshVersion();
				} )
				.catch( fail );
		} );
		const publishBtn = btn(
			t.publishNow,
			function () {
				if ( s.conflict ) {
					doPublish( false );
					return;
				}
				doPublish( false );
			},
			'lmv-btn--primary'
		);
		if ( s.blocking ) {
			scheduleBtn.disabled = true;
			publishBtn.disabled = true;
		}
		row.appendChild( scheduleBtn );
		row.appendChild( publishBtn );
	}

	/**
	 * Publication réussie : la version n'existe plus. On propose « Annuler »
	 * pendant 30 secondes plutôt qu'une confirmation préalable.
	 * @param res
	 */
	function published( res ) {
		version = null;
		stopPolling();
		const old = document.getElementById( 'lmv-banner' );
		if ( old ) {
			old.remove();
		}
		let seconds = 30;
		dialog(
			t.published,
			function ( body ) {
				( res.warnings || [] ).forEach( function ( w ) {
					body.appendChild( h( 'div', { class: 'lmv-alert' }, w ) );
				} );
				var undoBtn = btn( t.undo + ' (' + seconds + ')', function () {
					undoBtn.disabled = true;
					api( {
						path: NS + '/snapshots/' + res.snapshot_id + '/restore',
						method: 'POST',
						data: { confirm: true },
					} )
						.then( function () {
							clearInterval( timer );
							undoBtn.remove();
							body.insertBefore(
								h( 'p', {}, t.undone ),
								body.firstChild
							);
						} )
						.catch( function ( e ) {
							body.appendChild(
								h(
									'div',
									{ class: 'lmv-alert lmv-alert--error' },
									errMessage( e )
								)
							);
						} );
				} );
				var timer = setInterval( function () {
					seconds--;
					undoBtn.textContent = t.undo + ' (' + seconds + ')';
					if ( seconds <= 0 ) {
						clearInterval( timer );
						undoBtn.remove();
					}
				}, 1000 );
				const row = h( 'div', { class: 'lmv-row' }, [
					undoBtn,
					h(
						'a',
						{
							class: 'lmv-btn',
							href: res.live_url,
							target: '_blank',
							rel: 'noopener',
						},
						t.viewLive
					),
					h(
						'a',
						{
							class: 'lmv-btn lmv-btn--primary',
							href:
								cfg.context === 'builder'
									? res.edit_url
									: res.live_url,
						},
						cfg.context === 'builder' ? t.editOriginal : t.close
					),
				] );
				body.appendChild( row );
			},
			{ blocking: true }
		);
	}

	/* ------------------------------------------------------------ */
	/* Partager au client                                           */
	/* ------------------------------------------------------------ */

	function pagePicker( onPick ) {
		const input = h( 'input', {
			type: 'search',
			placeholder: t.pickPage,
			'aria-label': t.pickPage,
			autocomplete: 'off',
		} );
		const list = h( 'ul', {
			class: 'lmv-results',
			role: 'listbox',
			hidden: true,
		} );
		let timer = null;
		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( function () {
				api( {
					path:
						NS +
						'/pages?search=' +
						encodeURIComponent( input.value ),
				} ).then( function ( items ) {
					list.innerHTML = '';
					list.hidden = ! items.length;
					items.forEach( function ( p ) {
						list.appendChild(
							h(
								'li',
								{},
								h(
									'button',
									{
										type: 'button',
										role: 'option',
										on: {
											click() {
												input.value = p.title;
												list.hidden = true;
												onPick( p );
											},
										},
									},
									p.title
								)
							)
						);
					} );
				} );
			}, 250 );
		} );
		return h( 'div', {}, [ input, list ] );
	}

	function openShare() {
		dialog( t.shareTitle, function ( body ) {
			const days = h( 'input', {
				type: 'number',
				min: '1',
				max: '30',
				value: String( cfg.previewDays || 7 ),
				id: 'lmv-days',
			} );
			body.appendChild(
				h( 'label', { for: 'lmv-days' }, [ t.shareDays, days ] )
			);
			let onPage = 0;
			if ( version.is_template ) {
				body.appendChild(
					h( 'label', {}, [
						t.shareOn,
						pagePicker( function ( p ) {
							onPage = p.id;
						} ),
					] )
				);
			}
			const out = h( 'div', { 'aria-live': 'polite' } );
			body.appendChild( out );
			const links = h( 'div' );
			body.appendChild( links );
			renderLinks( links );
			var createBtn = btn(
				t.shareCreate,
				function () {
					createBtn.disabled = true;
					api( {
						path: NS + '/versions/' + version.id + '/share',
						method: 'POST',
						data: {
							days: parseInt( days.value, 10 ) || 7,
							on_page: onPage,
						},
					} )
						.then( function ( res ) {
							version = res.version;
							renderBanner();
							const field = h( 'input', {
								type: 'text',
								readonly: true,
								value: res.url,
								'aria-label': t.share,
							} );
							out.innerHTML = '';
							out.appendChild( field );
							field.select();
							const copied = navigator.clipboard
								? navigator.clipboard.writeText( res.url )
								: Promise.reject();
							copied
								.catch( function () {
									document.execCommand( 'copy' );
								} )
								.then( function () {
									out.appendChild(
										h(
											'p',
											{ class: 'lmv-muted' },
											t.shareCopied
										)
									);
								} );
							renderLinks( links );
							createBtn.disabled = false;
						} )
						.catch( function ( e ) {
							createBtn.disabled = false;
							out.innerHTML = '';
							out.appendChild(
								h(
									'div',
									{ class: 'lmv-alert lmv-alert--error' },
									errMessage( e )
								)
							);
						} );
				},
				'lmv-btn--primary'
			);
			body.appendChild(
				h( 'div', { class: 'lmv-row' }, [
					btn( t.close, closeDialog ),
					createBtn,
				] )
			);
		} );
	}

	function renderLinks( container ) {
		container.innerHTML = '';
		const tokens = ( version && version.tokens ) || [];
		if ( ! tokens.length ) {
			return;
		}
		container.appendChild( h( 'p', { class: 'lmv-muted' }, t.shareLinks ) );
		const ul = h( 'ul', { class: 'lmv-links' } );
		tokens.forEach( function ( tk ) {
			const status = tk.revoked
				? t.revoked
				: tk.expired
				? t.expired
				: fmt( t.expires, formatDate( tk.expires_at ) );
			ul.appendChild(
				h( 'li', {}, [
					h(
						'span',
						{},
						formatDate( tk.created_at ) + ' — ' + status
					),
					tk.revoked || tk.expired
						? null
						: btn(
								t.revoke,
								function () {
									api( {
										path:
											NS +
											'/versions/' +
											version.id +
											'/share/' +
											tk.id,
										method: 'DELETE',
									} ).then( function ( v ) {
										version = v;
										renderLinks( container );
									} );
								},
								'lmv-btn--link'
						  ),
				] )
			);
		} );
		container.appendChild( ul );
	}

	/* ------------------------------------------------------------ */
	/* Aperçu d'un template sur une page (R7)                       */
	/* ------------------------------------------------------------ */

	function openPreviewOn() {
		dialog( t.previewOn, function ( body ) {
			body.appendChild(
				pagePicker( function ( p ) {
					api( {
						path:
							NS +
							'/compare?version=' +
							version.id +
							'&on_page=' +
							p.id,
					} ).then( function ( res ) {
						window.open( res.right, '_blank', 'noopener' );
						closeDialog();
					} );
				} )
			);
			body.appendChild(
				h( 'div', { class: 'lmv-row' }, btn( t.close, closeDialog ) )
			);
		} );
	}

	/* ------------------------------------------------------------ */
	/* Abandonner                                                   */
	/* ------------------------------------------------------------ */

	function abandon() {
		// Action irréversible : confirmation explicite.
		if ( ! window.confirm( t.abandonConfirm ) ) {
			return;
		}
		function send( confirmFlag ) {
			return api( {
				path: NS + '/versions/' + version.id + '/abandon',
				method: 'POST',
				data: { confirm: confirmFlag },
			} );
		}
		send( false )
			.catch( function ( e ) {
				if (
					e &&
					e.code === 'lmv_confirm_required' &&
					window.confirm( e.message )
				) {
					return send( true );
				}
				throw e;
			} )
			.then( function ( res ) {
				if ( ! res ) {
					return;
				}
				stopPolling();
				toast( t.abandoned );
				window.location.href =
					version.live_url ||
					cfg.adminUrl + 'admin.php?page=lumia-staging';
			} )
			.catch( function ( e ) {
				toast( errMessage( e ) );
			} );
	}

	/* ------------------------------------------------------------ */
	/* Surveillance : publication programmée pendant l'édition      */
	/* ------------------------------------------------------------ */

	let pollTimer = null;
	function startPolling() {
		pollTimer = setInterval( function () {
			if ( ! version ) {
				return;
			}
			api( { path: NS + '/versions/' + version.id + '/state' } ).then(
				function ( res ) {
					if ( ! res.exists ) {
						const live = version.live_url;
						version = null;
						stopPolling();
						renderBanner();
						dialog(
							t.publishedAway,
							function ( body ) {
								body.appendChild(
									h( 'div', { class: 'lmv-row' }, [
										h(
											'a',
											{
												class: 'lmv-btn',
												href: live,
												target: '_blank',
												rel: 'noopener',
											},
											t.viewLive
										),
										h(
											'a',
											{
												class: 'lmv-btn lmv-btn--primary',
												href:
													cfg.adminUrl +
													'admin.php?page=lumia-staging',
											},
											t.close
										),
									] )
								);
							},
							{ blocking: true, alert: true }
						);
					} else if ( res.state !== version.state ) {
						refreshVersion();
					}
				}
			);
		}, cfg.poll || 60000 );
	}
	function stopPolling() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	/* ------------------------------------------------------------ */
	/* Original : avertissement, création                          */
	/* ------------------------------------------------------------ */

	function warnOriginal() {
		const open = cfg.openVersion;
		dialog(
			t.openTitle,
			function ( body ) {
				body.appendChild(
					h( 'p', {}, fmt( t.openText, open.author ) )
				);
				body.appendChild(
					h( 'div', { class: 'lmv-row' }, [
						btn( t.editAnyway, closeDialog ),
						h(
							'a',
							{
								class: 'lmv-btn lmv-btn--primary',
								href: open.edit_url,
							},
							t.goVersion
						),
					] )
				);
			},
			{ alert: true }
		);
	}

	/**
	 * Bouton « Créer une version » (pages sans version). Réductible en
	 * pastille ; l'état est mémorisé dans le navigateur.
	 */
	function createButton() {
		const old = document.getElementById( 'lmv-banner' );
		if ( old ) {
			old.remove();
		}
		if ( store( undefined, CREATE_KEY ) ) {
			root().appendChild(
				h(
					'button',
					{
						id: 'lmv-banner',
						type: 'button',
						class: 'lmv-fab',
						'aria-label': t.showCreate,
						title: t.showCreate,
						on: {
							click() {
								store( false, CREATE_KEY );
								createButton();
							},
						},
					},
					icon( 'layers' )
				)
			);
			return;
		}
		const create = actionBtn(
			'plus',
			t.create,
			function () {
				create.disabled = true;
				create.querySelector( '.lmv-btn__label' ).textContent =
					t.creating;
				api( {
					path: NS + '/versions',
					method: 'POST',
					data: { source_id: cfg.postId },
				} )
					.then( function ( res ) {
						window.location.href = res.edit_url;
					} )
					.catch( function ( e ) {
						if ( e && e.code === 'lmv_version_exists' && e.data ) {
							window.location.href = e.data.edit_url;
							return;
						}
						create.disabled = false;
						create.querySelector( '.lmv-btn__label' ).textContent =
							t.create;
						toast( errMessage( e ) );
					} );
			},
			'lmv-btn--primary'
		);
		create.title = t.createHelp;
		root().appendChild(
			h(
				'div',
				{
					id: 'lmv-banner',
					class: 'lmv-create',
					role: 'region',
					'aria-label': t.create,
				},
				[
					create,
					iconBtn( 'x', t.hideCreate, function () {
						store( true, CREATE_KEY );
						createButton();
					} ),
				]
			)
		);
	}

	/* ------------------------------------------------------------ */
	/* Démarrage                                                    */
	/* ------------------------------------------------------------ */

	function start() {
		if ( cfg.mode === 'version' ) {
			renderBanner();
			if ( cfg.context === 'builder' ) {
				startPolling();
				// Raccourcis : Ctrl/Cmd + Maj + P (publier), Ctrl/Cmd + Maj + D (comparer).
				window.addEventListener(
					'keydown',
					function ( e ) {
						if (
							! version ||
							! ( e.ctrlKey || e.metaKey ) ||
							! e.shiftKey
						) {
							return;
						}
						const k = ( e.key || '' ).toLowerCase();
						if ( k === 'p' ) {
							e.preventDefault();
							e.stopPropagation();
							openPublish();
						} else if ( k === 'd' ) {
							e.preventDefault();
							e.stopPropagation();
							window.open(
								version.compare_url,
								'_blank',
								'noopener'
							);
						}
					},
					true
				);
			}
		} else if ( cfg.mode === 'original' && cfg.openVersion ) {
			warnOriginal();
		} else if ( cfg.mode === 'free' && cfg.context === 'builder' ) {
			createButton();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
