<?php
/**
 * Event capacity: declines stay open, extra yes replies waitlist.
 *
 * @package Wedding_Party_RSVP
 * @group all
 * @group rsvp
 */

/**
 * Tests for WGRSVP_Capacity::resolve_batch().
 *
 * @group all
 * @group rsvp
 */
class WGRSVP_Test_Event_Capacity extends WP_UnitTestCase {

	/**
	 * Settings before this test.
	 *
	 * @var mixed
	 */
	private $wgrsvp_prev_settings;

	/**
	 * Remember general settings.
	 */
	public function set_up() {
		parent::set_up();
		$this->wgrsvp_prev_settings = get_option( 'wgrsvp_general_settings', array() );
	}

	/**
	 * Restore general settings.
	 */
	public function tear_down() {
		global $wpdb;
		update_option( 'wgrsvp_general_settings', $this->wgrsvp_prev_settings );
		$wpdb->delete( $wpdb->prefix . 'wedding_rsvps', array( 'party_id' => 'CAP-HOUSE' ) );
		parent::tear_down();
	}

	/**
	 * Insert a guest row.
	 *
	 * @param string $name   Guest name.
	 * @param string $status RSVP status.
	 * @return int
	 */
	private function wgrsvp_insert_guest( $name, $status ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'wedding_rsvps',
			array(
				'party_id'    => 'CAP-HOUSE',
				'guest_name'  => $name,
				'rsvp_status' => $status,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Store a capacity on the general settings option.
	 *
	 * @param int $capacity Cap. Zero disables it.
	 */
	private function wgrsvp_set_capacity( $capacity ) {
		$settings = get_option( 'wgrsvp_general_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['event_capacity'] = absint( $capacity );
		update_option( 'wgrsvp_general_settings', $settings );
	}

	/**
	 * A cap of zero stores a yes.
	 */
	public function test_cap_off_leaves_accepted() {
		$this->wgrsvp_set_capacity( 0 );
		$resolved = WGRSVP_Capacity::resolve_one( 0, 'Accepted', 'Pending', WGRSVP_Capacity::CONTEXT_GUEST );
		$this->assertSame( 'Accepted', $resolved );
	}

	/**
	 * A full event still stores a decline, waitlists the next yes, and keeps an existing yes.
	 */
	public function test_full_event_waitlists_new_yes_and_keeps_decline() {
		$this->wgrsvp_set_capacity( 1 );
		$seated  = $this->wgrsvp_insert_guest( 'Already Seated', 'Accepted' );
		$pending = $this->wgrsvp_insert_guest( 'Still Out', 'Pending' );

		$decline = WGRSVP_Capacity::resolve_batch(
			array(
				array(
					'id'        => $pending,
					'requested' => 'Declined',
				),
			),
			WGRSVP_Capacity::CONTEXT_GUEST
		);
		$this->assertSame( 'Declined', $decline[ $pending ] );

		$wait = WGRSVP_Capacity::resolve_batch(
			array(
				array(
					'id'        => $pending,
					'requested' => 'Accepted',
				),
			),
			WGRSVP_Capacity::CONTEXT_GUEST
		);
		$this->assertSame( 'Waitlist', $wait[ $pending ] );

		$keep = WGRSVP_Capacity::resolve_batch(
			array(
				array(
					'id'        => $seated,
					'requested' => 'Accepted',
				),
			),
			WGRSVP_Capacity::CONTEXT_GUEST
		);
		$this->assertSame( 'Accepted', $keep[ $seated ] );
	}

	/**
	 * An administrator can store a yes after the cap is full.
	 */
	public function test_admin_context_can_accept_when_full() {
		$this->wgrsvp_set_capacity( 1 );
		$this->wgrsvp_insert_guest( 'Already Seated', 'Accepted' );
		$pending = $this->wgrsvp_insert_guest( 'Needs A Seat', 'Pending' );

		$resolved = WGRSVP_Capacity::resolve_batch(
			array(
				array(
					'id'        => $pending,
					'requested' => 'Accepted',
				),
			),
			WGRSVP_Capacity::CONTEXT_ADMIN
		);
		$this->assertSame( 'Accepted', $resolved[ $pending ] );
	}

	/**
	 * The lower guest id gets the open seat when a household straddles the cap.
	 */
	public function test_household_seats_are_given_in_id_order() {
		$this->wgrsvp_set_capacity( 1 );
		$first  = $this->wgrsvp_insert_guest( 'First', 'Pending' );
		$second = $this->wgrsvp_insert_guest( 'Second', 'Pending' );

		$resolved = WGRSVP_Capacity::resolve_batch(
			array(
				array(
					'id'        => $second,
					'requested' => 'Accepted',
				),
				array(
					'id'        => $first,
					'requested' => 'Accepted',
				),
			),
			WGRSVP_Capacity::CONTEXT_GUEST
		);

		$this->assertSame( 'Accepted', $resolved[ $first ] );
		$this->assertSame( 'Waitlist', $resolved[ $second ] );
	}
}
