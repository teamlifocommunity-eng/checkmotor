<?php
/**
 * حذف کامل داده‌های افزونه هنگام پاک کردن آن.
 *
 * برای جلوگیری از حذف ناخواسته‌ی نوبت‌ها، جداول فقط زمانی حذف می‌شوند که
 * ثابت CMB_REMOVE_ALL_DATA در wp-config.php روی true تنظیم شده باشد.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'CMB_REMOVE_ALL_DATA' ) || ! CMB_REMOVE_ALL_DATA ) {
	return;
}

global $wpdb;

$cmb_tables = array( 'bookings', 'closures', 'services', 'branches', 'otp', 'payments' );

foreach ( $cmb_tables as $cmb_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cmb_{$cmb_table}" ); // phpcs:ignore
}

/* همه‌ی آپشن‌ها، نه فقط چند تای اصلی. نسخه‌های قبلی هفت مورد از
   این فهرست را جا می‌گذاشتند و بعد از حذف افزونه در جدول options
   می‌ماندند. */
$cmb_options = array(
	'cmb_settings',
	'cmb_db_version',
	'cmb_default_branch_id',
	'cmb_page_booking',
	'cmb_page_my',
	'cmb_app_slug',
	'cmb_canvas',
	'cmb_rewrite_stamp',
	'cmb_panel_roles',
	'cmb_persian_digits',
	'cmb_poster_cols',
	'cmb_login_url',
	'cmb_font_url',
	'cmb_font_stack',
	'cmb_last_tick',
	'cmb_autocomplete_day',
	'cmb_new_user_role',
	'cmb_panel_pwa',
	'cmb_panel_app_name',
	'cmb_panel_otp',
	'cmb_fast',
	'cmb_fast_stamp',
	'cmb_pay_used',
	'cmb_zp_token',
	'cmb_pay_selftest',
	'cmb_pay_setup',
);

/* فایل حالت سریع در mu-plugins؛ فقط اگر واقعاً مال همین افزونه باشد. */
$cmb_fast_file = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/cmb-fast-requests.php';

if ( file_exists( $cmb_fast_file ) && false !== strpos( (string) file_get_contents( $cmb_fast_file ), 'cmb-fast-version' ) ) { // phpcs:ignore
	@unlink( $cmb_fast_file ); // phpcs:ignore
}

foreach ( $cmb_options as $cmb_option ) {
	delete_option( $cmb_option );
}

// ترنزینت‌ها: کلیدشان متغیر است (شناسه‌ی شعبه)، پس با الگو پاک می‌شوند.
$wpdb->query( // phpcs:ignore
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_cmb\_%'
	    OR option_name LIKE '\_transient\_timeout\_cmb\_%'"
);

// متای کاربران.
foreach ( array( 'cmb_phone', 'cmb_registered_via' ) as $cmb_meta ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $cmb_meta ) ); // phpcs:ignore
}

/* ترتیب این بخش مهم است.

   ۱) اول نقش را از خودِ کاربرها برمی‌داریم. اگر ابتدا remove_role
      سراسری اجرا شود، WP_User::remove_role دیگر آن نقش را جزو
      نقش‌های کاربر نمی‌بیند و کلیدِ بی‌صاحب «cmb_operator» تا ابد
      در متای کاربر می‌ماند.
   ۲) بعد تعریف نقش‌ها حذف می‌شود.
   ۳) بعد دسترسی از نقش‌های وردپرس و از کاربرانی که دسترسی مستقیم
      داشته‌اند برداشته می‌شود. */

foreach ( array( 'cmb_operator', 'cmb_customer' ) as $cmb_role_name ) {
	foreach ( get_users( array( 'role' => $cmb_role_name, 'fields' => 'all' ) ) as $cmb_role_user ) {
		$cmb_role_user->remove_role( $cmb_role_name );
	}
}

remove_role( 'cmb_customer' );
remove_role( 'cmb_operator' );

foreach ( array( 'administrator', 'editor', 'shop_manager' ) as $cmb_role_name ) {
	$cmb_role = get_role( $cmb_role_name );

	if ( $cmb_role ) {
		$cmb_role->remove_cap( 'cmb_manage_bookings' );
	}
}

/* دسترسی‌هایی که مستقیم روی خودِ کاربر نشسته‌اند (add_cap).

   اینجا نباید ردیف capabilities را پاک کرد: آن ردیف همه‌ی نقش‌های
   کاربر را نگه می‌دارد، نه فقط دسترسی ما. حذفش یعنی کاربر بی‌نقش و
   بی‌دسترسی می‌ماند — از جمله مدیر کلی که این قابلیت را داشته.
   پس هر کاربر را برمی‌داریم و remove_cap می‌زنیم تا فقط همان یک
   کلید از آرایه‌ی سریال‌شده برداشته شود. */
$cmb_cap_users = get_users(
	array(
		'meta_key'     => $wpdb->get_blog_prefix() . 'capabilities', // phpcs:ignore
		'meta_value'   => 'cmb_manage_bookings',                     // phpcs:ignore
		'meta_compare' => 'LIKE',
		'fields'       => 'all',
	)
);

foreach ( $cmb_cap_users as $cmb_cap_user ) {
	$cmb_cap_user->remove_cap( 'cmb_manage_bookings' );
}
