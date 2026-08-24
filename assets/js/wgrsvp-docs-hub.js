/**
 * Documentation hub modal (markdown viewer).
 */
( function ( $ ) {
	'use strict';

	if ( ! $ || ! window.wgrsvpDocsHub ) {
		return;
	}

	var cfg = window.wgrsvpDocsHub;
	var docContents = cfg.contents || {};
	var docTitles = cfg.titles || {};
	var blockDocTitles = cfg.blockTitles || {};
	var notFound = cfg.notFound || 'Documentation not found.';
	var autoDoc = cfg.autoDoc || '';

	function escapeHtml( text ) {
		return String( text )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function renderMarkdown( content ) {
		if ( ! content ) {
			return '<p>' + escapeHtml( notFound ) + '</p>';
		}

		var lines = String( content ).replace( /\r\n/g, '\n' ).split( '\n' );
		var html = '';
		var inCode = false;
		var codeBuffer = [];
		var listOpen = false;
		var inTable = false;

		function closeList() {
			if ( listOpen ) {
				html += '</ul>';
				listOpen = false;
			}
		}

		function closeTable() {
			if ( inTable ) {
				html += '</table>';
				inTable = false;
			}
		}

		function inlineFormat( text ) {
			var safe = escapeHtml( text );
			safe = safe.replace( /`([^`]+)`/g, '<code>$1</code>' );
			safe = safe.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
			safe = safe.replace( /\[([^\]]+)\]\(([^)]+\.md)\)/g, function ( m, label, file ) {
				return '<a href="#" class="wgrsvp-doc-link" data-doc="' + escapeHtml( file ) + '">' + label + '</a>';
			} );
			return safe;
		}

		lines.forEach( function ( line ) {
			if ( /^```/.test( line ) ) {
				closeList();
				closeTable();
				if ( ! inCode ) {
					inCode = true;
					codeBuffer = [];
				} else {
					html += '<pre><code>' + escapeHtml( codeBuffer.join( '\n' ) ) + '</code></pre>';
					inCode = false;
				}
				return;
			}

			if ( inCode ) {
				codeBuffer.push( line );
				return;
			}

			if ( /^\s*$/.test( line ) ) {
				closeList();
				closeTable();
				return;
			}

			if ( /^---+$/.test( line.trim() ) ) {
				closeList();
				closeTable();
				html += '<hr />';
				return;
			}

			if ( /^# /.test( line ) ) {
				closeList();
				closeTable();
				html += '<h1>' + inlineFormat( line.replace( /^# /, '' ) ) + '</h1>';
				return;
			}

			if ( /^## /.test( line ) ) {
				closeList();
				closeTable();
				html += '<h2>' + inlineFormat( line.replace( /^## /, '' ) ) + '</h2>';
				return;
			}

			if ( /^### /.test( line ) ) {
				closeList();
				closeTable();
				html += '<h3>' + inlineFormat( line.replace( /^### /, '' ) ) + '</h3>';
				return;
			}

			if ( /^\|/.test( line ) ) {
				closeList();
				if ( /^\|[\s\-:|]+\|$/.test( line.replace( /\s/g, '' ) ) ) {
					return;
				}
				if ( ! inTable ) {
					html += '<table>';
					inTable = true;
				}
				var cells = line.split( '|' ).slice( 1, -1 );
				html +=
					'<tr>' +
					cells
						.map( function ( cell ) {
							return '<td>' + inlineFormat( cell.trim() ) + '</td>';
						} )
						.join( '' ) +
					'</tr>';
				return;
			}

			if ( /^[-*] /.test( line ) ) {
				closeTable();
				if ( ! listOpen ) {
					html += '<ul>';
					listOpen = true;
				}
				html += '<li>' + inlineFormat( line.replace( /^[-*] /, '' ) ) + '</li>';
				return;
			}

			closeList();
			closeTable();
			html += '<p>' + inlineFormat( line ) + '</p>';
		} );

		closeList();
		closeTable();
		if ( inCode && codeBuffer.length ) {
			html += '<pre><code>' + escapeHtml( codeBuffer.join( '\n' ) ) + '</code></pre>';
		}

		return html;
	}

	function docTitleFor( file, fallback ) {
		return blockDocTitles[ file ] || docTitles[ file ] || fallback || file;
	}

	function openDocModal( docFile, title ) {
		var content = docContents[ docFile ];
		if ( ! content ) {
			return;
		}
		$( '#wgrsvp-modal-title' ).text( docTitleFor( docFile, title ) );
		$( '#wgrsvp-modal-content' ).html( renderMarkdown( content ) );
		$( '#wgrsvp-doc-modal' ).removeAttr( 'hidden' ).fadeIn();
	}

	$( function () {
		$( '.wgrsvp-view-doc-btn' ).on( 'click', function () {
			var $btn = $( this );
			openDocModal( $btn.data( 'doc' ), $btn.closest( '.wgrsvp-doc-card' ).find( 'h2' ).text() );
		} );

		$( document ).on( 'click', '.wgrsvp-doc-link', function ( e ) {
			e.preventDefault();
			openDocModal( $( this ).data( 'doc' ), $( this ).text() );
		} );

		$( '.wgrsvp-modal-close, #wgrsvp-doc-modal' ).on( 'click', function ( e ) {
			if ( e.target === this ) {
				$( '#wgrsvp-doc-modal' ).fadeOut( function () {
					$( this ).attr( 'hidden', 'hidden' );
				} );
			}
		} );

		if ( autoDoc && docContents[ autoDoc ] ) {
			openDocModal( autoDoc, '' );
		}
	} );
} )( window.jQuery );
