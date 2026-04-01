/**
 * Numismatist – Admin JavaScript
 *
 * Handles all AJAX calls, table rendering, pagination, filtering,
 * modal management, and WordPress Media Library integration.
 *
 * Depends on: jQuery (WordPress bundled), numData (wp_localize_script).
 *
 * @package Numismatist
 */

/* global numData, wp */
( function ( $ ) {
	'use strict';

	// ── State ──────────────────────────────────────────────────────────────────
	const state = {
		page:     1,
		perPage:  10,
		search:   '',
		year:     '',
		material: '',
		total:    0,
		pages:    1,
	};

	// ── DOM refs ───────────────────────────────────────────────────────────────
	const $body        = $( 'body' );
	const $tableBody   = $( '#num-table-body' );
	const $search      = $( '#num-search' );
	const $filterYear  = $( '#num-filter-year' );
	const $filterMat   = $( '#num-filter-material' );
	const $perPage     = $( '#num-per-page' );
	const $info        = $( '#num-pagination-info' );

	// Pagination buttons.
	const $btnFirst = $( '#num-page-first' );
	const $btnPrev  = $( '#num-page-prev' );
	const $btnNext  = $( '#num-page-next' );
	const $btnLast  = $( '#num-page-last' );

	// Modal.
	const $overlay   = $( '#num-modal-overlay' );
	const $modal     = $overlay.find( '.num-modal' );
	const $modalTitle = $( '#num-modal-title' );
	const $formError = $( '#num-form-error' );

	// Form fields.
	const fields = {
		id:          $( '#num-field-id' ),
		name:        $( '#num-field-name' ),
		url:         $( '#num-field-url' ),
		year:        $( '#num-field-year' ),
		material:    $( '#num-field-material' ),
		circulation: $( '#num-field-circulation' ),
		price:       $( '#num-field-price' ),
		photo:       $( '#num-field-photo' ),
		quantity:    $( '#num-field-quantity' ),
		notes:       $( '#num-field-notes' ),
		sorting:     $( '#num-field-sorting' ),
	};

	const $photoPreview     = $( '#num-photo-preview' );
	const $btnMedia         = $( '#num-btn-media' );
	const $btnMediaRemove   = $( '#num-btn-media-remove' );

	// WordPress media frame (lazy).
	let mediaFrame = null;

	// Search debounce timer.
	let searchTimer = null;

	// ── AJAX helper ────────────────────────────────────────────────────────────
	/**
	 * Thin wrapper around $.post for plugin AJAX calls.
	 *
	 * @param {string}   action   wp_ajax action name.
	 * @param {Object}   data     Extra POST parameters.
	 * @param {Function} success  Called with response.data on success.
	 * @param {Function} [error]  Called with message string on error.
	 */
	function ajax( action, data, success, error ) {
		$.post(
			numData.ajaxUrl,
			Object.assign( { action, nonce: numData.nonce }, data ),
			function ( res ) {
				if ( res && res.success ) {
					success( res.data );
				} else {
					const msg = ( res && res.data && res.data.message )
						? res.data.message
						: numData.i18n.errorGeneric;
					if ( typeof error === 'function' ) {
						error( msg );
					} else {
						// eslint-disable-next-line no-alert
						alert( msg );
					}
				}
			}
		).fail( function () {
			if ( typeof error === 'function' ) {
				error( numData.i18n.errorGeneric );
			}
		} );
	}

	// ── Table rendering ────────────────────────────────────────────────────────
	/**
	 * Fetch coins from the server using current state and rebuild the table.
	 */
	function loadCoins() {
		$tableBody.html(
			'<tr><td colspan="6" class="num-loading">Завантаження…</td></tr>'
		);

		ajax(
			'num_get_coins',
			{
				search:   state.search,
				year:     state.year,
				material: state.material,
				per_page: state.perPage,
				page:     state.page,
			},
			function ( data ) {
				state.total = data.total;
				state.pages = data.total_pages;
				state.page  = data.page;

				renderTable( data.items, data.page, data.per_page );
				renderPagination();
			},
			function ( msg ) {
				$tableBody.html(
					'<tr><td colspan="6" class="num-loading">' + escHtml( msg ) + '</td></tr>'
				);
			}
		);
	}

	/**
	 * Rebuild the <tbody> rows.
	 *
	 * @param {Array}  items    Coin records from server.
	 * @param {number} page     Current page number.
	 * @param {number} perPage  Records per page.
	 */
	function renderTable( items, page, perPage ) {
		if ( ! items || ! items.length ) {
			$tableBody.html(
				'<tr><td colspan="6" class="num-empty">Записи відсутні</td></tr>'
			);
			return;
		}

		const offset = ( page - 1 ) * perPage;
		const rows   = items.map( function ( coin, i ) {
			const num      = offset + i + 1;
			const name     = escHtml( coin.name );
			const year     = coin.year  ? escHtml( String( coin.year ) ) : '—';
			const qty      = coin.quantity !== undefined ? escHtml( String( coin.quantity ) ) : '0';
			const photoHtml = coin.url
				? '<a href="' + escAttr( coin.url ) + '" target="_blank" rel="noopener noreferrer" class="num-photo-link">Фото ↗</a>'
				: '—';

			return (
				'<tr data-id="' + escAttr( String( coin.id ) ) + '">' +
					'<td class="num-col-num">' + num + '</td>' +
					'<td class="num-col-name">' +
						'<a class="num-name-link" data-id="' + escAttr( String( coin.id ) ) + '">' + name + '</a>' +
					'</td>' +
					'<td class="num-col-year">' + year + '</td>' +
					'<td class="num-col-photo">' + photoHtml + '</td>' +
					'<td class="num-col-qty">' + qty + '</td>' +
					'<td class="num-col-actions">' +
						'<button class="button num-btn-edit" data-id="' + escAttr( String( coin.id ) ) + '">Редагувати</button>' +
						'<button class="button num-btn-delete" data-id="' + escAttr( String( coin.id ) ) + '">Видалити</button>' +
					'</td>' +
				'</tr>'
			);
		} );

		$tableBody.html( rows.join( '' ) );
	}

	/**
	 * Update the pagination UI.
	 */
	function renderPagination() {
		const from  = state.total === 0 ? 0 : ( state.page - 1 ) * state.perPage + 1;
		const to    = Math.min( state.page * state.perPage, state.total );
		$info.text(
			'Сторінка ' + state.page + ' з ' + state.pages +
			' (всього: ' + state.total + ' записів)'
		);

		$btnFirst.prop( 'disabled', state.page <= 1 );
		$btnPrev.prop(  'disabled', state.page <= 1 );
		$btnNext.prop(  'disabled', state.page >= state.pages );
		$btnLast.prop(  'disabled', state.page >= state.pages );
	}

	// ── Filter dropdowns refresh ───────────────────────────────────────────────
	function refreshFilters() {
		ajax( 'num_get_filters', {}, function ( data ) {
			rebuildSelect( $filterYear, data.years, 'Всі роки', state.year );
			rebuildSelect( $filterMat,  data.materials, 'Всі матеріали', state.material );
		} );
	}

	function rebuildSelect( $sel, values, placeholder, current ) {
		let html = '<option value="">' + escHtml( placeholder ) + '</option>';
		values.forEach( function ( v ) {
			const s = String( v ) === String( current ) ? ' selected' : '';
			html += '<option value="' + escAttr( String( v ) ) + '"' + s + '>' + escHtml( String( v ) ) + '</option>';
		} );
		$sel.html( html );
	}

	// ── Modal helpers ──────────────────────────────────────────────────────────
	function openModal( title ) {
		$modalTitle.text( title );
		$formError.text( '' );
		$overlay.addClass( 'is-open' ).attr( 'aria-hidden', 'false' );
		$( 'body' ).addClass( 'modal-open' );
		fields.name.trigger( 'focus' );
	}

	function closeModal() {
		$overlay.removeClass( 'is-open' ).attr( 'aria-hidden', 'true' );
		$( 'body' ).removeClass( 'modal-open' );
		resetForm();
	}

	function resetForm() {
		Object.values( fields ).forEach( function ( $f ) {
			$f.val( '' );
		} );
		fields.quantity.val( '1' );
		fields.sorting.val( '0' );
		fields.id.val( '0' );
		$photoPreview.addClass( 'hidden' ).attr( 'src', '' );
		$btnMediaRemove.addClass( 'hidden' );
		$formError.text( '' );
	}

	/**
	 * Populate form with a coin object fetched from the server.
	 *
	 * @param {Object} coin
	 */
	function populateForm( coin ) {
		fields.id.val(          coin.id          || '0' );
		fields.name.val(        coin.name         || '' );
		fields.url.val(         coin.url          || '' );
		fields.year.val(        coin.year         || '' );
		fields.material.val(    coin.material     || '' );
		fields.circulation.val( coin.circulation  || '' );
		fields.price.val(       coin.price        || '' );
		fields.quantity.val(    coin.quantity      !== undefined ? coin.quantity : '1' );
		fields.notes.val(       coin.notes        || '' );
		fields.sorting.val(     coin.sorting      !== undefined ? coin.sorting : '0' );

		if ( coin.photo ) {
			fields.photo.val( coin.photo );
			$photoPreview.attr( 'src', coin.photo ).removeClass( 'hidden' );
			$btnMediaRemove.removeClass( 'hidden' );
		} else {
			fields.photo.val( '' );
			$photoPreview.addClass( 'hidden' ).attr( 'src', '' );
			$btnMediaRemove.addClass( 'hidden' );
		}
	}

	/**
	 * Collect form values into a plain object.
	 *
	 * @return {Object}
	 */
	function collectForm() {
		return {
			id:          fields.id.val(),
			name:        fields.name.val().trim(),
			url:         fields.url.val().trim(),
			year:        fields.year.val(),
			material:    fields.material.val().trim(),
			circulation: fields.circulation.val().trim(),
			price:       fields.price.val(),
			photo:       fields.photo.val(),
			quantity:    fields.quantity.val(),
			notes:       fields.notes.val(),
			sorting:     fields.sorting.val(),
		};
	}

	// ── Event: Add button ──────────────────────────────────────────────────────
	$( '#num-btn-add' ).on( 'click', function () {
		resetForm();
		openModal( 'Нова монета' );
	} );

	// ── Event: Click coin name (view/edit) ─────────────────────────────────────
	$tableBody.on( 'click', '.num-name-link, .num-btn-edit', function () {
		const id = $( this ).data( 'id' );
		ajax(
			'num_get_coin',
			{ id },
			function ( data ) {
				populateForm( data.coin );
				openModal( 'Редагування: ' + escText( data.coin.name ) );
			},
			function ( msg ) {
				// eslint-disable-next-line no-alert
				alert( msg );
			}
		);
	} );

	// ── Event: Delete ──────────────────────────────────────────────────────────
	$tableBody.on( 'click', '.num-btn-delete', function () {
		const id = $( this ).data( 'id' );
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( numData.i18n.confirmDelete ) ) {
			return;
		}
		ajax(
			'num_delete_coin',
			{ id },
			function () {
				loadCoins();
				refreshFilters();
			},
			function ( msg ) {
				// eslint-disable-next-line no-alert
				alert( msg );
			}
		);
	} );

	// ── Event: Save ────────────────────────────────────────────────────────────
	$( '#num-btn-save' ).on( 'click', function () {
		const data = collectForm();

		if ( ! data.name ) {
			$formError.text( 'Поле "Назва" є обов\'язковим.' );
			fields.name.trigger( 'focus' );
			return;
		}
		$formError.text( '' );

		$( this ).prop( 'disabled', true ).text( 'Збереження…' );

		ajax(
			'num_save_coin',
			data,
			function () {
				closeModal();
				loadCoins();
				refreshFilters();
			},
			function ( msg ) {
				$formError.text( msg );
			}
		);

		$( '#num-btn-save' ).prop( 'disabled', false ).text( 'Зберегти' );
	} );

	// ── Event: Close modal ─────────────────────────────────────────────────────
	$( '#num-btn-cancel, #num-modal-close' ).on( 'click', closeModal );

	$overlay.on( 'click', function ( e ) {
		if ( $( e.target ).is( $overlay ) ) {
			closeModal();
		}
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && $overlay.hasClass( 'is-open' ) ) {
			closeModal();
		}
	} );

	// ── Event: Media Library ───────────────────────────────────────────────────
	$btnMedia.on( 'click', function () {
		if ( mediaFrame ) {
			mediaFrame.open();
			return;
		}

		mediaFrame = wp.media( {
			title:    numData.i18n.selectPhoto,
			button:   { text: numData.i18n.usePhoto },
			multiple: false,
			library:  { type: 'image' },
		} );

		mediaFrame.on( 'select', function () {
			const attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
			const url        = attachment.url || '';
			fields.photo.val( url );
			$photoPreview.attr( 'src', url ).removeClass( 'hidden' );
			$btnMediaRemove.removeClass( 'hidden' );
		} );

		mediaFrame.open();
	} );

	$btnMediaRemove.on( 'click', function () {
		fields.photo.val( '' );
		$photoPreview.addClass( 'hidden' ).attr( 'src', '' );
		$btnMediaRemove.addClass( 'hidden' );
	} );

	// ── Event: Search (debounced) ──────────────────────────────────────────────
	$search.on( 'input', function () {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( function () {
			state.search = $search.val().trim();
			state.page   = 1;
			loadCoins();
		}, 320 );
	} );

	// ── Event: Filters ─────────────────────────────────────────────────────────
	$filterYear.on( 'change', function () {
		state.year = $( this ).val();
		state.page = 1;
		loadCoins();
	} );

	$filterMat.on( 'change', function () {
		state.material = $( this ).val();
		state.page     = 1;
		loadCoins();
	} );

	// ── Event: Per-page ────────────────────────────────────────────────────────
	$perPage.on( 'change', function () {
		state.perPage = parseInt( $( this ).val(), 10 );
		state.page    = 1;
		loadCoins();
	} );

	// ── Event: Pagination buttons ──────────────────────────────────────────────
	$btnFirst.on( 'click', function () { if ( state.page > 1 )            { state.page = 1;            loadCoins(); } } );
	$btnPrev.on(  'click', function () { if ( state.page > 1 )            { state.page--;              loadCoins(); } } );
	$btnNext.on(  'click', function () { if ( state.page < state.pages )  { state.page++;              loadCoins(); } } );
	$btnLast.on(  'click', function () { if ( state.page < state.pages )  { state.page = state.pages;  loadCoins(); } } );

	// ── Security helpers ───────────────────────────────────────────────────────
	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}
	function escAttr( str ) { return escHtml( str ); }
	function escText( str ) { return String( str ); } // for modal title (set via .text())

	// ── Init ───────────────────────────────────────────────────────────────────
	loadCoins();

} )( jQuery );
