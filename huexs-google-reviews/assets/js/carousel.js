/**
 * Huexs Google Reviews — carrusel accesible y "Leer más".
 * JavaScript nativo, sin dependencias. Sin JS el carrusel degrada a fila desplazable
 * y los comentarios se muestran completos.
 */
( function () {
	'use strict';

	var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function initCarousel( root ) {
		var track = root.querySelector( '[data-hgr-track]' );
		var prev = root.querySelector( '[data-hgr-prev]' );
		var next = root.querySelector( '[data-hgr-next]' );
		if ( ! track || ! prev || ! next ) {
			return;
		}

		prev.hidden = false;
		next.hidden = false;

		function slideWidth() {
			var slide = track.querySelector( '.hgr-carousel__slide' );
			return slide ? slide.getBoundingClientRect().width + 14 : 300;
		}

		function updateButtons() {
			var maxScroll = track.scrollWidth - track.clientWidth - 2;
			prev.disabled = track.scrollLeft <= 2;
			next.disabled = track.scrollLeft >= maxScroll;
		}

		function scrollBySlides( direction ) {
			track.scrollBy( {
				left: direction * slideWidth(),
				behavior: reducedMotion ? 'auto' : 'smooth'
			} );
		}

		prev.addEventListener( 'click', function () {
			scrollBySlides( -1 );
		} );
		next.addEventListener( 'click', function () {
			scrollBySlides( 1 );
		} );

		// Navegación por teclado sobre la pista (sin robar el foco a nadie).
		track.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'ArrowLeft' ) {
				event.preventDefault();
				scrollBySlides( -1 );
			} else if ( event.key === 'ArrowRight' ) {
				event.preventDefault();
				scrollBySlides( 1 );
			}
		} );

		track.addEventListener( 'scroll', updateButtons, { passive: true } );
		window.addEventListener( 'resize', updateButtons );
		updateButtons();
	}

	function initReadMore( comment ) {
		var card = comment.closest( '.hgr-card' );
		var button = card ? card.querySelector( '[data-hgr-more]' ) : null;
		if ( ! button ) {
			return;
		}

		comment.classList.add( 'hgr-is-clamped' );

		// Solo mostramos el botón si el recorte oculta contenido real.
		if ( comment.scrollHeight <= comment.clientHeight + 2 ) {
			comment.classList.remove( 'hgr-is-clamped' );
			return;
		}

		button.hidden = false;
		button.addEventListener( 'click', function () {
			var expanded = button.getAttribute( 'aria-expanded' ) === 'true';
			comment.classList.toggle( 'hgr-is-clamped', expanded );
			button.setAttribute( 'aria-expanded', String( ! expanded ) );
			button.textContent = expanded ? button.dataset.moreLabel || 'Leer más' : button.dataset.lessLabel || 'Leer menos';
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
