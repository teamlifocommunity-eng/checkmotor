<?php
/**
 * مدیریت کاربرانِ پنل رزرو.
 *
 * اینجا برای مسئول رزرو یک نام کاربری و رمز می‌سازیم. آن حساب فقط
 * دسترسی cmb_manage_bookings می‌گیرد — نه پیشخوان وردپرس، نه تنظیمات،
 * نه کاربران. با همان نام کاربری و رمز از خودِ صفحه‌ی پنل وارد می‌شود،
 * یا اگر شماره‌ی موبایلش ثبت شده باشد، با کد تایید پیامکی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Operators {

	const CAP  = 'cmb_manage_bookings';
	const ROLE = 'cmb_operator';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_cmb_add_operator', array( $this, 'handle_add' ) );
		add_action( 'admin_post_cmb_reset_operator', array( $this, 'handle_reset' ) );
		add_action( 'admin_post_cmb_revoke_operator', array( $this, 'handle_revoke' ) );
		add_action( 'admin_post_cmb_save_roles', array( $this, 'handle_save_roles' ) );
		add_action( 'admin_post_cmb_grant_user', array( $this, 'handle_grant_user' ) );
		add_action( 'admin_post_cmb_operator_phone', array( $this, 'handle_phone' ) );
	}

	/**
	 * بررسی شماره‌ی موبایل مسئول رزرو.
	 *
	 * یک شماره فقط باید مال یک حساب باشد؛ وگرنه ورود با کد (هم در پنل،
	 * هم در اپ مشتری) معلوم نیست کدام حساب را باز کند.
	 *
	 * @param string $raw     شماره‌ی واردشده.
	 * @param int    $user_id حسابی که شماره برایش ثبت می‌شود (صفر برای حساب تازه).
	 *
	 * @return string|WP_Error شماره‌ی نرمال‌شده.
	 */
	protected static function check_phone( $raw, $user_id = 0 ) {
		$phone = cmb_normalize_phone( (string) $raw );

		if ( ! $phone ) {
			return new WP_Error( 'cmb_phone', 'شماره موبایل معتبر نیست. نمونه: ۰۹۱۲۳۴۵۶۷۸۹' );
		}

		foreach ( CMB_OTP::users_with_phone( $phone ) as $other ) {
			if ( (int) $other->ID !== (int) $user_id ) {
				return new WP_Error(
					'cmb_phone_taken',
					sprintf(
						'شماره‌ی %1$s مال حساب «%2$s» است و یک شماره فقط می‌تواند به یک حساب وصل باشد. اگر همان شخص است، به‌جای حساب تازه، در بخش «دادن دسترسی به یک کاربر موجود» همین شماره را وارد کنید.',
						$phone,
						$other->user_login
					)
				);
			}
		}

		return $phone;
	}

	/**
	 * ساخت نقش. با هر بار فعال‌سازی صدا زده می‌شود.
	 */
	public static function register_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				'مسئول رزرو چک موتور',
				array(
					'read'   => true,
					self::CAP => true,
				)
			);
		}

		// مدیر کل همیشه باید دسترسی داشته باشد.
		$admin = get_role( 'administrator' );

		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
	}

	/**
	 * فهرست کاربرانی که دسترسی پنل دارند.
	 *
	 * @return WP_User[]
	 */
	public static function all() {
		$users = get_users( array( 'role' => self::ROLE ) );

		// کاربرانی که از راه نقشِ مجاز دسترسی دارند.
		$roles = (array) get_option( 'cmb_panel_roles', array() );

		if ( $roles ) {
			foreach ( get_users( array( 'role__in' => $roles, 'number' => 200 ) ) as $user ) {
				$users[] = $user;
			}
		}

		// کسانی که نقش دیگری دارند ولی دسترسی به آن‌ها داده شده.
		foreach ( get_users( array( 'number' => 200 ) ) as $user ) {
			if ( in_array( self::ROLE, (array) $user->roles, true ) ) {
				continue;
			}

			if ( $user->has_cap( self::CAP ) && ! user_can( $user, 'manage_options' ) ) {
				$users[] = $user;
			}
		}

		// حذف تکراری‌ها
		$seen = array();
		$out  = array();

		foreach ( $users as $user ) {
			if ( isset( $seen[ $user->ID ] ) || user_can( $user, 'manage_options' ) ) {
				continue;
			}

			$seen[ $user->ID ] = true;
			$out[]             = $user;
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */

	protected function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}

		check_admin_referer( $action );
	}

	protected function back( $message, $type = 'success' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'cmb-operators',
					'cmb_message' => rawurlencode( $message ),
					'cmb_type'    => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * ساخت حساب تازه، یا دادن دسترسی به حساب موجود.
	 */
	public function handle_add() {
		$this->guard( 'cmb_add_operator' );

		$login = sanitize_user( wp_unslash( $_POST['cmb_user'] ?? '' ), true );
		$pass  = (string) ( $_POST['cmb_pass'] ?? '' );
		$name  = sanitize_text_field( wp_unslash( $_POST['cmb_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['cmb_email'] ?? '' ) );
		$raw   = trim( (string) wp_unslash( $_POST['cmb_phone'] ?? '' ) );

		if ( '' === $login ) {
			$this->back( 'نام کاربری را وارد کنید.', 'error' );
		}

		$existing = get_user_by( 'login', $login );
		$phone    = '';

		if ( '' !== $raw ) {
			$phone = self::check_phone( $raw, $existing ? $existing->ID : 0 );

			if ( is_wp_error( $phone ) ) {
				$this->back( $phone->get_error_message(), 'error' );
			}
		}

		if ( $existing ) {
			// حساب هست: فقط دسترسی بده (و اگر رمز داده شده، عوضش کن).
			$existing->add_cap( self::CAP );

			if ( '' !== $pass ) {
				wp_set_password( $pass, $existing->ID );
			}

			if ( '' !== $phone ) {
				update_user_meta( $existing->ID, 'cmb_phone', $phone );
			}

			$this->back( sprintf( 'به حساب «%s» دسترسی پنل رزرو داده شد.', $login ) );
		}

		if ( strlen( $pass ) < 8 ) {
			$this->back( 'رمز عبور باید حداقل ۸ کاراکتر باشد.', 'error' );
		}

		if ( '' === $email ) {
			$email = $login . '@panel.checkmotor.local';
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => $pass,
				'user_email'   => $email,
				'display_name' => '' !== $name ? $name : $login,
				'first_name'   => $name,
				'role'         => self::ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$this->back( 'ساخت حساب ناموفق بود: ' . $user_id->get_error_message(), 'error' );
		}

		if ( '' !== $phone ) {
			update_user_meta( $user_id, 'cmb_phone', $phone );
		}

		$this->back( sprintf( 'حساب «%s» ساخته شد و به پنل رزرو دسترسی دارد.', $login ) );
	}

	/**
	 * ثبت یا پاک کردن شماره‌ی موبایلِ ورود با کد.
	 */
	public function handle_phone() {
		$this->guard( 'cmb_operator_phone' );

		$user_id = (int) ( $_POST['cmb_user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			$this->back( 'کاربر پیدا نشد.', 'error' );
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			$this->back( 'شماره‌ی مدیران کل از این صفحه قابل تغییر نیست.', 'error' );
		}

		$raw = trim( (string) wp_unslash( $_POST['cmb_phone'] ?? '' ) );

		if ( '' === $raw ) {
			delete_user_meta( $user_id, 'cmb_phone' );
			$this->back( sprintf( 'شماره‌ی «%s» پاک شد؛ فقط با نام کاربری و رمز وارد می‌شود.', $user->user_login ) );
		}

		$phone = self::check_phone( $raw, $user_id );

		if ( is_wp_error( $phone ) ) {
			$this->back( $phone->get_error_message(), 'error' );
		}

		update_user_meta( $user_id, 'cmb_phone', $phone );

		$this->back( sprintf( 'شماره‌ی %1$s برای «%2$s» ثبت شد و می‌تواند با کد تایید وارد پنل شود.', $phone, $user->user_login ) );
	}

	/**
	 * تغییر رمز.
	 */
	public function handle_reset() {
		$this->guard( 'cmb_reset_operator' );

		$user_id = (int) ( $_POST['cmb_user_id'] ?? 0 );
		$pass    = (string) ( $_POST['cmb_pass'] ?? '' );

		if ( ! $user_id || strlen( $pass ) < 8 ) {
			$this->back( 'رمز تازه باید حداقل ۸ کاراکتر باشد.', 'error' );
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			$this->back( 'رمز مدیران کل از این صفحه قابل تغییر نیست.', 'error' );
		}

		wp_set_password( $pass, $user_id );

		$user = get_userdata( $user_id );

		$this->back( sprintf( 'رمز «%s» عوض شد.', $user ? $user->user_login : $user_id ) );
	}

	/**
	 * گرفتن دسترسی.
	 */
	public function handle_revoke() {
		$this->guard( 'cmb_revoke_operator' );

		$user_id = (int) ( $_POST['cmb_user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			$this->back( 'کاربر پیدا نشد.', 'error' );
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			$this->back( 'دسترسی مدیران کل از این صفحه قابل حذف نیست.', 'error' );
		}

		$user->remove_cap( self::CAP );

		if ( in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->remove_role( self::ROLE );
			$user->add_role( 'subscriber' );
		}

		$this->back( sprintf( 'دسترسی «%s» به پنل رزرو گرفته شد.', $user->user_login ) );
	}

	/**
	 * ذخیره‌ی نقش‌هایی که به پنل دسترسی دارند.
	 */
	public function handle_save_roles() {
		$this->guard( 'cmb_save_roles' );

		$roles = isset( $_POST['cmb_roles'] ) ? (array) wp_unslash( $_POST['cmb_roles'] ) : array();
		$valid = array_keys( wp_roles()->roles );
		$clean = array();

		foreach ( $roles as $role ) {
			$role = sanitize_key( $role );

			if ( 'administrator' !== $role && in_array( $role, $valid, true ) ) {
				$clean[] = $role;
			}
		}

		update_option( 'cmb_panel_roles', array_values( array_unique( $clean ) ) );

		$this->back(
			$clean
				? 'نقش‌های دارای دسترسی ذخیره شد: ' . implode( '، ', $clean )
				: 'دسترسی بر اساس نقش خاموش شد.'
		);
	}

	/**
	 * دادن دسترسی به یک کاربر موجود، بدون تغییر نقشش.
	 */
	public function handle_grant_user() {
		$this->guard( 'cmb_grant_user' );

		$lookup = trim( (string) wp_unslash( $_POST['cmb_lookup'] ?? '' ) );

		if ( '' === $lookup ) {
			$this->back( 'نام کاربری یا ایمیل را وارد کنید.', 'error' );
		}

		$user = get_user_by( 'login', $lookup );

		if ( ! $user && is_email( $lookup ) ) {
			$user = get_user_by( 'email', $lookup );
		}

		// شماره‌ی موبایل هم پذیرفته می‌شود، چون کاربران این سایت با شماره ثبت‌نام کرده‌اند.
		if ( ! $user && class_exists( 'CMB_OTP' ) ) {
			$phone = cmb_normalize_phone( $lookup );

			if ( $phone ) {
				$found = CMB_OTP::find_user( $phone );

				if ( $found ) {
					$user = $found;
				}
			}
		}

		if ( ! $user ) {
			$this->back( sprintf( 'کاربری با «%s» پیدا نشد.', $lookup ), 'error' );
		}

		$user->add_cap( self::CAP );

		$this->back( sprintf( 'به «%s» دسترسی پنل رزرو داده شد.', $user->user_login ) );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * رندر صفحه.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}

		$operators = self::all();
		$panel_url = function_exists( 'cmb_app_url' ) ? cmb_app_url( 'panel' ) : '';

		$message = isset( $_GET['cmb_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['cmb_message'] ) ) ) : '';
		$type    = isset( $_GET['cmb_type'] ) && 'error' === $_GET['cmb_type'] ? 'error' : 'success';
		?>
		<div class="wrap cmb-admin" dir="rtl">
			<h1>کاربران پنل رزرو</h1>

			<?php if ( $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<p style="font-size:14px;line-height:2;max-width:760px">
				برای مسئول رزرو اینجا نام کاربری و رمز بسازید. آن حساب فقط به
				<b>پنل رزرو</b> دسترسی دارد — نه پیشخوان وردپرس، نه تنظیمات، نه کاربران.
				<br>اگر شماره‌ی موبایلش را هم ثبت کنید، می‌تواند به‌جای رمز با <b>کد تایید پیامکی</b> وارد شود.
				مدیران کل سایت هم اگر شماره‌شان در حسابشان ثبت باشد (مثلاً از ورود پیامکی سایت) همین‌طور.
				<?php if ( $panel_url ) : ?>
					<br>نشانی ورود: <code><?php echo esc_html( $panel_url ); ?></code>
				<?php endif; ?>
			</p>

			<h2>دسترسی بر اساس نقش</h2>

			<p class="description" style="max-width:760px">
				هر کاربری که یکی از این نقش‌ها را داشته باشد، بدون تعریف جداگانه به پنل رزرو دسترسی پیدا می‌کند.
				مدیران کل همیشه دسترسی دارند.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:620px">
				<input type="hidden" name="action" value="cmb_save_roles">
				<?php wp_nonce_field( 'cmb_save_roles' ); ?>

				<?php
				$chosen = (array) get_option( 'cmb_panel_roles', array() );
				$counts = count_users();
				?>

				<fieldset style="margin:12px 0">
					<?php foreach ( wp_roles()->roles as $slug => $role ) : ?>
						<?php if ( 'administrator' === $slug ) { continue; } ?>
						<label style="display:block;margin-bottom:7px">
							<input type="checkbox" name="cmb_roles[]" value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $chosen, true ) ); ?> />
							<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
							<span class="description">
								(<?php echo esc_html( cmb_fa_num( isset( $counts['avail_roles'][ $slug ] ) ? $counts['avail_roles'][ $slug ] : 0 ) ); ?> کاربر)
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<?php submit_button( 'ذخیره‌ی نقش‌ها', 'secondary' ); ?>
			</form>

			<h2>دادن دسترسی به یک کاربر موجود</h2>

			<p class="description" style="max-width:760px">
				اگر می‌خواهید فقط یک نفر مشخص دسترسی داشته باشد بدون اینکه کل نقشش تغییر کند،
				نام کاربری یا ایمیلش را اینجا بنویسید.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:620px">
				<input type="hidden" name="action" value="cmb_grant_user">
				<?php wp_nonce_field( 'cmb_grant_user' ); ?>

				<p>
					<input name="cmb_lookup" class="regular-text" dir="ltr"
						placeholder="نام کاربری، ایمیل یا شماره موبایل" list="cmb-user-list" required>
					<button class="button">دادن دسترسی</button>
				</p>

				<datalist id="cmb-user-list">
					<?php foreach ( get_users( array( 'number' => 60, 'orderby' => 'registered', 'order' => 'DESC' ) ) as $u ) : ?>
						<option value="<?php echo esc_attr( $u->user_login ); ?>"><?php echo esc_attr( $u->display_name ); ?></option>
					<?php endforeach; ?>
				</datalist>
			</form>

			<h2>افزودن کاربر</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:620px">
				<input type="hidden" name="action" value="cmb_add_operator">
				<?php wp_nonce_field( 'cmb_add_operator' ); ?>

				<table class="form-table">
					<tr>
						<th><label for="cmb_user">نام کاربری</label></th>
						<td>
							<input name="cmb_user" id="cmb_user" class="regular-text" dir="ltr" required>
							<p class="description">اگر این نام کاربری از قبل وجود داشته باشد، فقط دسترسی پنل به آن داده می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th><label for="cmb_pass">رمز عبور</label></th>
						<td>
							<input name="cmb_pass" id="cmb_pass" class="regular-text" dir="ltr" autocomplete="new-password">
							<button type="button" class="button" id="cmb-gen">ساخت رمز تصادفی</button>
							<p class="description">حداقل ۸ کاراکتر. حتماً جایی یادداشتش کنید — بعداً قابل دیدن نیست.</p>
						</td>
					</tr>
					<tr>
						<th><label for="cmb_name">نام نمایشی</label></th>
						<td><input name="cmb_name" id="cmb_name" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="cmb_phone">شماره موبایل (اختیاری)</label></th>
						<td>
							<input name="cmb_phone" id="cmb_phone" class="regular-text" dir="ltr" inputmode="numeric" placeholder="09123456789">
							<p class="description">برای ورود به پنل با کد تایید پیامکی.</p>
						</td>
					</tr>
					<tr>
						<th><label for="cmb_email">ایمیل (اختیاری)</label></th>
						<td><input name="cmb_email" id="cmb_email" type="email" class="regular-text" dir="ltr"></td>
					</tr>
				</table>

				<?php submit_button( 'ساخت کاربر پنل' ); ?>
			</form>

			<h2>کاربران فعلی</h2>

			<?php if ( empty( $operators ) ) : ?>
				<p>هنوز کاربری برای پنل تعریف نشده است. مدیران کل سایت همیشه دسترسی دارند.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead>
						<tr>
							<th>نام کاربری</th>
							<th>نام نمایشی</th>
							<th>موبایل (ورود با کد)</th>
							<th>رمز تازه</th>
							<th>عملیات</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $operators as $op ) : ?>
						<tr>
							<td><code><?php echo esc_html( $op->user_login ); ?></code></td>
							<td><?php echo esc_html( $op->display_name ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px">
									<input type="hidden" name="action" value="cmb_operator_phone">
									<input type="hidden" name="cmb_user_id" value="<?php echo esc_attr( $op->ID ); ?>">
									<?php wp_nonce_field( 'cmb_operator_phone' ); ?>
									<input name="cmb_phone" dir="ltr" inputmode="numeric" placeholder="09123456789" style="width:140px"
										value="<?php echo esc_attr( cmb_get_user_phone( $op->ID ) ); ?>">
									<button class="button">ذخیره</button>
								</form>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px">
									<input type="hidden" name="action" value="cmb_reset_operator">
									<input type="hidden" name="cmb_user_id" value="<?php echo esc_attr( $op->ID ); ?>">
									<?php wp_nonce_field( 'cmb_reset_operator' ); ?>
									<input name="cmb_pass" dir="ltr" placeholder="رمز تازه" autocomplete="new-password">
									<button class="button">تغییر</button>
								</form>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									onsubmit="return confirm('دسترسی این کاربر به پنل گرفته شود؟');">
									<input type="hidden" name="action" value="cmb_revoke_operator">
									<input type="hidden" name="cmb_user_id" value="<?php echo esc_attr( $op->ID ); ?>">
									<?php wp_nonce_field( 'cmb_revoke_operator' ); ?>
									<button class="button button-link-delete">گرفتن دسترسی</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<script>
		document.getElementById('cmb-gen').addEventListener('click', function () {
			var c = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789@#%';
			var out = '';
			var a = new Uint32Array(14);
			(window.crypto || window.msCrypto).getRandomValues(a);
			for (var i = 0; i < 14; i++) { out += c[a[i] % c.length]; }
			var f = document.getElementById('cmb_pass');
			f.type = 'text';
			f.value = out;
			f.select();
		});
		</script>
		<?php
	}
}
