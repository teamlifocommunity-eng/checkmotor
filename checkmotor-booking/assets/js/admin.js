/**
 * اسکریپت پنل مدیریت — انتخاب پوستر خدمات از کتابخانه‌ی رسانه.
 */
jQuery( function ( $ ) {
	'use strict';

	var frame = null;

	$( document ).on( 'click', '.cmb-poster-select', function ( event ) {
		event.preventDefault();

		var field = $( this ).closest( '.cmb-poster-field' );

		frame = wp.media( {
			title: 'انتخاب پوستر خدمت',
			button: { text: 'استفاده از این تصویر' },
			library: { type: 'image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;

			field.find( '.cmb-poster-id' ).val( attachment.id );
			field.find( '.cmb-poster-preview' ).html( '<img src="' + url + '" alt="" />' );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.cmb-poster-remove', function ( event ) {
		event.preventDefault();

		var field = $( this ).closest( '.cmb-poster-field' );
		field.find( '.cmb-poster-id' ).val( '' );
		field.find( '.cmb-poster-preview' ).empty();
	} );
} );
