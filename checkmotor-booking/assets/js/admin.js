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

	/* آزمایش کلید نقشهٔ نشان: همان درخواست سنجشی که SDK نقشه پیش از نمایش می‌فرستد.
	   فقط پاسخ ۲۰۴ یعنی کلید پذیرفته شد؛ هر پاسخ دیگری نقشه را خاکستری می‌کند. */
	var neshanErrors = {
		480: 'کلید در نشان پیدا نشد یا نادرست وارد شده است.',
		481: 'سقف استفادهٔ این کلید پر شده است.',
		482: 'درخواست‌ها از حد مجاز گذشته است؛ کمی بعد دوباره امتحان کنید.',
		483: 'این کلید از نوع «سرویس‌ها» است؛ برای نقشه کلیدی از نوع «نقشهٔ وب» بسازید.',
		484: 'دامنهٔ این سایت در «دامنه‌های مجاز» کلید ثبت نشده است.',
		485: 'سرویس نقشهٔ وب برای این کلید فعال نیست.'
	};

	$( document ).on( 'click', '#cmb-neshan-test', function () {
		var key = String( $( '#cmb-neshan-key' ).val() || '' ).trim();
		var out = $( '#cmb-neshan-result' );
		var say = function ( text, ok ) { out.text( text ).css( 'color', ok ? '#1b7f4b' : ( false === ok ? '#b32d2e' : '' ) ); };

		if ( ! key ) {
			say( 'اول کلید را وارد کنید.', false );
			return;
		}

		say( 'در حال بررسی…' );

		var xhr = new XMLHttpRequest();
		xhr.open( 'PUT', 'https://api.neshan.org/v1/quota?t=' + Date.now() );
		xhr.setRequestHeader( 'Api-Key', key );
		xhr.timeout = 15000;
		xhr.onloadend = function () {
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				say( 'کلید درست است؛ نقشه روی همین دامنه نمایش داده می‌شود. فراموش نکنید تنظیمات را ذخیره کنید.', true );
				return;
			}

			var body = {};
			try { body = JSON.parse( xhr.responseText ) || {}; } catch ( e ) { body = {}; }
			var code = Number( body.code || xhr.status ) || 0;

			say( code
				? ( neshanErrors[ code ] || 'نشان کلید را نپذیرفت (کد ' + code + ( body.message ? '، ' + body.message : '' ) + ').' )
				: 'ارتباط با نشان برقرار نشد؛ اتصال اینترنت را بررسی کنید.', false );
		};
		xhr.send();
	} );

	$( document ).on( 'click', '.cmb-poster-remove', function ( event ) {
		event.preventDefault();

		var field = $( this ).closest( '.cmb-poster-field' );
		field.find( '.cmb-poster-id' ).val( '' );
		field.find( '.cmb-poster-preview' ).empty();
	} );
} );
