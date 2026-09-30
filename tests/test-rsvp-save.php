<?php
/**
 * RSVP save: create guest rows and reject incomplete payloads.
 *
 * @package Wedding_Party_RSVP
 * @group all
 * @group rsvp
 */

/**
 * Tests for WGRSVP_Mobile_REST::rest_create_guest().
 *
 * @group all
 * @group rsvp
 */
class WGRSVP_Test_Rsvp_Save extends WP_UnitTestCase {

	/**
	 * Build a JSON REST request for guest create.
	 *
	 * @param array<string,mixed> $body JSON body.
	 * @return WP_REST_Request
	 */
	private function wgrsvp_create_request( $body ) {
		$request = new WP_REST_Request( 'POST', '/' . WGRSVP_App_Config::REST_NAMESPACE . '/mobile/guests' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * Valid submission writes the expected columns.
	 */
	public function test_valid_submission_saves_guest_to_database() {
		global $wpdb;

		$request = $this->wgrsvp_create_request(
			array(
				'name'         => 'Ada Lovelace',
				'partyId'      => 'HOUSE-ADA',
				'email'        => 'ada@example.com',
				'phone'        => '555-0100',
				'status'       => 'Accepted',
				'menuChoice'   => 'Salmon',
				'dietaryNotes' => 'No nuts',
			)
		);

		$result = WGRSVP_Mobile_REST::rest_create_guest( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );

		$data = $result->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'Ada Lovelace', $data['name'] );
		$this->assertSame( 'HOUSE-ADA', $data['partyId'] );
		$this->assertSame( 'ada@example.com', $data['email'] );
		$this->assertSame( 'Accepted', $data['status'] );
		$this->assertSame( 'Salmon', $data['menuChoice'] );

		$table = $wpdb->prefix . 'wedding_rsvps';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT guest_name, party_id, email, phone, rsvp_status, menu_choice, dietary_restrictions FROM %i WHERE id = %d',
				$table,
				(int) $data['id']
			)
		);

		$this->assertNotEmpty( $row );
		$this->assertSame( 'Ada Lovelace', $row->guest_name );
		$this->assertSame( 'HOUSE-ADA', $row->party_id );
		$this->assertSame( 'ada@example.com', $row->email );
		$this->assertSame( '555-0100', $row->phone );
		$this->assertSame( 'Accepted', $row->rsvp_status );
		$this->assertSame( 'Salmon', $row->menu_choice );
		$this->assertSame( 'No nuts', $row->dietary_restrictions );
	}

	/**
	 * Missing name and Party ID return WP_Error.
	 */
	public function test_missing_required_fields_returns_wp_error() {
		$result = WGRSVP_Mobile_REST::rest_create_guest(
			$this->wgrsvp_create_request(
				array(
					'email' => 'nobody@example.com',
				)
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wgrsvp_guest_required', $result->get_error_code() );
		$this->assertSame( 400, (int) $result->get_error_data()['status'] );
	}

	/**
	 * Name without Party ID is also rejected.
	 */
	public function test_missing_party_id_returns_wp_error() {
		$result = WGRSVP_Mobile_REST::rest_create_guest(
			$this->wgrsvp_create_request(
				array(
					'name' => 'No Party',
				)
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wgrsvp_guest_required', $result->get_error_code() );
	}
}
