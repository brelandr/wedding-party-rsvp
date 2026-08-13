<?php
/**
 * Guestbook spam protection: Google reCAPTCHA v3 (primary) + Cloudflare Turnstile backup.
 *
 * When Guestbook keys are empty, falls back to WSB Hub Connection keys
 * (`wsb_hub_recaptcha_*` / `wsb_hub_turnstile_*`) on the same site.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captcha settings, widget enqueue, and server-side verification.
 */
class WGRSVP_Guestbook_Captcha {

	public const OPTION_KEY     = 'wgrsvp_guestbook_captcha';
	public const ACTION_SUBMIT  = 'wgrsvp_guestbook';
	public const HUB_SITE       = 'wsb_hub_recaptcha_site_key';
	public const HUB_SECRET     = 'wsb_hub_recaptcha_secret_key';
	public const HUB_SCORE      = 'wsb_hub_recaptcha_min_score';
	public const HUB_TS_SITE    = 'wsb_hub_turnstile_site_key';
	public const HUB_TS_SECRET  = 'wsb_hub_turnstile_secret_key';

	/**
	 * Boot hooks (settings render is invoked from the settings page).
	 *
	 * @return void
	 */
	public static function register_hooks() {
		// Reserved for future admin_enqueue if needed.
	}

	/**
	 * Default empty local settings.
	 *
	 * @return array<string,string>
	 */
	public static function defaults() {
		return array(
			'recaptcha_site_key'   => '',
			'recaptcha_secret_key' => '',
			'turnstile_site_key'   => '',
			'turnstile_secret_key' => '',
			'recaptcha_min_score'  => '',
		);
	}

	/**
	 * Sanitize API key / site key material without stripping valid characters.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function sanitize_key_material( $raw ) {
		$raw = (string) $raw;
		$raw = wp_unslash( $raw );
		$raw = preg_replace( '/[\x00-\x1F\x7F]/', '', $raw );
		return is_string( $raw ) ? trim( $raw ) : '';
	}

	/**
	 * Local option only (no hub merge).
	 *
	 * @return array<string,string>
	 */
	public static function get_local_settings() {
		$opt = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		return array_merge( self::defaults(), $opt );
	}

	/**
	 * Effective keys: local overrides, else WSB Hub Connection options.
	 *
	 * @return array{recaptcha_site_key:string,recaptcha_secret_key:string,turnstile_site_key:string,turnstile_secret_key:string,recaptcha_min_score:float,recaptcha_source:string,turnstile_source:string}
	 */
	public static function get_effective_settings() {
		$local = self::get_local_settings();

		$g_site   = (string) $local['recaptcha_site_key'];
		$g_secret = (string) $local['recaptcha_secret_key'];
		$g_src    = 'local';
		if ( '' === $g_site || '' === $g_secret ) {
			$hub_site   = self::sanitize_key_material( (string) get_option( self::HUB_SITE, '' ) );
			$hub_secret = self::sanitize_key_material( (string) get_option( self::HUB_SECRET, '' ) );
			if ( '' !== $hub_site && '' !== $hub_secret ) {
				$g_site   = $hub_site;
				$g_secret = $hub_secret;
				$g_src    = 'hub';
			} else {
				$g_src = 'none';
			}
		}

		$t_site   = (string) $local['turnstile_site_key'];
		$t_secret = (string) $local['turnstile_secret_key'];
		$t_src    = 'local';
		if ( '' === $t_site || '' === $t_secret ) {
			$hub_ts_site   = self::sanitize_key_material( (string) get_option( self::HUB_TS_SITE, '' ) );
			$hub_ts_secret = self::sanitize_key_material( (string) get_option( self::HUB_TS_SECRET, '' ) );
			if ( '' !== $hub_ts_site && '' !== $hub_ts_secret ) {
				$t_site   = $hub_ts_site;
				$t_secret = $hub_ts_secret;
				$t_src    = 'hub';
			} else {
				$t_src = 'none';
			}
		}

		$score_raw = (string) $local['recaptcha_min_score'];
		if ( '' === $score_raw || ! is_numeric( $score_raw ) ) {
			$score_raw = (string) get_option( self::HUB_SCORE, '0.5' );
		}
		$score = is_numeric( $score_raw ) ? (float) $score_raw : 0.5;
		if ( $score < 0.0 ) {
			$score = 0.0;
		}
		if ( $score > 1.0 ) {
			$score = 1.0;
		}

		return array(
			'recaptcha_site_key'   => $g_site,
			'recaptcha_secret_key' => $g_secret,
			'turnstile_site_key'   => $t_site,
			'turnstile_secret_key' => $t_secret,
			'recaptcha_min_score'  => $score,
			'recaptcha_source'     => $g_src,
			'turnstile_source'     => $t_src,
		);
	}

	/**
	 * @deprecated Use get_effective_settings().
	 * @return array<string,string>
	 */
	public static function get_settings() {
		$e = self::get_effective_settings();
		return array(
			'recaptcha_site_key'   => $e['recaptcha_site_key'],
			'recaptcha_secret_key' => $e['recaptcha_secret_key'],
			'turnstile_site_key'   => $e['turnstile_site_key'],
			'turnstile_secret_key' => $e['turnstile_secret_key'],
		);
	}

	/**
	 * Whether Google site + secret are both available (local or hub).
	 *
	 * @param array<string,mixed>|null $opt Effective settings.
	 * @return bool
	 */
	public static function has_recaptcha_keys( $opt = null ) {
		$opt = is_array( $opt ) ? $opt : self::get_effective_settings();
		return '' !== (string) $opt['recaptcha_site_key'] && '' !== (string) $opt['recaptcha_secret_key'];
	}

	/**
	 * Whether Turnstile site + secret are both available (local or hub).
	 *
	 * @param array<string,mixed>|null $opt Effective settings.
	 * @return bool
	 */
	public static function has_turnstile_keys( $opt = null ) {
		$opt = is_array( $opt ) ? $opt : self::get_effective_settings();
		return '' !== (string) $opt['turnstile_site_key'] && '' !== (string) $opt['turnstile_secret_key'];
	}

	/**
	 * Preferred front-end provider: recaptcha | turnstile | none.
	 *
	 * @return string
	 */
	public static function active_provider() {
		$opt = self::get_effective_settings();
		if ( self::has_recaptcha_keys( $opt ) ) {
			return 'recaptcha';
		}
		if ( self::has_turnstile_keys( $opt ) ) {
			return 'turnstile';
		}
		return 'none';
	}

	/**
	 * Data for wp_localize_script.
	 *
	 * @return array<string,mixed>
	 */
	public static function front_config() {
		$opt = self::get_effective_settings();
		return array(
			'primary'          => self::active_provider(),
			'version'          => 'v3',
			'recaptchaSiteKey' => self::has_recaptcha_keys( $opt ) ? (string) $opt['recaptcha_site_key'] : '',
			'recaptchaAction'  => self::ACTION_SUBMIT,
			'turnstileSiteKey' => self::has_turnstile_keys( $opt ) ? (string) $opt['turnstile_site_key'] : '',
		);
	}

	/**
	 * Enqueue provider scripts when keys exist.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		$opt = self::get_effective_settings();
		if ( self::has_recaptcha_keys( $opt ) ) {
			$key = rawurlencode( (string) $opt['recaptcha_site_key'] );
			wp_enqueue_script(
				'wgrsvp-google-recaptcha',
				'https://www.google.com/recaptcha/api.js?render=' . $key,
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- remote API script; versioned by Google.
				true
			);
		}
		if ( self::has_turnstile_keys( $opt ) ) {
			wp_enqueue_script(
				'wgrsvp-cloudflare-turnstile',
				'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- remote API script; versioned by Cloudflare.
				true
			);
		}
	}

	/**
	 * Markup for the captcha mount point inside the guestbook form.
	 *
	 * @return string Escaped-safe HTML (static structure).
	 */
	public static function widget_html() {
		if ( ! self::has_recaptcha_keys() && ! self::has_turnstile_keys() ) {
			return '';
		}
		$html  = '<div class="wgrsvp-guestbook__captcha" data-wgrsvp-guestbook-captcha>';
		$html .= '<input type="hidden" name="g-recaptcha-response" value="" />';
		$html .= '<div class="wgrsvp-guestbook__captcha-slot" aria-live="polite"></div>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Verify submitted captcha token(s). True when no provider configured.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_request() {
		$opt  = self::get_effective_settings();
		$g_ok = self::has_recaptcha_keys( $opt );
		$t_ok = self::has_turnstile_keys( $opt );
		if ( ! $g_ok && ! $t_ok ) {
			return true;
		}

		$g_token = isset( $_POST['g-recaptcha-response'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AJAX nonce in caller.
			? self::sanitize_key_material( (string) wp_unslash( $_POST['g-recaptcha-response'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
			: '';
		$t_token = isset( $_POST['cf-turnstile-response'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? self::sanitize_key_material( (string) wp_unslash( $_POST['cf-turnstile-response'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
			: '';

		if ( $g_ok && '' !== $g_token && self::remote_verify_recaptcha_v3( $g_token, (string) $opt['recaptcha_secret_key'], (float) $opt['recaptcha_min_score'] ) ) {
			return true;
		}
		if ( $t_ok && '' !== $t_token && self::remote_verify_turnstile( $t_token, (string) $opt['turnstile_secret_key'] ) ) {
			return true;
		}

		return new WP_Error(
			'wgrsvp_guestbook_captcha',
			__( 'Please complete the security check and try again.', 'wedding-party-rsvp' )
		);
	}

	/**
	 * Google reCAPTCHA v3 siteverify (success + score + action).
	 *
	 * @param string $token  Response token.
	 * @param string $secret Secret key.
	 * @param float  $min    Minimum score.
	 * @return bool
	 */
	private static function remote_verify_recaptcha_v3( $token, $secret, $min ) {
		$response = wp_safe_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 15,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => self::client_ip(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return false;
		}
		$score = isset( $body['score'] ) ? (float) $body['score'] : 0.0;
		if ( $score < $min ) {
			return false;
		}
		$action = isset( $body['action'] ) ? (string) $body['action'] : '';
		return self::ACTION_SUBMIT === $action;
	}

	/**
	 * Cloudflare Turnstile siteverify.
	 *
	 * @param string $token  Response token.
	 * @param string $secret Secret key.
	 * @return bool
	 */
	private static function remote_verify_turnstile( $token, $secret ) {
		$response = wp_safe_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 15,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => self::client_ip(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && ! empty( $body['success'] );
	}

	/**
	 * Best-effort client IP for provider APIs.
	 *
	 * @return string
	 */
	private static function client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
	}

	/**
	 * Persist local settings from the general settings form POST.
	 *
	 * @return void
	 */
	public static function save_from_request() {
		$prev = self::get_local_settings();

		$recaptcha_site   = isset( $_POST['wgrsvp_gb_recaptcha_site_key'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verified settings nonce.
			? self::sanitize_key_material( (string) wp_unslash( $_POST['wgrsvp_gb_recaptcha_site_key'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
			: '';
		$recaptcha_secret = isset( $_POST['wgrsvp_gb_recaptcha_secret_key'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? self::sanitize_key_material( (string) wp_unslash( $_POST['wgrsvp_gb_recaptcha_secret_key'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
			: '';
		$turnstile_site   = isset( $_POST['wgrsvp_gb_turnstile_site_key'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? self::sanitize_key_material( (string) wp_unslash( $_POST['wgrsvp_gb_turnstile_site_key'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
			: '';
		$turnstile_secret = isset( $_POST['wgrsvp_gb_turnstile_secret_key'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? self::sanitize_key_material( (string) wp_unslash( $_POST['wgrsvp_gb_turnstile_secret_key'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
			: '';

		if ( '' === $recaptcha_secret && '' !== (string) $prev['recaptcha_secret_key'] ) {
			$recaptcha_secret = (string) $prev['recaptcha_secret_key'];
		}
		if ( '' === $turnstile_secret && '' !== (string) $prev['turnstile_secret_key'] ) {
			$turnstile_secret = (string) $prev['turnstile_secret_key'];
		}

		$next = array(
			'recaptcha_site_key'   => $recaptcha_site,
			'recaptcha_secret_key' => $recaptcha_secret,
			'turnstile_site_key'   => $turnstile_site,
			'turnstile_secret_key' => $turnstile_secret,
			'recaptcha_min_score'  => isset( $prev['recaptcha_min_score'] ) ? (string) $prev['recaptcha_min_score'] : '',
		);

		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, $next, '', 'no' );
		} else {
			update_option( self::OPTION_KEY, $next, false );
		}
	}

	/**
	 * Mask a secret for placeholders.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask_secret( $secret ) {
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return '';
		}
		if ( function_exists( 'wgrsvp_mask_license_key_for_display' ) ) {
			return wgrsvp_mask_license_key_for_display( $secret );
		}
		$len = strlen( $secret );
		if ( $len <= 4 ) {
			return '••••••••';
		}
		return str_repeat( '•', max( 8, $len - 4 ) ) . substr( $secret, -4 );
	}

	/**
	 * Settings card fields (inside general settings form).
	 *
	 * @return void
	 */
	public static function render_settings_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$local   = self::get_local_settings();
		$eff     = self::get_effective_settings();
		$g_ph    = self::mask_secret( (string) $local['recaptcha_secret_key'] );
		$t_ph    = self::mask_secret( (string) $local['turnstile_secret_key'] );
		$primary = self::active_provider();
		$hub_url = admin_url( 'admin.php?page=wsb-hub' );
		?>
		<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px;">
			<h3><?php esc_html_e( 'Guestbook spam protection', 'wedding-party-rsvp' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Optional. Uses Google reCAPTCHA v3 (invisible) when keys are available, with Cloudflare Turnstile as backup. Leave Guestbook fields blank to reuse keys from WSB Hub → Connection on this site.', 'wedding-party-rsvp' ); ?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: provider slug, 2: recaptcha source, 3: turnstile source */
					esc_html__( 'Active primary: %1$s (Google source: %2$s, Turnstile source: %3$s).', 'wedding-party-rsvp' ),
					esc_html( $primary ),
					esc_html( (string) $eff['recaptcha_source'] ),
					esc_html( (string) $eff['turnstile_source'] )
				);
				?>
				<?php if ( 'hub' === $eff['recaptcha_source'] || 'hub' === $eff['turnstile_source'] ) : ?>
					<a href="<?php echo esc_url( $hub_url ); ?>"><?php esc_html_e( 'Open WSB Hub Connection', 'wedding-party-rsvp' ); ?></a>
				<?php endif; ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Google reCAPTCHA v3 (override)', 'wedding-party-rsvp' ); ?></strong>
				— <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get keys', 'wedding-party-rsvp' ); ?></a>
			</p>
			<p>
				<label for="wgrsvp_gb_recaptcha_site_key"><strong><?php esc_html_e( 'reCAPTCHA site key', 'wedding-party-rsvp' ); ?></strong></label><br>
				<input type="text" class="regular-text" id="wgrsvp_gb_recaptcha_site_key" name="wgrsvp_gb_recaptcha_site_key" value="<?php echo esc_attr( (string) $local['recaptcha_site_key'] ); ?>" autocomplete="off" placeholder="<?php echo esc_attr( 'hub' === $eff['recaptcha_source'] ? (string) $eff['recaptcha_site_key'] : '' ); ?>">
			</p>
			<p>
				<label for="wgrsvp_gb_recaptcha_secret_key"><strong><?php esc_html_e( 'reCAPTCHA secret key', 'wedding-party-rsvp' ); ?></strong></label><br>
				<input type="text" class="regular-text" id="wgrsvp_gb_recaptcha_secret_key" name="wgrsvp_gb_recaptcha_secret_key" value="" placeholder="<?php echo esc_attr( $g_ph ); ?>" autocomplete="off">
				<?php if ( '' !== $g_ph ) : ?>
					<br><span class="description"><?php esc_html_e( 'Secret on file is masked. Leave blank to keep it, or enter a new key to replace.', 'wedding-party-rsvp' ); ?></span>
				<?php elseif ( 'hub' === $eff['recaptcha_source'] ) : ?>
					<br><span class="description"><?php esc_html_e( 'Using secret from WSB Hub Connection.', 'wedding-party-rsvp' ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Cloudflare Turnstile backup (override)', 'wedding-party-rsvp' ); ?></strong>
				— <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get keys', 'wedding-party-rsvp' ); ?></a>
			</p>
			<p>
				<label for="wgrsvp_gb_turnstile_site_key"><strong><?php esc_html_e( 'Turnstile site key', 'wedding-party-rsvp' ); ?></strong></label><br>
				<input type="text" class="regular-text" id="wgrsvp_gb_turnstile_site_key" name="wgrsvp_gb_turnstile_site_key" value="<?php echo esc_attr( (string) $local['turnstile_site_key'] ); ?>" autocomplete="off">
			</p>
			<p>
				<label for="wgrsvp_gb_turnstile_secret_key"><strong><?php esc_html_e( 'Turnstile secret key', 'wedding-party-rsvp' ); ?></strong></label><br>
				<input type="text" class="regular-text" id="wgrsvp_gb_turnstile_secret_key" name="wgrsvp_gb_turnstile_secret_key" value="" placeholder="<?php echo esc_attr( $t_ph ); ?>" autocomplete="off">
				<?php if ( '' !== $t_ph ) : ?>
					<br><span class="description"><?php esc_html_e( 'Secret on file is masked. Leave blank to keep it, or enter a new key to replace.', 'wedding-party-rsvp' ); ?></span>
				<?php elseif ( 'hub' === $eff['turnstile_source'] ) : ?>
					<br><span class="description"><?php esc_html_e( 'Using secret from WSB Hub Connection.', 'wedding-party-rsvp' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
