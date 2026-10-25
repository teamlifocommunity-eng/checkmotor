<?php
/**
 * پیامک‌های مخصوص نمایندگی‌ها (بخش ۶.۶ سند).
 *
 * گیرنده‌ها: branches.manager_phones همان نمایندگی به‌علاوه‌ی موبایل
 * مدیرش؛ نه admin_phones سراسری (آن فقط برای نوبت‌های مرکز است). مرکز
 * پیامک نمایندگی‌ها را نمی‌گیرد، فقط گزارش.
 *
 * هر پیامک اول پترن تازه‌ی خودش را امتحان می‌کند؛ تا آن تأیید نشده،
 * اگر پترن مشابهی از قبل هست همان می‌رود (مثلاً نوبت تازه ← پترن «اطلاع
 * به مدیر»)، و در حالت ارسال ساده متن کامل.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Branch_Notify {

	protected static function send_all( $branch, array $attempts, $fallback ) {
		$phones = CMB_Branches::notify_phones( $branch );

		foreach ( $phones as $phone ) {
			$r = CMB_SMS::send_first( $phone, $attempts, $fallback );

			if ( is_wp_error( $r ) ) {
				cmb_log( 'Agency SMS failed: ' . $r->get_error_message(), array( 'phone' => $phone ) );
			}
		}

		return count( $phones );
	}

	protected static function service_title( $booking ) {
		$s = CMB_Services::get_service( $booking->service_id );

		return $s ? $s->title : '';
	}

	/** نوبت تازه در نمایندگی. */
	public static function new_booking( $booking ) {
		$b = CMB_Branches::get( $booking->branch_id );

		if ( ! $b ) {
			return 0;
		}

		$date  = cmb_jalali_date( $booking->booking_date, 'numeric' );
		$block = cmb_block_label( $booking->block_key );
		$svc   = self::service_title( $booking );

		return self::send_all(
			$b,
			array(
				// ۱.۴۶.۰: اول پترن تازه با شهر، خودرو و موتور مشتری
				array( 'pattern_new_booking', CMB_Bookings::new_booking_args( $booking ) ),
				array( 'pattern_agency_new', array( $booking->customer_name, $booking->phone, $svc, $date, $block ) ),
				array( 'pattern_admin', array( $svc, $date, $block, $booking->customer_name, $booking->phone ) ),
			),
			CMB_Bookings::new_booking_text( $booking )
		);
	}

	/** مشتری نوبت نمایندگی را لغو کرد. */
	public static function cancelled( $booking ) {
		$b = CMB_Branches::get( $booking->branch_id );

		if ( ! $b ) {
			return 0;
		}

		$date  = cmb_jalali_date( $booking->booking_date, 'numeric' );
		$block = cmb_block_label( $booking->block_key );
		$svc   = self::service_title( $booking );

		return self::send_all(
			$b,
			array(
				array( 'pattern_agency_cancel', array( $booking->customer_name, $svc, $date ) ),
				array( 'pattern_admin_cancel', array( $svc, $date, $block, $booking->customer_name, $booking->phone ) ),
			),
			sprintf( "لغو توسط مشتری\n%s %s\n%s\n%s %s", $booking->customer_name, $booking->phone, $svc, $date, $block )
		);
	}

	/** عبور موجودی آزاد به زیر یکی از دو پله‌ی هشدار. */
	public static function low_balance( $branch_id, $free, $capacity ) {
		$b = CMB_Branches::get( $branch_id );

		if ( ! $b ) {
			return 0;
		}

		$free_fa = number_format( max( 0, (int) $free ) );

		return self::send_all(
			$b,
			array(
				array( 'pattern_agency_low', array( $free_fa, (int) $capacity ) ),
			),
			$capacity > 0
				? sprintf( "موجودی کیف پول: %s تومان (%s نوبت). از پنل شارژ کنید.\nچک موتور", $free_fa, (int) $capacity )
				: "موجودی کیف پول تمام شد؛ نوبت آنلاین متوقف است. از پنل شارژ کنید.\nچک موتور"
		);
	}

	/** شارژ موفق کیف پول. */
	public static function topup( $branch_id, $amount, $free ) {
		$b = CMB_Branches::get( $branch_id );

		if ( ! $b ) {
			return 0;
		}

		return self::send_all(
			$b,
			array(
				array( 'pattern_agency_topup', array( number_format( (int) $amount ), number_format( max( 0, (int) $free ) ) ) ),
			),
			sprintf( "شارژ کیف پول: %s تومان\nموجودی: %s تومان\nچک موتور", number_format( (int) $amount ), number_format( max( 0, (int) $free ) ) )
		);
	}

	/** «پنل شما فعال شد» (US-1.2). */
	public static function activated( $branch ) {
		$b = is_object( $branch ) ? $branch : CMB_Branches::get( $branch );

		if ( ! $b ) {
			return 0;
		}

		$credit = number_format( max( 0, (int) CMB_Settings::get( 'agency_welcome_credit', 1000000 ) ) );
		$link   = cmb_app_url( 'panel' );

		return self::send_all(
			$b,
			array(
				array( 'pattern_agency_active', array( $b->title, $credit, $link ) ),
			),
			sprintf( "پنل «%s» فعال شد. اعتبار: %s تومان\n%s", $b->title, $credit, $link )
		);
	}
}
