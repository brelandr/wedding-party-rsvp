<?php
/**
 * RSVP guest update: PATCH changes only the submitted fields.
 *
 * @package Wedding_Party_RSVP
 * @group all
 * @group rsvp
 */

/**
 * Tests for WGRSVP_Mobile_REST::rest_patch_guest().
 *
 * @group all
 * @group rsvp
 */
class WGRSVP_Test_Rsvp_Guest_Update extends WP_UnitTestCase {

	/**
	 * Insert a guest and return the serialized row.
	 *
	 * @return array<string,mixed>
	 */
	private function wgrsvp_insert_guest() {
		$request = new WP_REST_Request( 'POST', '/' . WGRSVP_App_Config::REST_NAMESPACE . '/mobile/guests' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'name'       => 'Original Name',
					'partyId'    => 'HOUSE-UPD',
					'email'      => 'original@example.com',
					'phone'      => '555-0200',
					'status'     => 'Pending',
					'menuChoice' => 'Chicken',
					'address'    => '1 First St',
				)
			)
		);

		$created = WGRSVP_Mobile_REST::rest_create_guest( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $created );
		return $created->get_data();
	}

	/**
	 * Updating email and status leaves name, party, phone, and meal alone.
	 */
	public function test_update_changes_only_expected_fields() {
		global $wpdb;

		$created = $this->wgrsvp_insert_guest();
		$id      = (int) $created['id'];

		$request = new WP_REST_Request( 'PATCH', '/' . WGRSVP_App_Config::REST_NAMESPACE . '/mobile/guests/' . $id );
		$request->set_url_params( array( 'id' => $id ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'email'  => 'updated@example.com',
					'status' => 'Accepted',
				)
			)
		);

		$result = WGRSVP_Mobile_REST::rest_patch_guest( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $result );

		$data = $result->get_data();
		$this->assertSame( 'updated@example.com', $data['email'] );
		$this->assertSame( 'Accepted', $data['status'] );
		$this->assertSame( 'Original Name', $data['name'] );
		$this->assertSame( 'HOUSE-UPD', $data['partyId'] );
		$this->assertSame( '555-0200', $data['phone'] );
		$this->assertSame( 'Chicken', $data['menuChoice'] );
		$this->assertSame( '1 First St', $data['address'] );

		$table = $wpdb->prefix . 'wedding_rsvps';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT guest_name, party_id, email, phone, rsvp_status, menu_choice, address FROM %i WHERE id = %d',
				$table,
				$id
			)
		);

		$this->assertSame( 'Original Name', $row->guest_name );
		$this->assertSame( 'HOUSE-UPD', $row->party_id );
		$this->assertSame( 'updated@example.com', $row->email );
		$this->assertSame( '555-0200', $row->phone );
		$this->assertSame( 'Accepted', $row->rsvp_status );
		$this->assertSame( 'Chicken', $row->menu_choice );
		$this->assertSame( '1 First St', $row->address );
	}

	/**
	 * Empty PATCH body is rejected.
	 */
	public function test_update_with_no_fields_returns_wp_error() {
		$created = $this->wgrsvp_insert_guest();
		$id      = (int) $created['id'];

		$request = new WP_REST_Request( 'PATCH', '/' . WGRSVP_App_Config::REST_NAMESPACE . '/mobile/guests/' . $id );
		$request->set_url_params( array( 'id' => $id ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array() ) );

		$result = WGRSVP_Mobile_REST::rest_patch_guest( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'wgrsvp_no_fields', $result->get_error_code() );
	}
}
