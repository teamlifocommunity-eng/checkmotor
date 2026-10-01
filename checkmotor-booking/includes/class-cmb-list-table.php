<?php
/**
 * جدول نوبت‌ها در پنل مدیریت.
 *
 * مهم: این فایل نباید هنگام بارگذاری افزونه (plugins_loaded) لود شود.
 * کلاس زیر از WP_List_Table ارث‌بری می‌کند و WP_List_Table تنها بخشی از
 * wp-admin است که در آن لحظه هنوز تعریف نشده — بارگذاری زودهنگام باعث
 * «Fatal error: Class WP_List_Table not found» و شکست فعال‌سازی افزونه می‌شد.
 *
 * این فایل به‌صورت تنبل و فقط داخل CMB_Admin::page_bookings() لود می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	$cmb_list_table_core = ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

	if ( file_exists( $cmb_list_table_core ) ) {
		require_once $cmb_list_table_core;
	}
}

// اگر به هر دلیلی هسته‌ی وردپرس در دسترس نبود، به‌جای Fatal Error ساکت خارج شو.
if ( ! class_exists( 'WP_List_Table' ) ) {
	return;
}

if ( class_exists( 'CMB_Bookings_List_Table' ) ) {
	return;
}

class CMB_Bookings_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'booking',
				'plural'   => 'bookings',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'booking_date'  => 'تاریخ',
			'block_key'     => 'شیفت',
			'service_id'    => 'خدمت',
			'customer_name' => 'مشتری',
			'car'           => 'خودرو',
			'tracking_code' => 'کد پیگیری',
			'status'        => 'وضعیت',
			'actions'       => 'عملیات',
		);
	}

	public function get_sortable_columns() {
		return array(
			'booking_date'  => array( 'booking_date', true ),
			'customer_name' => array( 'customer_name', false ),
			'status'        => array( 'status', false ),
		);
	}

	/**
	 * فیلترهای بالای جدول.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$filter = isset( $_GET['cmb_filter'] ) ? sanitize_key( wp_unslash( $_GET['cmb_filter'] ) ) : 'upcoming'; // phpcs:ignore
		$status = isset( $_GET['cmb_status'] ) ? sanitize_key( wp_unslash( $_GET['cmb_status'] ) ) : ''; // phpcs:ignore
		$date   = cmb_read_jalali_field( 'cmb_date', $_GET ); // phpcs:ignore
		?>
		<div class="alignleft actions">
			<select name="cmb_filter">
				<option value="upcoming" <?php selected( $filter, 'upcoming' ); ?>>نوبت‌های پیش‌رو</option>
				<option value="today" <?php selected( $filter, 'today' ); ?>>امروز</option>
				<option value="tomorrow" <?php selected( $filter, 'tomorrow' ); ?>>فردا</option>
				<option value="past" <?php selected( $filter, 'past' ); ?>>گذشته</option>
				<option value="all" <?php selected( $filter, 'all' ); ?>>همه</option>
			</select>

			<select name="cmb_status">
				<option value="">همه‌ی وضعیت‌ها</option>
				<?php foreach ( cmb_statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php echo cmb_jalali_field( 'cmb_date', $date, array( 'empty' => 'تاریخ' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<?php submit_button( 'اعمال فیلتر', '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function prepare_items() {
		global $wpdb;

		$table    = cmb_table( 'bookings' );
		$per_page = 30;
		$page     = $this->get_pagenum();

		$where  = array( '1=1' );
		$params = array();

		$filter = isset( $_GET['cmb_filter'] ) ? sanitize_key( wp_unslash( $_GET['cmb_filter'] ) ) : 'upcoming'; // phpcs:ignore
		$today  = cmb_today();

		switch ( $filter ) {
			case 'today':
				$where[]  = 'booking_date = %s';
				$params[] = $today;
				break;
			case 'tomorrow':
				$where[]  = 'booking_date = %s';
				$params[] = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
				break;
			case 'past':
				$where[]  = 'booking_date < %s';
				$params[] = $today;
				break;
			case 'all':
				break;
			case 'upcoming':
			default:
				$where[]  = 'booking_date >= %s';
				$params[] = $today;
				break;
		}

		if ( ! empty( $_GET['cmb_status'] ) ) { // phpcs:ignore
			$where[]  = 'status = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['cmb_status'] ) ); // phpcs:ignore
		}

		$filter_date = cmb_read_jalali_field( 'cmb_date', $_GET ); // phpcs:ignore

		if ( '' !== $filter_date ) {
			$where[]  = 'booking_date = %s';
			$params[] = $filter_date;
		}

		if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore
			$search   = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) . '%'; // phpcs:ignore
			$where[]  = '(customer_name LIKE %s OR phone LIKE %s OR tracking_code LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$where_sql = implode( ' AND ', $where );

		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'booking_date'; // phpcs:ignore
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( wp_unslash( $_GET['order'] ) ) ? 'DESC' : 'ASC'; // phpcs:ignore

		if ( ! in_array( $orderby, array( 'booking_date', 'customer_name', 'status', 'id' ), true ) ) {
			$orderby = 'booking_date';
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		$sql          = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, block_key ASC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) );

		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) ); // phpcs:ignore

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'booking_date':
				return esc_html( cmb_jalali_date( $item->booking_date, 'full' ) ) . '<br /><small>' . esc_html( $item->booking_date ) . '</small>';

			case 'block_key':
				return esc_html( cmb_block_label( $item->block_key ) ) . '<br /><small>ساعت ' . esc_html( cmb_fa_num( cmb_block_start( $item->block_key ) ) ) . '</small>';

			case 'service_id':
				$service = CMB_Services::get_service( $item->service_id );
				return $service ? esc_html( $service->title ) : '—';

			case 'customer_name':
				return '<b>' . esc_html( $item->customer_name ) . '</b><br /><a href="tel:' . esc_attr( $item->phone ) . '">' . esc_html( cmb_fa_num( $item->phone ) ) . '</a>'
					. ( ! empty( $item->city ) ? ' · ' . esc_html( $item->city ) : '' );

			case 'car':
				/* این مقادیر را مشتری وارد می‌کند و ستون‌های جدول بدون
				   فرار داده‌شدن چاپ می‌شوند، پس esc_html اینجا لازم است. */
				$car = esc_html(
					implode(
						' · ',
						array_filter( array( $item->car_brand, $item->car_model, cmb_fa_num( $item->car_year ) ) )
					)
				);

				if ( $item->car_mileage ) {
					$car .= '<br /><small>' . esc_html( cmb_fa_num( number_format( (int) $item->car_mileage ) ) ) . ' کیلومتر</small>';
				}

				return $car;

			case 'tracking_code':
				return '<code>' . esc_html( $item->tracking_code ) . '</code>';

			case 'status':
				return '<span class="cmb-pill cmb-pill--' . esc_attr( $item->status ) . '">' . esc_html( cmb_status_label( $item->status ) ) . '</span>';

			case 'actions':
				return $this->render_actions( $item );
		}

		return '';
	}

	protected function render_actions( $item ) {
		$links = array();

		$map = array(
			'done'      => 'انجام شد',
			'no_show'   => 'عدم مراجعه',
			'cancelled' => 'لغو',
			'confirmed' => 'بازگردانی',
		);

		// همان قواعد پنل: نوبت پرداخت‌نشده فقط لغو، نوبت با برگشت وجهِ انجام‌شده هیچ…
		$allowed = CMB_Bookings::actions_for( $item );

		if ( 'pending' === $item->status ) {
			$map['cancelled'] = 'لغو (آزاد کردن جا)';
		}

		foreach ( $map as $status => $label ) {
			if ( $status === $item->status || ! in_array( $status, $allowed, true ) ) {
				continue;
			}

			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=cmb_update_booking&booking_id=' . $item->id . '&status=' . $status ),
				'cmb_update_booking_' . $item->id
			);

			$ask = 'با لغو نوبت، پیامک اطلاع‌رسانی برای مشتری ارسال می‌شود. مطمئن هستید؟';

			if ( 'cancelled' === $status && isset( $item->pay_status ) && 'paid' === $item->pay_status ) {
				$ask = sprintf(
					'بیعانه‌ی این نوبت پرداخت شده؛ با لغو، %s تومان در صف بازگشت وجه می‌نشیند (مبلغ دیگر را از پنل رزرو می‌توانید بگذارید). مطمئن هستید؟',
					number_format( CMB_Payments::shop_refund_default( (int) $item->deposit_amount ) )
				);
			}

			$confirm = 'cancelled' === $status ? ' onclick="return confirm(\'' . esc_js( $ask ) . '\')"' : '';

			$links[] = '<a class="button button-small" href="' . esc_url( $url ) . '"' . $confirm . '>' . esc_html( $label ) . '</a>';
		}

		$delete_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cmb_update_booking&booking_id=' . $item->id . '&status=delete' ),
			'cmb_update_booking_' . $item->id
		);

		if ( in_array( 'delete', $allowed, true ) ) {
			$links[] = '<a class="button button-small cmb-danger" href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'این نوبت برای همیشه حذف شود؟\')">حذف</a>';
		}

		$pay = CMB_Payments::summary( $item );

		if ( $pay && $pay['deposit'] ) {
			$links[] = '<p class="description">بیعانه ' . esc_html( $pay['depositFa'] ) . ( $pay['statusLabel'] ? ' — ' . esc_html( $pay['statusLabel'] ) : '' ) . '</p>';
		}

		$note = $item->note ? '<p class="description">یادداشت: ' . esc_html( $item->note ) . '</p>' : '';

		return '<div class="cmb-row-actions">' . implode( ' ', $links ) . '</div>' . $note;
	}

	public function no_items() {
		echo 'نوبتی با این فیلترها یافت نشد.';
	}
}
