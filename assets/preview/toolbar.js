/**
 * Barre d'aperçu client — Lümia Staging.
 * HTML, CSS et JS natifs, isolée dans un Shadow DOM (n'hérite pas du CSS du site).
 * Objectif : < 15 Ko, utilisable dès 360 px de large.
 */
( function () {
	'use strict';
	const cfg = window.lmvPreview;
	if ( ! cfg || customElements.get( 'lmv-preview-bar' ) ) {
		return;
	}
	const t = cfg.i18n;
	const KEY = 'lmv-preview-collapsed';

	function fmt( s, v ) {
		return String( s ).replace( '%s', v );
	}
	function el( tag, attrs, text ) {
		const n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			n.setAttribute( k, attrs[ k ] );
		} );
		if ( text ) {
			n.textContent = text;
		}
		return n;
	}
	function store( v ) {
		try {
			if ( v === undefined ) {
				return window.sessionStorage.getItem( KEY ) === '1';
			}
			window.sessionStorage.setItem( KEY, v ? '1' : '0' );
		} catch ( e ) {}
		return false;
	}

	const CSS =
		':host{all:initial;position:fixed;z-index:2147483646;left:50%;bottom:16px;transform:translateX(-50%);font:14px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d1b2e;' +
		'--a:#5b3df5;--a2:#4a2fe0;--ok:#127a4a;--ko:#a44a00;--bg:#fff;--mut:#6b6880;--line:#e6e4ef;--r:14px}' +
		'*{box-sizing:border-box}' +
		'.bar{display:flex;align-items:center;gap:8px;background:var(--bg);border:1px solid var(--line);border-radius:var(--r);box-shadow:0 8px 30px rgba(20,16,50,.18);padding:8px;max-width:calc(100vw - 24px)}' +
		'.brand{display:flex;align-items:center;gap:8px;padding:0 6px;min-width:0}' +
		'.dot{width:10px;height:10px;border-radius:50%;background:var(--a);flex:none}' +
		'.lbl{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}' +
		'.sub{display:block;font-weight:400;color:var(--mut);font-size:12px}' +
		'.seg{display:flex;background:#f3f2f8;border-radius:10px;padding:3px}' +
		'.seg a{all:unset;cursor:pointer;padding:6px 12px;border-radius:8px;color:var(--mut);font-weight:500}' +
		'.seg a[aria-current=true]{background:#fff;color:#1d1b2e;box-shadow:0 1px 3px rgba(0,0,0,.12)}' +
		'button{all:unset;cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;font-weight:600;white-space:nowrap}' +
		'.ok{background:var(--a);color:#fff}.ok:hover{background:var(--a2)}' +
		'.ghost{color:#1d1b2e;background:#f3f2f8}.ghost:hover{background:#e9e7f3}' +
		'.icon{padding:8px;color:var(--mut)}' +
		'a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible{outline:3px solid var(--a);outline-offset:2px}' +
		'.status{font-size:12px;padding:4px 8px;border-radius:8px;white-space:nowrap}' +
		'.s-approve{background:#e5f6ee;color:var(--ok)}.s-changes{background:#fff1e0;color:var(--ko)}' +
		'.pill{all:unset;cursor:pointer;display:flex;align-items:center;gap:8px;background:var(--a);color:#fff;border-radius:999px;padding:10px 14px;box-shadow:0 8px 30px rgba(20,16,50,.25);font-weight:600}' +
		'form{position:absolute;bottom:calc(100% + 8px);left:0;right:0;background:#fff;border:1px solid var(--line);border-radius:var(--r);box-shadow:0 8px 30px rgba(20,16,50,.18);padding:14px;display:grid;gap:10px}' +
		'label{display:grid;gap:4px;font-weight:500;font-size:13px}' +
		'input,textarea{all:unset;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:inherit;background:#fff}' +
		'textarea{min-height:90px;white-space:pre-wrap}' +
		'.row{display:flex;gap:8px;justify-content:flex-end}' +
		'.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}' +
		'.msg{font-size:13px;color:var(--ko)}' +
		'.toast{position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:#1d1b2e;color:#fff;padding:8px 12px;border-radius:8px;white-space:nowrap;font-size:13px}' +
		'@media (max-width:640px){:host{left:8px;right:8px;bottom:8px;transform:none}.bar{flex-wrap:wrap;justify-content:space-between}.brand{flex:1 1 100%}.lbl{max-width:none}.actions{display:flex;gap:6px;flex:1;justify-content:flex-end}}' +
		'@media (prefers-reduced-motion:no-preference){.bar,form,.pill{animation:in .18s ease-out}@keyframes in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}}';

	const Bar = function () {
		return Reflect.construct( HTMLElement, [], Bar );
	};
	Bar.prototype = Object.create( HTMLElement.prototype );
	Bar.prototype.constructor = Bar;
	Object.setPrototypeOf( Bar, HTMLElement );

	Bar.prototype.connectedCallback = function () {
		this.root = this.attachShadow( { mode: 'closed' } );
		this.decision = cfg.decision;
		this.render();
	};

	Bar.prototype.render = function () {
		const self = this;
		const root = this.root;
		root.innerHTML = '';
		root.appendChild( el( 'style', {}, CSS ) );

		if ( store() ) {
			const pill = el( 'button', {
				class: 'pill',
				'aria-label': t.expand,
			} );
			pill.appendChild(
				el( 'span', { class: 'dot', style: 'background:#fff' } )
			);
			pill.appendChild(
				document.createTextNode(
					cfg.side === 'before' ? t.before : t.after
				)
			);
			pill.addEventListener( 'click', function () {
				store( false );
				self.render();
			} );
			root.appendChild( pill );
			return;
		}

		const bar = el( 'div', {
			class: 'bar',
			role: 'region',
			'aria-label': t.label,
		} );
		const brand = el( 'div', { class: 'brand' } );
		brand.appendChild(
			el( 'span', { class: 'dot', 'aria-hidden': 'true' } )
		);
		const lbl = el( 'span', { class: 'lbl' }, cfg.title );
		lbl.appendChild(
			el(
				'span',
				{ class: 'sub' },
				cfg.isTemplate ? t.template : t.label
			)
		);
		brand.appendChild( lbl );
		bar.appendChild( brand );

		const seg = el( 'nav', {
			class: 'seg',
			'aria-label': t.before + ' / ' + t.after,
		} );
		const before = el(
			'a',
			{
				href: cfg.beforeUrl,
				'aria-current': cfg.side === 'before' ? 'true' : 'false',
			},
			t.before
		);
		const after = el(
			'a',
			{
				href: cfg.afterUrl,
				'aria-current': cfg.side === 'after' ? 'true' : 'false',
			},
			t.after
		);
		seg.appendChild( before );
		seg.appendChild( after );
		bar.appendChild( seg );

		const actions = el( 'div', { class: 'actions' } );
		if ( this.decision ) {
			const ok = this.decision.decision === 'approve';
			actions.appendChild(
				el(
					'span',
					{ class: 'status ' + ( ok ? 's-approve' : 's-changes' ) },
					fmt( ok ? t.approved : t.requested, this.decision.name )
				)
			);
		}
		const approve = el(
			'button',
			{ class: 'ok', type: 'button' },
			t.approve
		);
		const changes = el(
			'button',
			{ class: 'ghost', type: 'button' },
			t.changes
		);
		approve.addEventListener( 'click', function () {
			self.openForm( 'approve' );
		} );
		changes.addEventListener( 'click', function () {
			self.openForm( 'changes' );
		} );
		actions.appendChild( approve );
		actions.appendChild( changes );
		const collapse = el(
			'button',
			{
				class: 'icon',
				type: 'button',
				'aria-label': t.collapse,
				title: t.collapse,
			},
			'–'
		);
		collapse.addEventListener( 'click', function () {
			store( true );
			self.render();
		} );
		actions.appendChild( collapse );
		bar.appendChild( actions );

		const wrap = el( 'div', { style: 'position:relative' } );
		wrap.appendChild( bar );
		root.appendChild( wrap );
		this.wrap = wrap;
	};

	Bar.prototype.toast = function ( text ) {
		if ( ! this.wrap ) {
			window.alert( text );
			return;
		}
		const n = el( 'div', { class: 'toast', role: 'status' }, text );
		this.wrap.appendChild( n );
		setTimeout( function () {
			n.remove();
		}, 3500 );
	};

	Bar.prototype.openForm = function ( decision ) {
		const self = this;
		const old = this.wrap.querySelector( 'form' );
		if ( old ) {
			old.remove();
		}
		const form = el( 'form', { novalidate: '' } );
		const nameLbl = el( 'label', {}, t.name );
		const name = el( 'input', {
			name: 'name',
			maxlength: '100',
			autocomplete: 'name',
			required: '',
		} );
		nameLbl.appendChild( name );
		form.appendChild( nameLbl );

		const comLbl = el(
			'label',
			{},
			decision === 'changes' ? t.commentReq : t.comment
		);
		const comment = el( 'textarea', {
			name: 'comment',
			maxlength: '2000',
		} );
		if ( decision === 'changes' ) {
			comment.setAttribute( 'required', '' );
		}
		comLbl.appendChild( comment );
		form.appendChild( comLbl );

		const hp = el(
			'label',
			{ class: 'hp', 'aria-hidden': 'true' },
			'Website'
		);
		hp.appendChild(
			el( 'input', {
				name: 'website',
				tabindex: '-1',
				autocomplete: 'off',
			} )
		);
		form.appendChild( hp );

		const msg = el( 'div', { class: 'msg', role: 'alert' } );
		form.appendChild( msg );

		const row = el( 'div', { class: 'row' } );
		const cancel = el(
			'button',
			{ class: 'ghost', type: 'button' },
			t.cancel
		);
		const send = el(
			'button',
			{ class: 'ok', type: 'submit' },
			decision === 'approve' ? t.approve : t.send
		);
		cancel.addEventListener( 'click', function () {
			form.remove();
		} );
		row.appendChild( cancel );
		row.appendChild( send );
		form.appendChild( row );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			msg.textContent = '';
			if ( ! name.value.trim() ) {
				msg.textContent = t.name;
				name.focus();
				return;
			}
			if ( decision === 'changes' && ! comment.value.trim() ) {
				msg.textContent = t.commentReq;
				comment.focus();
				return;
			}
			const body = new URLSearchParams();
			body.set( 'lmv_action', 'feedback' );
			body.set( 'lmv_nonce', cfg.nonce );
			body.set( 'decision', decision );
			body.set( 'name', name.value.trim() );
			body.set( 'comment', comment.value.trim() );
			body.set( 'website', form.querySelector( '[name=website]' ).value );
			send.setAttribute( 'aria-busy', 'true' );
			window
				.fetch( cfg.postUrl, {
					method: 'POST',
					body,
					credentials: 'omit',
					lmvFeedback: true,
				} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						throw new Error(
							( res && res.data && res.data.message ) || t.error
						);
					}
					self.decision = {
						decision,
						name: name.value.trim(),
					};
					self.render();
					self.toast(
						decision === 'approve' ? t.thanksOk : t.thanksKo
					);
				} )
				.catch( function ( err ) {
					send.removeAttribute( 'aria-busy' );
					msg.textContent = err.message || t.error;
				} );
		} );

		this.wrap.appendChild( form );
		name.focus();
		form.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				form.remove();
			}
		} );
	};

	customElements.define( 'lmv-preview-bar', Bar );
	function mount() {
		const bar = document.createElement( 'lmv-preview-bar' );
		document.body.appendChild( bar );
		window.lmvToast = function ( m ) {
			bar.toast( m );
		};
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )();
