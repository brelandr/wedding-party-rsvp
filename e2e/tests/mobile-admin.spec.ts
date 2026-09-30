import { expect, test } from '@playwright/test';

test.describe( 'Mobile admin', () => {
	test.use( { viewport: { width: 375, height: 812 } } );

	test( 'Mobile App screen renders at 375px', async ( { page } ) => {
		await page.setViewportSize( { width: 375, height: 812 } );
		await page.goto( '/wp-admin/admin.php?page=wedding-rsvp-mobile-app' );

		await expect( page.getByRole( 'heading', { name: 'Mobile App' } ) ).toBeVisible();
		await expect( page.locator( '#wgrsvp-mobile-site-url' ) ).toBeVisible();
		await expect( page.locator( '#wgrsvp-mobile-connect-url' ) ).toBeVisible();
		await expect( page.locator( '#wgrsvp-mobile-join-url' ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'Connection details' } ) ).toBeVisible();

		const viewport = page.viewportSize();
		expect( viewport?.width ).toBe( 375 );

		const wrapBox = await page.locator( '.wrap' ).first().boundingBox();
		expect( wrapBox ).not.toBeNull();
		expect( wrapBox!.width ).toBeLessThanOrEqual( 375 );
	} );
} );
