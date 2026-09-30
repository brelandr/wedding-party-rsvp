<?php
/**
 * REST permission filters on protected coordinator endpoints.
 *
 * @package Wedding_Party_RSVP
 * @group all
 * @group rest
 */

/**
 * Unauthenticated vs authenticated access to wgrsvp/v1 routes.
 *
 * @group all
 * @group rest
 */
class WGRSVP_Test_Rest_Filters extends WP_UnitTestCase {

	/**
	 * Protected coordinator routes under test.
	 *
	 * @return array<int,string>
	 */
	private function wgrsvp_protected_routes() {
		return array(
			'/' . WGRSVP_App_Config::REST_NAMESPACE . '/mobile/dashboard',
			'/' . WGRSVP_App_Config::REST_NAMESPACE . '/guest-rows',
		);
	}

	/**
	 * Dispatch a GET and return the WP_REST_Response.
	 *
	 * @param string $route Route.
	 * @return WP_REST_Response
	 */
	private function wgrsvp_get_route( $route ) {
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * Logged-out requests receive 401.
	 */
	public function test_unauthenticated_requests_return_401() {
		wp_set_current_user( 0 );

		foreach ( $this->wgrsvp_protected_routes() as $route ) {
			$response = $this->wgrsvp_get_route( $route );
			$this->assertSame(
				401,
				$response->get_status(),
				'Expected 401 for ' . $route
			);
		}
	}

	/**
	 * An administrator receives 200.
	 */
	public function test_authenticated_requests_return_200() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		foreach ( $this->wgrsvp_protected_routes() as $route ) {
			$response = $this->wgrsvp_get_route( $route );
			$this->assertSame(
				200,
				$response->get_status(),
				'Expected 200 for ' . $route
			);
		}
	}
}
