/**
 * Huexs Google Reviews — carrusel accesible y "Leer más".
 *
 * JavaScript nativo, sin dependencias. Sin JS el carrusel degrada a una fila
 * desplazable y los comentarios se muestran completos.
 *
 * El carrusel es cíclico: al llegar al final vuelve al principio, y al revés.
 * No lleva botón de pausa: avanza y ya está. Se detiene solo, sin controles a la
 * vista, al pasar el ratón, al mover el foco dentro, al tocar la pantalla y
 * mientras la pestaña esté oculta.
 */
( function () {
	'use strict';

	var reducedMotion =
		window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function initCarousel( root ) {
		var track = root.querySelector( '[data-hgr-track]' );
		var prev = root.querySelector( '[data-hgr-prev]' );
		var next = root.querySelector( '[data-hgr-next]' );
		if ( ! track || ! prev || ! next ) {
			return;
		}

		var slides = track.querySelectorAll( '.hgr-carousel__slide' );
		if ( slides.length < 2 ) {
			return; // Con una sola reseña no hay nada que recorrer.
		}

		prev.hidden = false;
		next.hidden = false;

		var intervalMs = Math.max( 2, parseInt( root.dataset.autoplaySeconds, 10 ) || 5 ) * 1000;
		// prefers-reduced-motion desactiva el avance automático, no la navegación.
		var autoplayWanted = root.dataset.autoplay === '1' && ! reducedMotion;

		var timer = null;

		// La posición se lleva por índice, no leyendo scrollLeft: durante el
		// desplazamiento suave scrollLeft devuelve valores intermedios, y calcular
		// sobre ellos hacía que el salto de vuelta al principio fallara.
		var index = 0;

		function maxScroll() {
			return Math.max( 0, track.scrollWidth - track.clientWidth );
		}

		function rawOffset( position ) {
			return slides[ position ].offsetLeft - slides[ 0 ].offsetLeft;
		}

		/**
		 * Última tarjeta con parada propia. Cuando la pista llega a su tope, las
		 * tarjetas siguientes ya están a la vista, así que pedir su posición no
		 * mueve nada: el avance automático se quedaba clavado un par de ciclos al
		 * final antes de volver al principio. El ciclo va de 0 a esta posición.
		 */
		function lastIndex() {
			var limit = maxScroll();
			for ( var i = 0; i < slides.length; i++ ) {
				if ( rawOffset( i ) >= limit ) {
					return i;
				}
			}
			return slides.length - 1;
		}

		function offsetOf( position ) {
			return Math.min( rawOffset( position ), maxScroll() );
		}

		function scrollToIndex( position ) {
			var stops = lastIndex() + 1;
			index = ( ( position % stops ) + stops ) % stops;
			track.scrollTo( {
				left: offsetOf( index ),
				behavior: reducedMotion ? 'auto' : 'smooth'
			} );
		}

		/** Avanza en la dirección indicada, dando la vuelta en los extremos. */
		function move( direction ) {
			scrollToIndex( index + direction );
		}

		/** Si el visitante arrastra a mano, el índice se recalcula por proximidad. */
		function syncIndexFromScroll() {
			var current = track.scrollLeft;
			var closest = 0;
			var best = Infinity;
			var stops = lastIndex() + 1;

			for ( var i = 0; i < stops; i++ ) {
				var distance = Math.abs( offsetOf( i ) - current );
				if ( distance < best ) {
					best = distance;
					closest = i;
				}
			}
			index = closest;
		}

		function startAutoplay() {
			if ( ! autoplayWanted || timer ) {
				return;
			}
			timer = window.setInterval( function () {
				move( 1 );
			}, intervalMs );
		}

		function stopAutoplay() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		prev.addEventListener( 'click', function () {
			move( -1 );
		} );
		next.addEventListener( 'click', function () {
			move( 1 );
		} );

		track.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'ArrowLeft' ) {
				event.preventDefault();
				move( -1 );
			} else if ( event.key === 'ArrowRight' ) {
				event.preventDefault();
				move( 1 );
			}
		} );

		// No hay botón de pausa: el carrusel avanza y ya está. Se detiene solo,
		// sin controles a la vista, mientras el visitante está leyendo.
		root.addEventListener( 'mouseenter', stopAutoplay );
		root.addEventListener( 'mouseleave', startAutoplay );
		root.addEventListener( 'focusin', stopAutoplay );
		root.addEventListener( 'focusout', function ( event ) {
			if ( ! root.contains( event.relatedTarget ) ) {
				startAutoplay();
			}
		} );
		track.addEventListener( 'touchstart', stopAutoplay, { passive: true } );

		// Al soltar tras un arrastre manual, se recupera el índice real.
		track.addEventListener( 'scrollend', syncIndexFromScroll );

		// Un temporizador corriendo en una pestaña oculta no aporta nada.
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				stopAutoplay();
			} else {
				startAutoplay();
			}
		} );

		startAutoplay();
	}

	function initReadMore( comment ) {
		var card = comment.closest( '.hgr-card' );
		var button = card ? card.querySelector( '[data-hgr-more]' ) : null;
		if ( ! button ) {
			return;
		}

		comment.classList.add( 'hgr-is-clamped' );

		// Solo se ofrece el botón si el recorte oculta contenido real.
		if ( comment.scrollHeight <= comment.clientHeight + 2 ) {
			comment.classList.remove( 'hgr-is-clamped' );
			return;
		}

		button.hidden = false;
		button.addEventListener( 'click', function () {
			var expanded = button.getAttribute( 'aria-expanded' ) === 'true';
			comment.classList.toggle( 'hgr-is-clamped', expanded );
			button.setAttribute( 'aria-expanded', String( ! expanded ) );
			button.textContent = expanded
				? button.dataset.moreLabel || 'Leer más'
				: button.dataset.lessLabel || 'Leer menos';
		} );
	}

	function init() {
		document.querySelectorAll( '[data-hgr-carousel]' ).forEach( initCarousel );
		document.querySelectorAll( '[data-hgr-clamp]' ).forEach( initReadMore );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
