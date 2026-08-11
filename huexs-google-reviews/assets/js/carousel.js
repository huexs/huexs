/**
 * Huexs Google Reviews — carrusel accesible y "Leer más".
 *
 * JavaScript nativo, sin dependencias. Sin JS el carrusel degrada a una fila
 * desplazable y los comentarios se muestran completos.
 *
 * El carrusel es cíclico: al llegar al final vuelve al principio, y al revés.
 * El avance automático se detiene al pasar el ratón, al mover el foco dentro,
 * al ocultarse la pestaña y en cuanto el visitante navega a mano.
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

		var pauseButton = root.querySelector( '[data-hgr-pause]' );
		var intervalMs = Math.max( 2, parseInt( root.dataset.autoplaySeconds, 10 ) || 5 ) * 1000;
		// prefers-reduced-motion desactiva el avance automático, no la navegación.
		var autoplayWanted = root.dataset.autoplay === '1' && ! reducedMotion;

		var timer = null;
		var paused = false;

		// La posición se lleva por índice, no leyendo scrollLeft: durante el
		// desplazamiento suave scrollLeft devuelve valores intermedios, y calcular
		// sobre ellos hacía que el salto de vuelta al principio fallara.
		var index = 0;

		function offsetOf( position ) {
			return slides[ position ].offsetLeft - slides[ 0 ].offsetLeft;
		}

		function scrollToIndex( position ) {
			index = ( position + slides.length ) % slides.length;
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

			for ( var i = 0; i < slides.length; i++ ) {
				var distance = Math.abs( offsetOf( i ) - current );
				if ( distance < best ) {
					best = distance;
					closest = i;
				}
			}
			index = closest;
		}

		function startAutoplay() {
			if ( ! autoplayWanted || paused || timer ) {
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

		/** Pausa definitiva, a petición del visitante. */
		function setPaused( value ) {
			paused = value;
			if ( paused ) {
				stopAutoplay();
			} else {
				startAutoplay();
			}
			if ( pauseButton ) {
				pauseButton.setAttribute( 'aria-pressed', String( paused ) );
				pauseButton.textContent = paused
					? pauseButton.dataset.labelPlay
					: pauseButton.dataset.labelPause;
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

		if ( pauseButton && autoplayWanted ) {
			pauseButton.hidden = false;
			pauseButton.addEventListener( 'click', function () {
				setPaused( ! paused );
			} );
		}

		// Se detiene mientras el visitante está leyendo o interactuando.
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
