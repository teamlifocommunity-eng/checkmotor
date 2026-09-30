/**
 * چک موتور — اسکریپت فرم رزرو نوبت.
 * بدون وابستگی به jQuery.
 */
( function () {
	'use strict';

	var CFG = window.CMB_DATA || {};
	var I18N = CFG.i18n || {};

	/* ---------------------------------------------------------------
	 * ابزارهای عمومی
	 * ------------------------------------------------------------ */

	function $( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function $$( selector, scope ) {
		return Array.prototype.slice.call( ( scope || document ).querySelectorAll( selector ) );
	}

	function toEnDigits( value ) {
		return String( value )
			.replace( /[۰-۹]/g, function ( c ) {
				return String.fromCharCode( c.charCodeAt( 0 ) - 1728 );
			} )
			.replace( /[٠-٩]/g, function ( c ) {
				return String.fromCharCode( c.charCodeAt( 0 ) - 1584 );
			} );
	}

	function toFaDigits( value ) {
		return String( value ).replace( /[0-9]/g, function ( d ) {
			return String.fromCharCode( 1776 + parseInt( d, 10 ) );
		} );
	}

	function normalizePhone( raw ) {
		var phone = toEnDigits( raw || '' ).replace( /[^0-9+]/g, '' );

		if ( phone.indexOf( '+98' ) === 0 ) {
			phone = '0' + phone.slice( 3 );
		} else if ( phone.indexOf( '0098' ) === 0 ) {
			phone = '0' + phone.slice( 4 );
		} else if ( phone.indexOf( '98' ) === 0 && phone.length === 12 ) {
			phone = '0' + phone.slice( 2 );
		} else if ( phone.indexOf( '9' ) === 0 && phone.length === 10 ) {
			phone = '0' + phone;
		}

		return /^09\d{9}$/.test( phone ) ? phone : '';
	}

	/**
	 * فراخوانی REST API افزونه.
	 */
	function api( path, options ) {
		options = options || {};

		var config = {
			method: options.method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce
			},
			credentials: 'same-origin'
		};

		if ( options.body ) {
			config.body = JSON.stringify( options.body );
		}

		return fetch( CFG.root + path, config ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var error = new Error( ( data && data.message ) || I18N.networkError );
					error.code = data && data.code;
					error.status = response.status;
					error.data = data;
					throw error;
				}

				// اگر سرور نانس تازه فرستاده بود جایگزین می‌کنیم (پس از ورود).
				if ( data && data.nonce ) {
					CFG.nonce = data.nonce;
				}

				return data;
			} ).catch( function ( err ) {
				if ( err instanceof SyntaxError ) {
					throw new Error( I18N.networkError );
				}
				throw err;
			} );
		} );
	}

	function setBusy( button, busy, busyText ) {
		if ( ! button ) {
			return;
		}

		if ( busy ) {
			button.dataset.label = button.dataset.label || button.textContent;
			button.textContent = busyText || I18N.sending;
			button.disabled = true;
			button.classList.add( 'is-busy' );
		} else {
			button.textContent = button.dataset.label || button.textContent;
			button.disabled = false;
			button.classList.remove( 'is-busy' );
		}
	}

	/* ---------------------------------------------------------------
	 * ویجت ورود با کد تایید (قابل استفاده در ویزارد و فرم مستقل)
	 * ------------------------------------------------------------ */

	function OtpWidget( options ) {
		this.opts = options;
		this.phone = '';
		this.timer = null;
		this.isNewUser = false;

		this.bind();
	}

	OtpWidget.prototype.bind = function () {
		var self = this;
		var o = this.opts;

		if ( o.sendBtn ) {
			o.sendBtn.addEventListener( 'click', function () {
				self.requestCode();
			} );
		}

		if ( o.phoneInput ) {
			o.phoneInput.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					self.requestCode();
				}
			} );
		}

		if ( o.verifyBtn ) {
			o.verifyBtn.addEventListener( 'click', function () {
				self.verifyCode();
			} );
		}

		if ( o.resendBtn ) {
			o.resendBtn.addEventListener( 'click', function () {
				if ( ! o.resendBtn.disabled ) {
					self.requestCode( true );
				}
			} );
		}

		if ( o.changeBtn ) {
			o.changeBtn.addEventListener( 'click', function () {
				self.showPhoneStep();
			} );
		}

		this.setupOtpInputs();
	};

	/**
	 * رفتار خانه‌های کد: حرکت خودکار، بک‌اسپیس و چسباندن کد.
	 */
	OtpWidget.prototype.setupOtpInputs = function () {
		var self = this;
		var inputs = $$( 'input', this.opts.otpWrap );

		inputs.forEach( function ( input, index ) {
			input.addEventListener( 'input', function () {
				input.value = toEnDigits( input.value ).replace( /\D/g, '' ).slice( 0, 1 );

				if ( input.value && inputs[ index + 1 ] ) {
					inputs[ index + 1 ].focus();
				}

				if ( self.readCode().length === inputs.length ) {
					self.verifyCode();
				}
			} );

			input.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Backspace' && ! input.value && inputs[ index - 1 ] ) {
					inputs[ index - 1 ].focus();
				}

				if ( event.key === 'Enter' ) {
					event.preventDefault();
					self.verifyCode();
				}
			} );

			input.addEventListener( 'paste', function ( event ) {
				event.preventDefault();
				var text = toEnDigits( ( event.clipboardData || window.clipboardData ).getData( 'text' ) ).replace( /\D/g, '' );

				inputs.forEach( function ( target, i ) {
					target.value = text[ i ] || '';
				} );

				if ( self.readCode().length === inputs.length ) {
					self.verifyCode();
				}
			} );
		} );
	};

	OtpWidget.prototype.readCode = function () {
		return $$( 'input', this.opts.otpWrap )
			.map( function ( input ) {
				return input.value;
			} )
			.join( '' );
	};

	OtpWidget.prototype.clearCode = function () {
		$$( 'input', this.opts.otpWrap ).forEach( function ( input ) {
			input.value = '';
		} );
	};

	OtpWidget.prototype.requestCode = function ( isResend ) {
		var self = this;
		var phone = normalizePhone( this.opts.phoneInput.value );

		if ( ! phone ) {
			this.opts.onError( I18N.invalidPhone );
			this.opts.phoneInput.focus();
			return;
		}

		this.phone = phone;

		var button = isResend ? this.opts.resendBtn : this.opts.sendBtn;
		setBusy( button, true, I18N.sending );
		this.opts.onError( '' );

		api( 'otp/request', { method: 'POST', body: { phone: phone } } )
			.then( function ( data ) {
				setBusy( button, false );
				self.isNewUser = !! data.is_new_user;
				self.showCodeStep( data );

				if ( data.dev_code ) {
					self.opts.onInfo( 'حالت توسعه: کد شما ' + toFaDigits( data.dev_code ) + ' است.' );
				}
			} )
			.catch( function ( error ) {
				setBusy( button, false );
				self.opts.onError( error.message );

				if ( error.data && error.data.data && error.data.data.retry_after ) {
					self.startTimer( error.data.data.retry_after );
				}
			} );
	};

	OtpWidget.prototype.verifyCode = function () {
		var self = this;
		var code = this.readCode();

		if ( code.length < ( CFG.otpLength || 5 ) ) {
			this.opts.onError( I18N.invalidCode );
			return;
		}

		var name = this.opts.nameInput ? this.opts.nameInput.value.trim() : '';

		if ( this.isNewUser && name.length < 3 ) {
			this.opts.onError( 'برای ثبت‌نام، نام و نام خانوادگی خود را وارد کنید.' );
			if ( this.opts.nameInput ) {
				this.opts.nameInput.focus();
			}
			return;
		}

		setBusy( this.opts.verifyBtn, true, I18N.sending );
		this.opts.onError( '' );

		api( 'otp/verify', { method: 'POST', body: { phone: this.phone, code: code, name: name } } )
			.then( function ( data ) {
				setBusy( self.opts.verifyBtn, false );
				self.stopTimer();
				CFG.isLoggedIn = true;
				CFG.currentPhone = data.phone;
				CFG.currentName = data.display_name;
				self.opts.onSuccess( data );
			} )
			.catch( function ( error ) {
				setBusy( self.opts.verifyBtn, false );
				self.clearCode();
				$$( 'input', self.opts.otpWrap )[ 0 ].focus();
				self.opts.onError( error.message );
			} );
	};

	OtpWidget.prototype.showCodeStep = function ( data ) {
		this.opts.phoneStep.hidden = true;
		this.opts.codeStep.hidden = false;

		if ( this.opts.phoneLabel ) {
			this.opts.phoneLabel.textContent = toFaDigits( data.masked_phone || this.phone );
		}

		if ( this.opts.nameField ) {
			this.opts.nameField.hidden = ! this.isNewUser;
		}

		this.clearCode();

		var first = $$( 'input', this.opts.otpWrap )[ 0 ];
		if ( first ) {
			first.focus();
		}

		this.startTimer( data.resend_after || CFG.resendAfter || 120 );
	};

	OtpWidget.prototype.showPhoneStep = function () {
		this.stopTimer();
		this.opts.codeStep.hidden = true;
		this.opts.phoneStep.hidden = false;
		this.opts.phoneInput.focus();
	};

	OtpWidget.prototype.startTimer = function ( seconds ) {
		var self = this;
		var remaining = parseInt( seconds, 10 ) || 0;
		var button = this.opts.resendBtn;

		if ( ! button ) {
			return;
		}

		this.stopTimer();

		function tick() {
			if ( remaining <= 0 ) {
				button.disabled = false;
				button.textContent = I18N.resend;
				self.stopTimer();
				return;
			}

			button.disabled = true;
			button.textContent = I18N.resendIn.replace( '%s', toFaDigits( remaining ) );
			remaining--;
		}

		tick();
		this.timer = window.setInterval( tick, 1000 );
	};

	OtpWidget.prototype.stopTimer = function () {
		if ( this.timer ) {
			window.clearInterval( this.timer );
			this.timer = null;
		}
	};

	/* ---------------------------------------------------------------
	 * ویزارد رزرو
	 * ------------------------------------------------------------ */

	function BookingWizard( root ) {
		this.root = root;
		this.state = {
			serviceId: 0,
			service: null,
			date: '',
			dateFa: '',
			block: '',
			blockLabel: ''
		};

		this.alert = $( '#cmb-alert', root );
		this.init();
	}

	BookingWizard.prototype.init = function () {
		var self = this;

		// انتخاب خدمت
		$$( '.cmb-service', this.root ).forEach( function ( card ) {
			function choose() {
				self.selectService( parseInt( card.dataset.serviceId, 10 ), card );
			}

			$( '.cmb-select-service', card ).addEventListener( 'click', choose );
			card.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					event.preventDefault();
					choose();
				}
			} );
		} );

		// دکمه‌های بازگشت
		$$( '.cmb-back', this.root ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				self.goTo( button.dataset.target );
			} );
		} );

		// ورود
		this.otp = new OtpWidget( {
			phoneStep: $( '#cmb-auth-phone', this.root ),
			codeStep: $( '#cmb-auth-code', this.root ),
			phoneInput: $( '#cmb-phone', this.root ),
			phoneLabel: $( '#cmb-phone-label', this.root ),
			otpWrap: $( '#cmb-otp-inputs', this.root ),
			nameField: $( '#cmb-name-field', this.root ),
			nameInput: $( '#cmb-auth-name', this.root ),
			sendBtn: $( '#cmb-send-otp', this.root ),
			verifyBtn: $( '#cmb-verify-otp', this.root ),
			resendBtn: $( '#cmb-resend', this.root ),
			changeBtn: $( '#cmb-change-phone', this.root ),
			onError: function ( message ) {
				self.showAlert( message, 'error' );
			},
			onInfo: function ( message ) {
				self.showAlert( message, 'info' );
			},
			onSuccess: function ( data ) {
				var nameInput = $( '#cmb-name', self.root );
				if ( nameInput && ! nameInput.value && data.display_name ) {
					nameInput.value = data.display_name;
				}
				self.goTo( 'details' );
			}
		} );

		var continueBtn = $( '#cmb-continue-logged', this.root );
		if ( continueBtn ) {
			continueBtn.addEventListener( 'click', function () {
				self.goTo( 'details' );
			} );
		}

		var logoutBtn = $( '#cmb-logout', this.root );
		if ( logoutBtn ) {
			logoutBtn.addEventListener( 'click', function () {
				api( 'logout', { method: 'POST' } ).then( function () {
					window.location.reload();
				} );
			} );
		}

		// ثبت نهایی
		$( '#cmb-details-form', this.root ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			self.submit();
		} );
	};

	BookingWizard.prototype.showAlert = function ( message, type ) {
		if ( ! this.alert ) {
			return;
		}

		if ( ! message ) {
			this.alert.hidden = true;
			this.alert.textContent = '';
			return;
		}

		this.alert.hidden = false;
		this.alert.textContent = message;
		this.alert.className = 'cmb-alert cmb-alert--' + ( type || 'error' );
		this.alert.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	};

	BookingWizard.prototype.goTo = function ( step ) {
		$$( '.cmb-panel', this.root ).forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.dataset.panel === step );
		} );

		var order = [ 'service', 'slot', 'auth', 'details', 'done' ];
		var index = order.indexOf( step );

		$$( '.cmb-step', this.root ).forEach( function ( item, i ) {
			item.classList.toggle( 'is-active', i === index );
			item.classList.toggle( 'is-done', i < index );
		} );

		this.showAlert( '' );
		this.root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	};

	BookingWizard.prototype.selectService = function ( serviceId, card ) {
		var self = this;

		this.state.serviceId = serviceId;

		$$( '.cmb-service', this.root ).forEach( function ( item ) {
			var active = item === card;
			item.classList.toggle( 'is-selected', active );
			item.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );

		this.goTo( 'slot' );
		this.loadCalendar();
	};

	BookingWizard.prototype.loadCalendar = function () {
		var self = this;
		var container = $( '#cmb-calendar', this.root );

		container.innerHTML = '<div class="cmb-loading">در حال دریافت ظرفیت‌ها…</div>';

		api( 'availability?service_id=' + this.state.serviceId )
			.then( function ( data ) {
				self.state.service = data.service;
				$( '#cmb-slot-service', self.root ).textContent =
					'خدمت انتخابی: ' + data.service.title + ( data.service.priceLabel ? ' — ' + data.service.priceLabel : '' );
				self.renderCalendar( data.days, container );
			} )
			.catch( function ( error ) {
				container.innerHTML = '';
				self.showAlert( error.message, 'error' );
			} );
	};

	BookingWizard.prototype.renderCalendar = function ( days, container ) {
		var self = this;

		container.innerHTML = '';

		days.forEach( function ( day ) {
			var card = document.createElement( 'div' );
			card.className = 'cmb-day' + ( day.available ? '' : ' is-disabled' );

			var head = document.createElement( 'div' );
			head.className = 'cmb-day__head';
			head.innerHTML =
				'<strong>' + day.weekdayName + '</strong><span>' + day.jalali + '</span>';
			card.appendChild( head );

			if ( ! day.blocks.length ) {
				var reason = document.createElement( 'p' );
				reason.className = 'cmb-day__reason';
				reason.textContent = day.reason || I18N.closed;
				card.appendChild( reason );
				container.appendChild( card );
				return;
			}

			var list = document.createElement( 'div' );
			list.className = 'cmb-day__blocks';

			day.blocks.forEach( function ( block ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'cmb-slot' + ( block.available ? '' : ' is-disabled' );
				button.disabled = ! block.available;

				var meta = block.available
					? ( block.remaining === 1 ? I18N.remainingOne : I18N.remaining.replace( '%s', toFaDigits( block.remaining ) ) )
					: ( block.reason || I18N.full );

				button.innerHTML =
					'<span class="cmb-slot__label">' + block.label + '</span>' +
					'<span class="cmb-slot__time">ساعت ' + block.startFa + '</span>' +
					'<span class="cmb-slot__meta">' + meta + '</span>';

				button.addEventListener( 'click', function () {
					self.selectSlot( day, block, button );
				} );

				list.appendChild( button );
			} );

			card.appendChild( list );
			container.appendChild( card );
		} );
	};

	BookingWizard.prototype.selectSlot = function ( day, block, button ) {
		this.state.date = day.date;
		this.state.dateFa = day.jalaliFull;
		this.state.block = block.key;
		this.state.blockLabel = block.label + ' (ساعت ' + block.startFa + ')';

		$$( '.cmb-slot', this.root ).forEach( function ( item ) {
			item.classList.remove( 'is-selected' );
		} );
		button.classList.add( 'is-selected' );

		this.renderSummary();

		if ( CFG.isLoggedIn ) {
			this.goTo( 'details' );
		} else {
			this.goTo( 'auth' );
		}
	};

	BookingWizard.prototype.renderSummary = function () {
		var box = $( '#cmb-summary', this.root );

		if ( ! box ) {
			return;
		}

		box.innerHTML =
			'<div class="cmb-summary__row"><span>خدمت</span><b>' + ( this.state.service ? this.state.service.title : '' ) + '</b></div>' +
			'<div class="cmb-summary__row"><span>تاریخ</span><b>' + this.state.dateFa + '</b></div>' +
			'<div class="cmb-summary__row"><span>شیفت</span><b>' + this.state.blockLabel + '</b></div>';
	};

	BookingWizard.prototype.submit = function () {
		var self = this;
		var button = $( '#cmb-submit', this.root );

		if ( ! this.state.serviceId ) {
			this.showAlert( I18N.selectService, 'error' );
			return;
		}

		if ( ! this.state.date || ! this.state.block ) {
			this.showAlert( I18N.selectSlot, 'error' );
			return;
		}

		var body = {
			service_id: this.state.serviceId,
			date: this.state.date,
			block: this.state.block,
			name: $( '#cmb-name', this.root ).value.trim(),
			city: ( $( '#cmb-city', this.root ) ? $( '#cmb-city', this.root ).value.trim() : '' ),
			car_brand: $( '#cmb-car-brand', this.root ).value.trim(),
			car_model: $( '#cmb-car-model', this.root ).value.trim(),
			car_year: toEnDigits( $( '#cmb-car-year', this.root ).value.trim() ),
			car_mileage: toEnDigits( $( '#cmb-car-mileage', this.root ).value.trim() ),
			note: $( '#cmb-note', this.root ).value.trim()
		};

		if ( ! body.name || ! body.city || ! body.car_brand || ! body.car_model || ! body.car_year ) {
			this.showAlert( I18N.requiredFields, 'error' );
			return;
		}

		setBusy( button, true, I18N.submitting );
		this.showAlert( '' );

		api( 'bookings', { method: 'POST', body: body } )
			.then( function ( data ) {
				setBusy( button, false );
				self.renderReceipt( data.booking );
				self.goTo( 'done' );
			} )
			.catch( function ( error ) {
				setBusy( button, false );
				self.showAlert( error.message, 'error' );

				// اگر ظرفیت پر شده بود، کاربر باید دوباره اسلات انتخاب کند.
				if ( error.status === 409 ) {
					self.goTo( 'slot' );
					self.loadCalendar();
				}

				if ( error.status === 401 ) {
					CFG.isLoggedIn = false;
					self.goTo( 'auth' );
				}
			} );
	};

	BookingWizard.prototype.renderReceipt = function ( booking ) {
		var box = $( '#cmb-receipt', this.root );

		box.innerHTML =
			'<div class="cmb-receipt__check">✅</div>' +
			'<h3 class="cmb-receipt__title">نوبت شما با موفقیت ثبت شد</h3>' +
			'<div class="cmb-receipt__screenshot">📸 ' + ( booking.note || '' ) + '</div>' +
			'<div class="cmb-receipt__card">' +
				row( 'کد پیگیری', booking.code ) +
				row( 'خدمت', booking.service ) +
				row( 'شعبه', booking.branch ) +
				row( 'تاریخ', booking.dateFa ) +
				row( 'شیفت', booking.blockLabel + ' — ساعت ' + booking.blockStart ) +
				row( 'نام', booking.name ) +
				row( 'شماره تماس', booking.phoneFa ) +
				row( 'خودرو', booking.car ) +
			'</div>' +
			( booking.lateRule ? '<p class="cmb-note">⏰ ' + booking.lateRule + '</p>' : '' ) +
			'<p class="cmb-note">پیامک تاییدیه برای شما ارسال شد.</p>' +
			'<div class="cmb-actions"><button type="button" class="cmb-btn" onclick="window.print()">چاپ / ذخیره</button></div>';

		function row( label, value ) {
			return '<div class="cmb-receipt__row"><span>' + label + '</span><b>' + ( value || '—' ) + '</b></div>';
		}
	};

	/* ---------------------------------------------------------------
	 * فرم مستقل ورود
	 * ------------------------------------------------------------ */

	function initStandaloneLogin( root ) {
		var alertBox = $( '#cmb-login-alert', root );

		function show( message, type ) {
			if ( ! message ) {
				alertBox.hidden = true;
				return;
			}
			alertBox.hidden = false;
			alertBox.textContent = message;
			alertBox.className = 'cmb-alert cmb-alert--' + ( type || 'error' );
		}

		new OtpWidget( {
			phoneStep: $( '#cmb-login-phone-step', root ),
			codeStep: $( '#cmb-login-code-step', root ),
			phoneInput: $( '#cmb-login-phone', root ),
			phoneLabel: $( '#cmb-login-phone-label', root ),
			otpWrap: $( '#cmb-login-otp', root ),
			nameField: $( '#cmb-login-name-field', root ),
			nameInput: $( '#cmb-login-name', root ),
			sendBtn: $( '#cmb-login-send', root ),
			verifyBtn: $( '#cmb-login-verify', root ),
			resendBtn: $( '#cmb-login-resend', root ),
			changeBtn: $( '#cmb-login-change', root ),
			onError: function ( message ) {
				show( message, 'error' );
			},
			onInfo: function ( message ) {
				show( message, 'info' );
			},
			onSuccess: function () {
				var redirect = root.dataset.redirect;
				window.location.href = redirect || window.location.href;
			}
		} );
	}

	/* ------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		var booking = $( '#cmb-booking' );
		if ( booking ) {
			new BookingWizard( booking );
		}

		var login = $( '#cmb-login' );
		if ( login ) {
			initStandaloneLogin( login );
		}
	} );
} )();
