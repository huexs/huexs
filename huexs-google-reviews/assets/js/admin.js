/**
 * Huexs Google Reviews — administración.
 *
 * - Confirmación de acciones destructivas.
 * - Buscador de negocios en vivo: sugiere mientras escribes.
 *   Sin JavaScript el formulario sigue funcionando con el botón "Buscar".
 */
( function () {
	'use strict';

	// ---- Confirmaciones ----

	document.querySelectorAll( '[data-hgr-confirm]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var message = form.getAttribute( 'data-hgr-confirm' ) || '¿Seguro?';
			if ( ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	} );

	// ---- Buscador en vivo ----

	var MIN_CHARS = 3;
	var DEBOUNCE_MS = 350;

	function initSearch( root ) {
		var input = root.querySelector( '[data-hgr-search-input]' );
		var status = root.querySelector( '[data-hgr-search-status]' );
		var list = root.querySelector( '[data-hgr-search-results]' );
		var form = root.querySelector( 'form' );
		if ( ! input || ! list || ! status ) {
			return;
		}

		var config = root.dataset;
		var timer = null;
		var controller = null;
		var lastQuery = '';

		function setStatus( text, kind ) {
			status.textContent = text || '';
			status.className = 'hgr-search__status' + ( kind ? ' hgr-search__status--' + kind : '' );
		}

		function clearResults() {
			list.innerHTML = '';
			list.hidden = true;
		}

		function renderResults( results ) {
			clearResults();

			if ( ! results.length ) {
				setStatus( config.labelEmpty, 'warn' );
				return;
			}

			setStatus( '' );

			results.forEach( function ( business ) {
				var item = document.createElement( 'li' );
				item.className = 'hgr-search-result';

				var info = document.createElement( 'div' );
				info.className = 'hgr-search-result__info';

				var name = document.createElement( 'strong' );
				name.textContent = business.name;
				info.appendChild( name );

				if ( business.address ) {
					var address = document.createElement( 'span' );
					address.className = 'hgr-search-result__address';
					address.textContent = business.address;
					info.appendChild( address );
				}

				if ( business.rating !== null && business.rating !== undefined ) {
					var rating = document.createElement( 'span' );
					rating.className = 'hgr-search-result__rating';
					rating.textContent =
						String( business.rating ).replace( '.', ',' ) +
						' ★ · ' +
						( business.review_count || 0 ) +
						' ' +
						config.labelReviews;
					info.appendChild( rating );
				}

				item.appendChild( info );
				item.appendChild( buildAddForm( business ) );
				list.appendChild( item );
			} );

			list.hidden = false;
		}

		/** El alta sigue siendo un POST normal con su nonce: la búsqueda no cambia nada. */
		function buildAddForm( business ) {
			var addForm = document.createElement( 'form' );
			addForm.method = 'post';
			addForm.action = config.postUrl;

			[
				[ 'action', 'hgr_add_business' ],
				[ 'hgr_place_id', business.place_id ],
				[ 'hgr_name', business.name ],
				[ 'hgr_address', business.address || '' ],
				[ '_wpnonce', config.addNonce ]
			].forEach( function ( pair ) {
				var field = document.createElement( 'input' );
				field.type = 'hidden';
				field.name = pair[ 0 ];
				field.value = pair[ 1 ];
				addForm.appendChild( field );
			} );

			var button = document.createElement( 'button' );
			button.type = 'submit';
			button.className = 'button button-primary';
			button.textContent = config.labelUse;
			addForm.appendChild( button );

			return addForm;
		}

		function search( query ) {
			// Una búsqueda nueva cancela la anterior: si no, una respuesta lenta
			// podría pisar los resultados de lo último que se ha escrito.
			if ( controller ) {
				controller.abort();
			}
			controller = new AbortController();

			setStatus( config.labelSearching );

			var url =
				config.ajaxUrl +
				'?action=hgr_search_businesses&_wpnonce=' +
				encodeURIComponent( config.searchNonce ) +
				'&q=' +
				encodeURIComponent( query );

			fetch( url, { signal: controller.signal, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( query !== lastQuery ) {
						return; // Respuesta obsoleta.
					}
					if ( payload && payload.success ) {
						renderResults( payload.data.results || [] );
						return;
					}
					clearResults();
					var data = ( payload && payload.data ) || {};
					setStatus(
						[ data.message, data.action ].filter( Boolean ).join( ' ' ) || 'Error',
						'error'
					);
				} )
				.catch( function ( error ) {
					if ( error.name === 'AbortError' ) {
						return;
					}
					clearResults();
					setStatus( 'No se pudo contactar con el servidor.', 'error' );
				} );
		}

		input.addEventListener( 'input', function () {
			var query = input.value.trim();
			lastQuery = query;

			window.clearTimeout( timer );

			if ( query.length < MIN_CHARS ) {
				if ( controller ) {
					controller.abort();
				}
				clearResults();
				setStatus( '' );
				return;
			}

			timer = window.setTimeout( function () {
				search( query );
			}, DEBOUNCE_MS );
		} );

		// Con JavaScript activo el botón no recarga la página: busca al momento.
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var query = input.value.trim();
				if ( query.length < MIN_CHARS ) {
					return;
				}
				event.preventDefault();
				lastQuery = query;
				window.clearTimeout( timer );
				search( query );
			} );
		}
	}

	document.querySelectorAll( '[data-hgr-search]' ).forEach( initSearch );
} )();
