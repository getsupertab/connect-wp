( function () {
	document.querySelectorAll( '.wp-hide-pw' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var input = this.closest( '.wp-pwd' ).querySelector( 'input' );
			var icon = this.querySelector( '.dashicons' );

			if ( 'password' === input.type ) {
				input.type = 'text';
				icon.classList.replace( 'dashicons-visibility', 'dashicons-hidden' );
				this.setAttribute( 'aria-label', this.dataset.hideLabel );
			} else {
				input.type = 'password';
				icon.classList.replace( 'dashicons-hidden', 'dashicons-visibility' );
				this.setAttribute( 'aria-label', this.dataset.showLabel );
			}
		} );
	} );
} )();
