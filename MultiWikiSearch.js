/**
 * JavaScript for MultiWikiSearch extension
 * License: MPLv2.0+
 * (c) 2013, Andrey Krasilnikov, Vitaliy Filippov
 */
jQuery( function( $ ) {

// Emulate HTML5 autofocus behavior in non HTML5 compliant browsers
if ( !( 'autofocus' in document.createElement( 'input' ) ) ) {
	$( 'input[autofocus]:first' ).focus();
}

// Bind check all/none checkbox
var $checkboxes = $('.mws-list input:not(#mws-toggleall)');
$('#mws-toggleall').change( function() {
	if ( $(this).prop("checked") == false ) {
		$checkboxes.prop( "checked", false );
	} else {
		$checkboxes.prop( "checked", true );
	}
} );
$checkboxes.change( function() {
	var y = true;
	$checkboxes.each( function( i, e ) {
		y = y && e.checked;
	} );
	document.getElementById( 'mws-toggleall' ).checked = y;
} );

// Change the header search links to what user entered
var headerLinks = $( '.search-types a' );
$( '#searchTextMultiWiki, #powerSearchTextMultiWiki' ).change( function() {
	var searchterm = $(this).val();
	headerLinks.each( function() {
		var parts = this.href.split( 'multiwikisearch=' );
		var lastpart = '';
		var prefix = 'multiwikisearch=';
		if( parts.length > 1 && parts[1].indexOf('&') >= 0 ) {
			lastpart = parts[1].substring( parts[1].indexOf('&') );
		} else {
			prefix = '&multiwikisearch=';
		}
		this.href = parts[0] + prefix + encodeURIComponent( searchterm ) + lastpart;
	});
}).trigger( 'change' );

} );
