import { expect, test } from '@playwright/test';

test.describe( 'Admin RSVP list', () => {
	test( 'guest list columns are sortable', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wedding-rsvp-main' );
		await expect( page.getByRole( 'heading', { name: 'Wedding Dashboard' } ) ).toBeVisible();
		await expect( page.locator( 'table.wp-list-table' ) ).toBeVisible();

		await page.getByRole( 'link', { name: 'Name' } ).click();
		await expect( page ).toHaveURL( /orderby=guest_name/ );
		await expect( page.locator( 'table.wp-list-table tbody tr' ).first() ).toBeVisible();

		const firstName = page.locator( 'table.wp-list-table tbody tr' ).first().locator( 'input[name="guest_name"]' );
		await expect( firstName ).toHaveValue( /AAA E2E First/ );

		await page.getByRole( 'link', { name: 'Name' } ).click();
		await expect( page ).toHaveURL( /orderby=guest_name.*order=DESC|order=DESC.*orderby=guest_name/ );
		await expect( page.locator( 'table.wp-list-table tbody tr' ).first().locator( 'input[name="guest_name"]' ) ).toHaveValue( /ZZZ E2E Last/ );
	} );

	test( 'guest table view paginates seeded rows', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wedding-rsvp-guest-table' );
		await expect( page.getByRole( 'heading', { name: 'Guest table' } ) ).toBeVisible();

		const root = page.locator( '#wgrsvp-dataviews-spike-root' );
		await expect( root ).toBeVisible();

		await expect( page.getByLabel( 'Next page' ) ).toBeVisible( { timeout: 20_000 } );
		await expect( page.getByLabel( 'Next page' ) ).toBeEnabled();

		await page.getByLabel( 'Next page' ).click();
		await expect( page.getByLabel( 'Previous page' ) ).toBeEnabled();
	} );
} );
