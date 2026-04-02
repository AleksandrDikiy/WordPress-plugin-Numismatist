/**
 * Numismatist – JavaScript фронтенду та адмін-панелі
 *
 * Відповідає за:
 *   - Відображення таблиці монет (AJAX-завантаження, рендер рядків).
 *   - Пагінацію, пошук за назвою, фільтрацію за роком та матеріалом.
 *   - Відкриття/закриття модального вікна редагування.
 *   - CRUD-операції через AJAX: додати, оновити, видалити монету.
 *   - Інтеграцію з медіатекою WordPress (вибір фото).
 *   - Клієнтське екранування виводу (захист від XSS).
 *
 * Залежності: jQuery (вбудований у WordPress), numData (wp_localize_script).
 *
 * @package Numismatist
 */

/* global numData, wp */
( function ( $ ) {
	'use strict';

	// ── SVG-іконки кнопок дій ─────────────────────────────────────────────────

	// Іконка «Редагувати» (олівець)
	const ICON_EDIT = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';

	// Іконка «Видалити» (кошик)
	const ICON_DEL  = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';

	// ── Стан компонента ────────────────────────────────────────────────────────
	const state = {
		page:     1,    // Поточна сторінка пагінації.
		perPage:  10,   // Кількість записів на сторінці.
		search:   '',   // Пошуковий рядок.
		year:     '',   // Фільтр за роком.
		material: '',   // Фільтр за матеріалом.
		total:    0,    // Загальна кількість монет (для пагінації).
		pages:    1,    // Загальна кількість сторінок.
	};

	// true — користувач авторизований і може керувати монетами.
	const canEdit = numData.canEdit === '1';

	// ── DOM-елементи ──────────────────────────────────────────────────────────
	const $tableBody  = $( '#num-table-body' );
	const $search     = $( '#num-search' );
	const $filterYear = $( '#num-filter-year' );
	const $filterMat  = $( '#num-filter-material' );
	const $perPage    = $( '#num-per-page' );
	const $info       = $( '#num-pagination-info' );

	// Кнопки пагінації.
	const $btnFirst = $( '#num-page-first' );
	const $btnPrev  = $( '#num-page-prev' );
	const $btnNext  = $( '#num-page-next' );
	const $btnLast  = $( '#num-page-last' );

	// Модальне вікно (присутнє лише для авторизованих).
	const $overlay    = $( '#num-modal-overlay' );
	const $modalTitle = $( '#num-modal-title' );
	const $formError  = $( '#num-form-error' );

	// Поля форми модального вікна.
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

	const $photoPreview   = $( '#num-photo-preview' );
	const $btnMedia       = $( '#num-btn-media' );
	const $btnMediaRemove = $( '#num-btn-media-remove' );

	// Об'єкт медіатеки WordPress (ледаче ініціалізується при першому кліку).
	let mediaFrame = null;

	// Таймер дебаунсу для поля пошуку.
	let searchTimer = null;

	// Кількість колонок таблиці (7 — з колонкою «Дії», 6 — без неї).
	const colCount = canEdit ? 7 : 6;

	// ── AJAX-хелпер ───────────────────────────────────────────────────────────

	/**
	 * Надсилає AJAX-запит на WordPress admin-ajax.php.
	 * Автоматично додає nonce до параметрів.
	 *
	 * @param {string}   action  Назва дії (wp_ajax_{action}).
	 * @param {Object}   data    Додаткові POST-параметри.
	 * @param {Function} success Колбек при успіху — отримує response.data.
	 * @param {Function} [error] Колбек при помилці — отримує рядок повідомлення.
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
					}
				}
			}
		).fail( function () {
			// Спрацьовує при мережевій помилці (не при success:false).
			if ( typeof error === 'function' ) {
				error( numData.i18n.errorGeneric );
			}
		} );
	}

	// ── Рендер таблиці ────────────────────────────────────────────────────────

	/**
	 * Завантажує список монет з сервера та перемальовує таблицю.
	 * Використовує поточний стан (page, perPage, search, year, material).
	 */
	function loadCoins() {
		$tableBody.html(
			'<tr><td colspan="' + colCount + '" class="num-loading">' +
			escHtml( numData.i18n.loading ) + '</td></tr>'
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
					'<tr><td colspan="' + colCount + '" class="num-loading">' + escHtml( msg ) + '</td></tr>'
				);
			}
		);
	}

	/**
	 * Перебудовує рядки tbody таблиці за отриманими даними.
	 *
	 * @param {Array}  items   Масив об'єктів монет з сервера.
	 * @param {number} page    Поточна сторінка.
	 * @param {number} perPage Кількість записів на сторінці.
	 */
	function renderTable( items, page, perPage ) {
		if ( ! items || ! items.length ) {
			$tableBody.html(
				'<tr><td colspan="' + colCount + '" class="num-empty">' +
				escHtml( numData.i18n.empty ) + '</td></tr>'
			);
			return;
		}

		const offset = ( page - 1 ) * perPage;

		const rows = items.map( function ( coin, i ) {
			const num      = offset + i + 1;
			const name     = escHtml( coin.name );
			const year     = coin.year ? escHtml( String( coin.year ) ) : '—';
			const material = coin.material ? escHtml( coin.material ) : '—';
			const qty      = coin.quantity !== undefined ? escHtml( String( coin.quantity ) ) : '0';

			// Мініатюра фото монети у таблиці.
			const photoHtml = coin.photo
				? '<img src="' + escAttr( coin.photo ) + '" alt="" class="num-table-photo" />'
				: '';

			// Для авторизованих: назва є кліковим посиланням для відкриття форми редагування.
			const nameCell = canEdit
				? '<a class="num-name-link" data-id="' + escAttr( String( coin.id ) ) + '">' + name + '</a>'
				: name;

			// Колонка «Дії» з іконками редагування та видалення (тільки для авторизованих).
			const actionsCell = canEdit
				? '<td class="num-col-actions num-col-actions--center">' +
					'<button class="num-icon-btn num-btn-edit" data-id="' + escAttr( String( coin.id ) ) + '" title="Редагувати">' + ICON_EDIT + '</button>' +
					'<button class="num-icon-btn num-btn-delete" data-id="' + escAttr( String( coin.id ) ) + '" title="Видалити">' + ICON_DEL + '</button>' +
				'</td>'
				: '';

			return (
				'<tr data-id="' + escAttr( String( coin.id ) ) + '">' +
					'<td class="num-col-num">' + num + '</td>' +
					'<td class="num-col-photo">' + photoHtml + '</td>' +
					'<td class="num-col-name">' + nameCell + '</td>' +
					'<td class="num-col-year">' + year + '</td>' +
					'<td class="num-col-material">' + material + '</td>' +
					'<td class="num-col-qty">' + qty + '</td>' +
					actionsCell +
				'</tr>'
			);
		} );

		$tableBody.html( rows.join( '' ) );
	}

	/**
	 * Оновлює інформаційний рядок пагінації та стан кнопок навігації.
	 */
	function renderPagination() {
		$info.text(
			'Сторінка ' + state.page + ' з ' + state.pages +
			' (всього: ' + state.total + ' записів)'
		);
		$btnFirst.prop( 'disabled', state.page <= 1 );
		$btnPrev.prop(  'disabled', state.page <= 1 );
		$btnNext.prop(  'disabled', state.page >= state.pages );
		$btnLast.prop(  'disabled', state.page >= state.pages );
	}

	// ── Оновлення фільтрів ────────────────────────────────────────────────────

	/**
	 * Запитує з сервера актуальні списки років та матеріалів
	 * і перебудовує відповідні select-елементи.
	 * Викликається після додавання, оновлення або видалення монети.
	 */
	function refreshFilters() {
		ajax( 'num_get_filters', {}, function ( data ) {
			rebuildSelect( $filterYear, data.years,     'Всі роки',      state.year );
			rebuildSelect( $filterMat,  data.materials, 'Всі матеріали', state.material );
		} );
	}

	/**
	 * Перебудовує HTML-опції у вказаному select-елементі.
	 *
	 * @param {jQuery} $sel        jQuery-об'єкт select.
	 * @param {Array}  values      Масив значень опцій.
	 * @param {string} placeholder Текст «порожньої» опції (вибрати всі).
	 * @param {string} current     Поточно вибране значення (буде збережено).
	 */
	function rebuildSelect( $sel, values, placeholder, current ) {
		let html = '<option value="">' + escHtml( placeholder ) + '</option>';
		values.forEach( function ( v ) {
			const s = String( v ) === String( current ) ? ' selected' : '';
			html += '<option value="' + escAttr( String( v ) ) + '"' + s + '>' + escHtml( String( v ) ) + '</option>';
		} );
		$sel.html( html );
	}

	// ── Модальне вікно ────────────────────────────────────────────────────────

	/** Відкриває модальне вікно з вказаним заголовком. */
	function openModal( title ) {
		if ( ! $overlay.length ) { return; }
		$modalTitle.text( title );
		$formError.text( '' );
		$overlay.addClass( 'is-open' ).attr( 'aria-hidden', 'false' );
		fields.name.trigger( 'focus' );
	}

	/** Закриває модальне вікно та очищає форму. */
	function closeModal() {
		if ( ! $overlay.length ) { return; }
		$overlay.removeClass( 'is-open' ).attr( 'aria-hidden', 'true' );
		resetForm();
	}

	/** Скидає всі поля форми до початкового стану. */
	function resetForm() {
		Object.values( fields ).forEach( function ( $f ) { $f.val( '' ); } );
		fields.quantity.val( '1' );
		fields.sorting.val( '0' );
		fields.id.val( '0' );
		$photoPreview.addClass( 'hidden' ).attr( 'src', '' );
		$btnMediaRemove.addClass( 'hidden' );
		$formError.text( '' );
	}

	/**
	 * Заповнює форму даними монети, отриманої з сервера.
	 *
	 * @param {Object} coin Об'єкт монети з сервера.
	 */
	function populateForm( coin ) {
		fields.id.val(          coin.id           || '0' );
		fields.name.val(        coin.name          || '' );
		fields.url.val(         coin.url           || '' );
		fields.year.val(        coin.year          || '' );
		fields.material.val(    coin.material      || '' );
		fields.circulation.val( coin.circulation   || '' );
		fields.price.val(       coin.price         || '' );
		fields.quantity.val(    coin.quantity !== undefined ? coin.quantity : '1' );
		fields.notes.val(       coin.notes         || '' );
		fields.sorting.val(     coin.sorting !== undefined ? coin.sorting : '0' );

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
	 * Збирає та повертає значення всіх полів форми.
	 *
	 * @return {Object} Об'єкт з полями монети.
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

	// ── Обробники подій: панель інструментів ──────────────────────────────────

	// Кнопка «ДОДАТИ» — відкриває порожню форму.
	$( '#num-btn-add' ).on( 'click', function () {
		resetForm();
		openModal( 'Нова монета' );
	} );

	// Пошук за назвою з дебаунсом 320 мс.
	$search.on( 'input', function () {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( function () {
			state.search = $search.val().trim();
			state.page   = 1;
			loadCoins();
		}, 320 );
	} );

	// Фільтр за роком.
	$filterYear.on( 'change', function () {
		state.year = $( this ).val();
		state.page = 1;
		loadCoins();
	} );

	// Фільтр за матеріалом.
	$filterMat.on( 'change', function () {
		state.material = $( this ).val();
		state.page     = 1;
		loadCoins();
	} );

	// Зміна кількості записів на сторінці.
	$perPage.on( 'change', function () {
		state.perPage = parseInt( $( this ).val(), 10 );
		state.page    = 1;
		loadCoins();
	} );

	// ── Обробники подій: рядки таблиці ────────────────────────────────────────

	// Клік на назві або іконці олівця — відкриває форму редагування.
	$tableBody.on( 'click', '.num-name-link, .num-btn-edit', function () {
		const id = $( this ).data( 'id' );
		ajax(
			'num_get_coin',
			{ id },
			function ( data ) {
				populateForm( data.coin );
				openModal( 'Редагування: ' + escText( data.coin.name ) );
			},
			function ( msg ) { window.alert( msg ); } // eslint-disable-line no-alert
		);
	} );

	// Клік на іконці кошика — підтвердження та видалення.
	$tableBody.on( 'click', '.num-btn-delete', function () {
		const id = $( this ).data( 'id' );
		if ( ! window.confirm( numData.i18n.confirmDelete ) ) { return; } // eslint-disable-line no-alert
		ajax(
			'num_delete_coin',
			{ id },
			function () { loadCoins(); refreshFilters(); },
			function ( msg ) { window.alert( msg ); } // eslint-disable-line no-alert
		);
	} );

	// ── Обробники подій: модальне вікно ───────────────────────────────────────

	// Кнопка «Зберегти» — валідація та відправка.
	$( '#num-btn-save' ).on( 'click', function () {
		const data = collectForm();
		if ( ! data.name ) {
			$formError.text( 'Поле "Назва" є обов\'язковим.' );
			fields.name.trigger( 'focus' );
			return;
		}
		$formError.text( '' );
		const $btn = $( this );
		$btn.prop( 'disabled', true ).text( 'Збереження…' );

		ajax(
			'num_save_coin',
			data,
			function () {
				closeModal();
				loadCoins();
				refreshFilters();
				$btn.prop( 'disabled', false ).text( 'Зберегти' );
			},
			function ( msg ) {
				$formError.text( msg );
				$btn.prop( 'disabled', false ).text( 'Зберегти' );
			}
		);
	} );

	// Кнопки «Скасувати» та «×» — закриття без збереження.
	$( '#num-btn-cancel, #num-modal-close' ).on( 'click', closeModal );

	// Клік поза межами модального вікна — закриття.
	$overlay.on( 'click', function ( e ) {
		if ( $( e.target ).is( $overlay ) ) { closeModal(); }
	} );

	// Клавіша Escape — закриття модального вікна.
	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && $overlay.hasClass( 'is-open' ) ) { closeModal(); }
	} );

	// ── Медіатека WordPress ───────────────────────────────────────────────────

	// Кнопка «Обрати фото» — відкриває медіатеку WordPress.
	$btnMedia.on( 'click', function () {
		if ( mediaFrame ) { mediaFrame.open(); return; }

		// Ледаче створення об'єкту медіатеки.
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

	// Кнопка «Видалити фото» — очищає поле та приховує прев'ю.
	$btnMediaRemove.on( 'click', function () {
		fields.photo.val( '' );
		$photoPreview.addClass( 'hidden' ).attr( 'src', '' );
		$btnMediaRemove.addClass( 'hidden' );
	} );

	// ── Навігація пагінацією ──────────────────────────────────────────────────
	$btnFirst.on( 'click', function () { if ( state.page > 1 )           { state.page = 1;           loadCoins(); } } );
	$btnPrev.on(  'click', function () { if ( state.page > 1 )           { state.page--;             loadCoins(); } } );
	$btnNext.on(  'click', function () { if ( state.page < state.pages ) { state.page++;             loadCoins(); } } );
	$btnLast.on(  'click', function () { if ( state.page < state.pages ) { state.page = state.pages; loadCoins(); } } );

	// ── Функції безпечного виводу (захист від XSS) ───────────────────────────

	/** Екранує HTML-спецсимволи у рядку. Використовується при вставці в HTML. */
	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

	/** Екранує значення HTML-атрибутів. */
	function escAttr( str ) { return escHtml( str ); }

	/** Повертає рядок без змін (для вставки через .text(), де XSS неможливий). */
	function escText( str ) { return String( str ); }

	// ── Ініціалізація ─────────────────────────────────────────────────────────
	loadCoins();

} )( jQuery );
