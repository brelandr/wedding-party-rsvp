import { expect, test } from '@playwright/test';

test.describe( 'RSVP form', () => {
	test( 'guest can submit an RSVP and the row appears in the admin list', async ( { page } ) => {
		await page.goto( '/rsvp/?party_id=E2E-PARTY' );

		await expect( page.locator( '.wpr-wrapper' ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'E2E Playwright Guest' } ) ).toBeVisible();

		await page.locator( 'select[name$="[rsvp]"]' ).selectOption( 'Accepted' );
		const menu = page.locator( 'select[name$="[menu]"]' );
		if ( await menu.count() ) {
			await menu.selectOption( { label: 'Chicken' } ).catch( async () => {
				await menu.selectOption( 'Chicken' );
			} );
		}

		await page.getByRole( 'button', { name: 'Submit RSVP' } ).click();
		await expect( page.getByText( 'Thank you! Your RSVP has been updated.' ) ).toBeVisible();

		await page.goto( '/wp-admin/admin.php?page=wedding-rsvp-main' );
		await expect( page.getByRole( 'heading', { name: 'Wedding Dashboard' } ) ).toBeVisible();

		await page.locator( 'input[name="s"]' ).fill( 'E2E Playwright Guest' );
		await page.getByRole( 'button', { name: 'Search' } ).click();

		const row = page.locator( 'table.wp-list-table tbody tr' ).filter( {
			has: page.locator( 'input[name="guest_name"][value="E2E Playwright Guest"]' ),
		} );
		await expect( row ).toHaveCount( 1 );
		await expect( row.locator( 'select[name="rsvp_status"]' ) ).toHaveValue( 'Accepted' );
	} );
} );
