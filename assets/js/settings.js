( function () {
	// Password visibility toggle.
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

	// Protected paths: toggle visibility when CAP checkbox changes.
	var capCheckbox = document.getElementById( 'supertab-bot-protection-enabled' );
	var pathsSection = document.getElementById( 'supertab-active-paths-section' );

	if ( capCheckbox && pathsSection ) {
		capCheckbox.addEventListener( 'change', function () {
			pathsSection.style.display = this.checked ? '' : 'none';
		} );
	}

	// Protected paths: add/remove rows.
	var addPathBtn = document.getElementById( 'supertab-add-path' );
	var pathsList = document.getElementById( 'supertab-active-paths-list' );

	if ( addPathBtn && pathsList ) {
		addPathBtn.addEventListener( 'click', function () {
			var existingPrefix = pathsList.querySelector( '.supertab-path-prefix' );
			var prefixText = existingPrefix ? existingPrefix.textContent : '';

			var row = document.createElement( 'div' );
			row.className = 'supertab-active-path-row';

			var span = document.createElement( 'span' );
			span.className = 'supertab-path-prefix';
			span.textContent = prefixText;
			row.appendChild( span );

			var placeholders = [ 'sample-post/', 'archives/123', 'archives/*', 'blog/*', 'news/2026/*' ];
			var placeholder = placeholders[ Math.floor( Math.random() * placeholders.length ) ];

			var input = document.createElement( 'input' );
			input.type = 'text';
			input.name = 'active_paths[]';
			input.className = 'regular-text supertab-path-input';
			input.placeholder = placeholder;
			row.appendChild( input );

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button supertab-remove-path';
			btn.setAttribute( 'aria-label', 'Remove path' );

			var icon = document.createElement( 'span' );
			icon.className = 'dashicons dashicons-no-alt';
			icon.setAttribute( 'aria-hidden', 'true' );
			btn.appendChild( icon );
			row.appendChild( btn );

			pathsList.appendChild( row );
		} );
	}

	// Protected paths: remove row (event delegation).
	if ( pathsList ) {
		pathsList.addEventListener( 'click', function ( e ) {
			var removeBtn = e.target.closest( '.supertab-remove-path' );
			if ( ! removeBtn ) {
				return;
			}
			removeBtn.closest( '.supertab-active-path-row' ).remove();
		} );
	}
} )();
