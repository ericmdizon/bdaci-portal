/* BDACI Portal Access: drag-and-drop and paste for the private Documents uploader. */
( function () {
	'use strict';
	var zone = document.getElementById( 'bdaci-dropzone' );
	var input = document.getElementById( 'bdaci-file' );
	var queue = document.getElementById( 'bdaci-queue' );
	var button = document.getElementById( 'bdaci-upload-btn' );
	if ( ! zone || ! input || ! queue || typeof DataTransfer === 'undefined' ) {
		return; // Plain file input still works.
	}
	var L = window.bdaciUpload || {};
	var OK = [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'txt', 'csv' ];
	var files = [];

	zone.classList.add( 'is-enhanced' );
	input.removeAttribute( 'required' );

	function ext( name ) {
		var m = /\.([a-z0-9]+)$/i.exec( name || '' );
		return m ? m[ 1 ].toLowerCase() : '';
	}
	function size( b ) {
		return b > 1048576 ? ( b / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( b / 1024 ) ) + ' KB';
	}
	function stamp() {
		var d = new Date();
		function p( n ) { return String( n ).padStart( 2, '0' ); }
		return d.getFullYear() + '-' + p( d.getMonth() + 1 ) + '-' + p( d.getDate() ) + ' ' + p( d.getHours() ) + '-' + p( d.getMinutes() ) + '-' + p( d.getSeconds() );
	}
	function sync() {
		var dt = new DataTransfer();
		files.forEach( function ( f ) { dt.items.add( f ); } );
		input.files = dt.files;
		queue.innerHTML = '';
		files.forEach( function ( f, i ) {
			var li = document.createElement( 'li' );
			var e = document.createElement( 'span' );
			e.className = 'bdaci-ext ' + ext( f.name );
			e.textContent = ( ext( f.name ) || 'file' ).toUpperCase();
			var n = document.createElement( 'span' );
			n.className = 'bdaci-name';
			n.textContent = f.name;
			var s = document.createElement( 'span' );
			s.className = 'bdaci-size';
			s.textContent = size( f.size );
			var r = document.createElement( 'button' );
			r.type = 'button';
			r.className = 'button-link';
			r.textContent = L.remove || 'Remove';
			r.addEventListener( 'click', function () { files.splice( i, 1 ); sync(); } );
			li.append( e, n, s, r );
			queue.appendChild( li );
		} );
		if ( button ) {
			button.value = files.length > 1 ? ( L.uploadN || 'Upload %d files' ).replace( '%d', files.length ) : ( L.uploadOne || 'Upload' );
		}
	}
	function add( list, pasted ) {
		var skipped = [];
		Array.prototype.forEach.call( list, function ( f ) {
			var name = f.name;
			if ( pasted && ( ! name || /^image\.(png|jpe?g)$/i.test( name ) ) ) {
				name = ( L.pasted || 'Pasted image' ) + ' ' + stamp() + '.' + ( /jpe?g/.test( f.type ) ? 'jpg' : 'png' );
				f = new File( [ f ], name, { type: f.type } );
			}
			if ( OK.indexOf( ext( name ) ) === -1 || ( L.maxBytes && f.size > L.maxBytes ) ) {
				skipped.push( name || 'file' );
				return;
			}
			files.push( f );
		} );
		sync();
		if ( skipped.length ) {
			window.alert( ( L.skipped || 'Not added:' ) + ' ' + skipped.join( ', ' ) );
		}
	}

	zone.addEventListener( 'click', function ( e ) {
		if ( e.target !== input ) { input.click(); }
	} );
	zone.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); input.click(); }
	} );
	input.addEventListener( 'change', function () {
		var picked = Array.prototype.slice.call( input.files );
		// input.files now holds only the new pick; merge it with what is already queued.
		add( picked, false );
	} );
	[ 'dragenter', 'dragover' ].forEach( function ( type ) {
		zone.addEventListener( type, function ( e ) { e.preventDefault(); zone.classList.add( 'is-over' ); } );
	} );
	zone.addEventListener( 'dragleave', function ( e ) {
		if ( ! zone.contains( e.relatedTarget ) ) { zone.classList.remove( 'is-over' ); }
	} );
	zone.addEventListener( 'drop', function ( e ) {
		e.preventDefault();
		zone.classList.remove( 'is-over' );
		if ( e.dataTransfer && e.dataTransfer.files.length ) { add( e.dataTransfer.files, false ); }
	} );
	// Stop a file dropped just outside the box from opening in the browser tab.
	window.addEventListener( 'dragover', function ( e ) { e.preventDefault(); } );
	window.addEventListener( 'drop', function ( e ) { if ( ! zone.contains( e.target ) ) { e.preventDefault(); } } );
	document.addEventListener( 'paste', function ( e ) {
		var tag = ( e.target.tagName || '' ).toLowerCase();
		var list = e.clipboardData && e.clipboardData.files;
		if ( list && list.length ) {
			e.preventDefault();
			add( list, true );
		} else if ( tag !== 'input' && tag !== 'textarea' && tag !== 'select' ) {
			window.alert( L.nothing || 'Nothing to upload in the clipboard.' );
		}
	} );
} )();
