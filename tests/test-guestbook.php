<?php
/**
 * Guestbook entry create and empty-message rejection.
 *
 * @package Wedding_Party_RSVP
 * @group all
 * @group guestbook
 */

/**
 * Tests for WGRSVP_Guestbook::ajax_submit().
 *
 * @group all
 * @group guestbook
 * @group ajax
 */
class WGRSVP_Test_Guestbook extends WP_Ajax_UnitTestCase {

	/**
	 * Seed the AJAX nonce and common POST fields.
	 *
	 * @param string $name    Author name.
	 * @param string $message Message.
	 * @return void
	 */
	private function wgrsvp_prime_guestbook_post( $name, $message ) {
		$this->_setRole( 'administrator' );
		$_POST['nonce']           = wp_create_nonce( 'wgrsvp_guestbook_submit' );
		$_POST['author_name']     = $name;
		$_POST['message']         = $message;
		$_POST['wgrsvp_gb_honey'] = '';
		$_POST['party_id']        = '';
		$_SERVER['REMOTE_ADDR']   = '203.0.113.50';
	}

	/**
	 * Dispatch the guestbook AJAX action and return decoded JSON.
	 *
	 * @return array<string,mixed>
	 */
	private function wgrsvp_dispatch_guestbook_ajax() {
		try {
			$this->_handleAjax( 'wgrsvp_guestbook_submit' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray( $decoded );
		return $decoded;
	}

	/**
	 * Valid entry stores name, message, and created_at.
	 */
	public function test_entry_creation_stores_message_name_and_timestamp() {
		global $wpdb;

		$this->wgrsvp_prime_guestbook_post( 'Casey Guest', 'Cannot wait for the party.' );
		$response = $this->wgrsvp_dispatch_guestbook_ajax();
		$this->assertTrue( $response['success'] );

		$table = WGRSVP_Guestbook::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT author_name, message, created_at FROM %i WHERE author_name = %s ORDER BY id DESC LIMIT 1',
				$table,
				'Casey Guest'
			)
		);

		$this->assertNotEmpty( $row );
		$this->assertSame( 'Casey Guest', $row->author_name );
		$this->assertSame( 'Cannot wait for the party.', $row->message );
		$this->assertNotEmpty( $row->created_at );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row->created_at );
	}

	/**
	 * Empty message is rejected and nothing is stored.
	 */
	public function test_empty_message_is_rejected() {
		global $wpdb;

		$table        = WGRSVP_Guestbook::table_name();
		$before_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

		$this->wgrsvp_prime_guestbook_post( 'Casey Guest', '' );
		$response = $this->wgrsvp_dispatch_guestbook_ajax();

		$this->assertFalse( $response['success'] );
		$this->assertArrayHasKey( 'data', $response );
		$this->assertSame( 'Name and message are required.', $response['data']['message'] );

		$after_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$this->assertSame( $before_count, $after_count );
	}
}
