<?php
/**
 * قالب فرم رزرو نوبت (ویزارد چندمرحله‌ای).
 *
 * متغیرهای در دسترس: $branch, $services, $branch_id
 *
 * @var object   $branch
 * @var object[] $services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cmb_settings = CMB_Settings::all();
$cmb_user     = is_user_logged_in() ? wp_get_current_user() : null;
$cmb_phone    = $cmb_user ? cmb_get_user_phone( $cmb_user->ID ) : '';
?>
<div class="cmb-wrap" id="cmb-booking" data-branch="<?php echo esc_attr( $branch ? $branch->id : 0 ); ?>" dir="rtl">

	<!-- نوار مراحل -->
	<ol class="cmb-steps" aria-label="مراحل رزرو">
		<li class="cmb-step is-active" data-step="service"><span>۱</span> انتخاب خدمت</li>
		<li class="cmb-step" data-step="slot"><span>۲</span> تاریخ و ساعت</li>
		<li class="cmb-step" data-step="auth"><span>۳</span> ورود</li>
		<li class="cmb-step" data-step="details"><span>۴</span> مشخصات خودرو</li>
		<li class="cmb-step" data-step="done"><span>۵</span> تاییدیه</li>
	</ol>

	<div class="cmb-alert" id="cmb-alert" role="alert" hidden></div>

	<!-- گام ۱: انتخاب خدمت -->
	<section class="cmb-panel is-active" data-panel="service">
		<h3 class="cmb-panel__title">خدمت مورد نظر را انتخاب کنید</h3>

		<div class="cmb-services">
			<?php foreach ( $services as $cmb_service ) : ?>
				<?php $cmb_poster = $cmb_service->poster_id ? wp_get_attachment_image_url( (int) $cmb_service->poster_id, 'large' ) : ''; ?>
				<article class="cmb-service" data-service-id="<?php echo esc_attr( $cmb_service->id ); ?>" tabindex="0" role="button" aria-pressed="false">
					<header class="cmb-service__head">
						<h4><?php echo esc_html( $cmb_service->title ); ?></h4>
						<?php if ( $cmb_service->price ) : ?>
							<span class="cmb-service__price"><?php echo esc_html( cmb_fa_num( number_format( (int) $cmb_service->price ) ) ); ?> تومان</span>
						<?php endif; ?>
					</header>

					<?php if ( $cmb_poster ) : ?>
						<div class="cmb-service__poster">
							<img src="<?php echo esc_url( $cmb_poster ); ?>" alt="<?php echo esc_attr( 'پوستر ' . $cmb_service->title ); ?>" loading="lazy" />
						</div>
					<?php endif; ?>

					<?php if ( $cmb_service->description ) : ?>
						<p class="cmb-service__desc"><?php echo esc_html( $cmb_service->description ); ?></p>
					<?php endif; ?>

					<ul class="cmb-service__meta">
						<?php if ( $cmb_service->duration_note ) : ?>
							<li>⏱ مدت تخمینی: <?php echo esc_html( $cmb_service->duration_note ); ?></li>
						<?php endif; ?>
						<li>📅 روزهای ارائه: <?php echo esc_html( CMB_Services::weekday_text( CMB_Services::allowed_weekdays( $cmb_service ) ) ); ?></li>
					</ul>

					<button type="button" class="cmb-btn cmb-btn--primary cmb-select-service">انتخاب و ادامه</button>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ( ! empty( $cmb_settings['ecu_note'] ) ) : ?>
			<p class="cmb-note">ℹ️ <?php echo esc_html( $cmb_settings['ecu_note'] ); ?></p>
		<?php endif; ?>
	</section>

	<!-- گام ۲: تاریخ و شیفت -->
	<section class="cmb-panel" data-panel="slot">
		<h3 class="cmb-panel__title">تاریخ و شیفت را انتخاب کنید</h3>
		<p class="cmb-panel__sub" id="cmb-slot-service"></p>

		<div class="cmb-calendar" id="cmb-calendar">
			<div class="cmb-loading">در حال دریافت ظرفیت‌ها…</div>
		</div>

		<?php if ( ! empty( $cmb_settings['out_of_window_note'] ) ) : ?>
			<p class="cmb-note">📞 <?php echo esc_html( $cmb_settings['out_of_window_note'] ); ?>
			<?php if ( ! empty( $branch->phone ) ) : ?>
				<a href="tel:<?php echo esc_attr( $branch->phone ); ?>"><?php echo esc_html( cmb_fa_num( $branch->phone ) ); ?></a>
			<?php endif; ?>
			</p>
		<?php endif; ?>

		<div class="cmb-actions">
			<button type="button" class="cmb-btn cmb-back" data-target="service">بازگشت</button>
		</div>
	</section>

	<!-- گام ۳: ورود با کد تایید -->
	<section class="cmb-panel" data-panel="auth">
		<h3 class="cmb-panel__title">ورود با شماره موبایل</h3>
		<p class="cmb-panel__sub">برای ثبت نوبت، شماره‌ی موبایل خود را تایید کنید. اگر قبلاً ثبت‌نام نکرده‌اید، حساب شما به‌صورت خودکار ساخته می‌شود.</p>

		<div class="cmb-auth" id="cmb-auth">
			<!-- مرحله‌ی شماره -->
			<div class="cmb-auth__phone" id="cmb-auth-phone" <?php echo $cmb_user ? 'hidden' : ''; ?>>
				<label class="cmb-field">
					<span>شماره موبایل <i>*</i></span>
					<input type="tel" id="cmb-phone" inputmode="numeric" autocomplete="tel" maxlength="15" placeholder="09123456789" value="<?php echo esc_attr( $cmb_phone ); ?>" />
				</label>
				<button type="button" class="cmb-btn cmb-btn--primary" id="cmb-send-otp">ارسال کد تایید</button>
			</div>

			<!-- مرحله‌ی کد -->
			<div class="cmb-auth__code" id="cmb-auth-code" hidden>
				<p class="cmb-auth__hint">کد ۵ رقمی ارسال‌شده به <b id="cmb-phone-label"></b> را وارد کنید.
					<button type="button" class="cmb-link" id="cmb-change-phone">تغییر شماره</button>
				</p>

				<div class="cmb-otp-inputs" id="cmb-otp-inputs" dir="ltr">
					<input type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code" />
					<input type="text" inputmode="numeric" maxlength="1" />
					<input type="text" inputmode="numeric" maxlength="1" />
					<input type="text" inputmode="numeric" maxlength="1" />
					<input type="text" inputmode="numeric" maxlength="1" />
				</div>

				<label class="cmb-field cmb-field--name" id="cmb-name-field" hidden>
					<span>نام و نام خانوادگی <i>*</i></span>
					<input type="text" id="cmb-auth-name" autocomplete="name" placeholder="مثلاً رضا محمدی" />
				</label>

				<button type="button" class="cmb-btn cmb-btn--primary" id="cmb-verify-otp">تایید و ادامه</button>
				<button type="button" class="cmb-link cmb-resend" id="cmb-resend" disabled></button>
			</div>

			<!-- کاربر واردشده -->
			<div class="cmb-auth__logged" id="cmb-auth-logged" <?php echo $cmb_user ? '' : 'hidden'; ?>>
				<p>وارد شده‌اید با شماره <b id="cmb-logged-phone"><?php echo esc_html( cmb_fa_num( $cmb_phone ) ); ?></b></p>
				<button type="button" class="cmb-btn cmb-btn--primary" id="cmb-continue-logged">ادامه</button>
				<button type="button" class="cmb-link" id="cmb-logout">خروج از حساب</button>
			</div>
		</div>

		<div class="cmb-actions">
			<button type="button" class="cmb-btn cmb-back" data-target="slot">بازگشت</button>
		</div>
	</section>

	<!-- گام ۴: مشخصات خودرو -->
	<section class="cmb-panel" data-panel="details">
		<h3 class="cmb-panel__title">مشخصات خودرو</h3>

		<div class="cmb-summary" id="cmb-summary"></div>

		<form class="cmb-form" id="cmb-details-form" novalidate>
			<label class="cmb-field">
				<span>نام و نام خانوادگی <i>*</i></span>
				<input type="text" name="name" id="cmb-name" autocomplete="name" value="<?php echo esc_attr( $cmb_user ? $cmb_user->display_name : '' ); ?>" required />
			</label>

			<label class="cmb-field">
				<span>شهر <i>*</i></span>
				<input type="text" name="city" id="cmb-city" list="cmb-city-list" autocomplete="address-level2" placeholder="مثلاً بوشهر" required />
				<datalist id="cmb-city-list">
					<?php foreach ( cmb_city_suggestions() as $cmb_city ) : ?>
						<option value="<?php echo esc_attr( $cmb_city ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			</label>

			<div class="cmb-grid-2">
				<label class="cmb-field">
					<span>نوع خودرو <i>*</i></span>
					<input type="text" name="car_brand" id="cmb-car-brand" placeholder="مثلاً ۲۰۷" required />
				</label>

				<label class="cmb-field">
					<span>نوع موتور <i>*</i></span>
					<input type="text" name="car_model" id="cmb-car-model" placeholder="مثلاً TU5" required />
				</label>

				<label class="cmb-field">
					<span>سال ساخت <i>*</i></span>
					<select name="car_year" id="cmb-car-year" required>
						<option value="" disabled selected>انتخاب کنید</option>
						<?php foreach ( cmb_car_years() as $cmb_year ) : ?>
							<option value="<?php echo esc_attr( $cmb_year ); ?>"><?php echo esc_html( cmb_fa_num( $cmb_year ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="cmb-field">
					<span>کارکرد (کیلومتر)</span>
					<input type="text" name="car_mileage" id="cmb-car-mileage" inputmode="numeric" placeholder="۱۲۰۰۰۰" />
				</label>
			</div>

			<label class="cmb-field">
				<span>توضیحات (اختیاری)</span>
				<textarea name="note" id="cmb-note" rows="3" placeholder="ECU خودرو چیست؟"></textarea>
			</label>

			<?php if ( ! empty( $cmb_settings['ecu_note'] ) ) : ?>
				<p class="cmb-note">ℹ️ <?php echo esc_html( $cmb_settings['ecu_note'] ); ?></p>
			<?php endif; ?>

			<div class="cmb-actions">
				<button type="button" class="cmb-btn cmb-back" data-target="slot">بازگشت</button>
				<button type="submit" class="cmb-btn cmb-btn--primary" id="cmb-submit">ثبت نهایی نوبت</button>
			</div>
		</form>
	</section>

	<!-- گام ۵: تاییدیه -->
	<section class="cmb-panel" data-panel="done">
		<div class="cmb-receipt" id="cmb-receipt"></div>
	</section>
</div>
