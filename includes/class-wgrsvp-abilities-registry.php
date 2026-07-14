<?php
/**
 * WordPress 7.0+ Abilities API registrations for the free plugin.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Abilities_Registry' ) ) {
	/**
	 * Registers free AI wording as an ability when the API is available.
	 */
	class WGRSVP_Abilities_Registry {

		const CATEGORY = 'wedding-party-rsvp';

		/**
		 * Hook category + ability registration when the Abilities API is available.
		 *
		 * Categories must use `wp_abilities_api_categories_init` (WP 6.9+).
		 * Abilities must use `wp_abilities_api_init`.
		 *
		 * @return void
		 */
		public static function init() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}
			if ( function_exists( 'wp_register_ability_category' ) ) {
				add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
			}
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
		}

		/**
		 * Register the plugin's ability category.
		 *
		 * @since 8.2.6
		 * @return void
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}
			wp_register_ability_category(
				self::CATEGORY,
				array(
					'label'       => __( 'Wedding Party RSVP', 'wedding-party-rsvp' ),
					'description' => __( 'Free RSVP admin helpers.', 'wedding-party-rsvp' ),
				)
			);
		}

		/**
		 * Register the plugin's abilities (category must already be registered).
		 *
		 * @return void
		 */
		public static function register() {
			wp_register_ability(
				self::CATEGORY . '/ai-wording',
				array(
					'label'               => __( 'Draft settings wording', 'wedding-party-rsvp' ),
					'description'         => __( 'Generate draft welcome, deadline, or snippet copy.', 'wedding-party-rsvp' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'context' => array( 'type' => 'string' ),
							'goals'   => array( 'type' => 'string' ),
						),
						'required'   => array( 'context' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'text' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( __CLASS__, 'execute_ai_wording' ),
					'permission_callback' => array( __CLASS__, 'permission_admin' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
						'show_in_rest' => true,
					),
				)
			);
		}

		/**
		 * Permission callback: site administrators only.
		 *
		 * @return bool
		 */
		public static function permission_admin() {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Execute the AI wording ability via the WordPress AI Client.
		 *
		 * @param array<string,mixed>|mixed $input Ability input.
		 * @return array<string,string>|\WP_Error
		 */
		public static function execute_ai_wording( $input ) {
			if ( ! function_exists( 'wgrsvp_wp70_ai_available' ) || ! wgrsvp_wp70_ai_available() ) {
				return new WP_Error( 'wgrsvp_ai_unavailable', __( 'WordPress AI Client is not available.', 'wedding-party-rsvp' ) );
			}
			$data    = is_array( $input ) ? $input : array();
			$context = isset( $data['context'] ) ? sanitize_key( (string) $data['context'] ) : '';
			$goals   = isset( $data['goals'] ) ? sanitize_textarea_field( (string) $data['goals'] ) : '';
			$task    = __( 'Write concise, warm wedding-event copy for guests.', 'wedding-party-rsvp' );
			if ( 'welcome_title' === $context ) {
				$task = __( 'Write a short welcome headline for the public RSVP page (plain text, no HTML, under 80 characters if possible).', 'wedding-party-rsvp' );
			} elseif ( 'deadline_closed_message' === $context ) {
				$task = __( 'Write a polite message for when the RSVP deadline has passed. Use simple HTML only: p, strong, em, br, a (href only if the site owner adds a real URL in their guidance).', 'wedding-party-rsvp' );
			} elseif ( 'save_the_date' === $context ) {
				$task = __( 'Write a short “save the date” paragraph for email or a site block (plain text, 2–4 sentences). Do not invent URLs or dates unless given in the extra guidance.', 'wedding-party-rsvp' );
			} elseif ( 'rsvp_deadline_reminder' === $context ) {
				$task = __( 'Write a short RSVP deadline reminder paragraph guests might see on the site or in email (plain text). Mention replying by the deadline without inventing a specific date unless provided in guidance.', 'wedding-party-rsvp' );
			} else {
				return new WP_Error( 'wgrsvp_ai_context', __( 'Unknown assistant context.', 'wedding-party-rsvp' ) );
			}
			$prompt  = $task . "\n\n";
			$prompt .= __( 'Do not include markdown fences. Do not invent URLs. Output only the final copy.', 'wedding-party-rsvp' ) . "\n";
			if ( '' !== $goals ) {
				$prompt .= __( 'Extra guidance from the site owner:', 'wedding-party-rsvp' ) . ' ' . $goals . "\n";
			}
			$prompt = (string) apply_filters( 'wgrsvp_ai_wording_prompt', $prompt, $context );
			$text   = wgrsvp_wp70_generate_text( $prompt, 'wording_' . $context );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
			if ( 'deadline_closed_message' === $context ) {
				$text = wp_kses_post( $text );
			} elseif ( 'welcome_title' === $context ) {
				$text = sanitize_text_field( $text );
			} else {
				$text = sanitize_textarea_field( $text );
			}
			return array( 'text' => (string) $text );
		}
	}
}
