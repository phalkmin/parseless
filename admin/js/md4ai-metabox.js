( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn = document.getElementById( 'md4ai-copy-btn' );
		var status = document.getElementById( 'md4ai-copy-status' );

		if ( ! btn || ! status ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var data = new FormData();

			status.textContent = btn.dataset.fetchingText || 'Fetching...';
			data.append( 'action', 'md4ai_preview_markdown' );
			data.append( 'post_id', btn.dataset.postId );
			data.append( 'nonce', btn.dataset.nonce );

			fetch( window.ajaxurl, {
				method: 'POST',
				body: data,
				credentials: 'same-origin',
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( ! json.success ) {
						status.textContent = btn.dataset.errorText || 'Error.';
						return;
					}

					navigator.clipboard.writeText( json.data.markdown ).then( function () {
						status.textContent = btn.dataset.copiedText || 'Copied!';
						setTimeout( function () {
							status.textContent = '';
						}, 2000 );
					} );
				} )
				.catch( function () {
					status.textContent = btn.dataset.errorText || 'Error.';
				} );
		} );
	} );
}() );
