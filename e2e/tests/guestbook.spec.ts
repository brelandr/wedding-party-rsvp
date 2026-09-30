import { expect, test } from '@playwright/test';

test.describe( 'Guestbook', () => {
	test( 'submitting a note shows it on the page', async ( { page } ) => {
		const stamp = Date.now();
		const author = `E2E Guest ${ stamp }`;
		const message = `Congratulations from Playwright ${ stamp }`;

		await page.goto( '/guestbook/' );
		await expect( page.locator( '[data-wgrsvp-guestbook]' ) ).toBeVisible();

		await page.locator( '#wgrsvp-gb-name' ).fill( author );
		await page.locator( '#wgrsvp-gb-message' ).fill( message );
		await page.locator( '.wgrsvp-guestbook__submit' ).click();

		await expect( page.locator( '.wgrsvp-guestbook__status' ) ).toContainText( /Thank you/i );

		await page.reload();
		await expect( page.locator( '.wgrsvp-guestbook__entry' ).filter( { hasText: message } ) ).toBeVisible();
		await expect( page.locator( '.wgrsvp-guestbook__author' ).filter( { hasText: author } ) ).toBeVisible();
	} );

	test( 'empty submission shows a validation error', async ( { page } ) => {
		await page.goto( '/guestbook/' );
		await expect( page.locator( '.wgrsvp-guestbook__form' ) ).toBeVisible();

		await page.locator( '.wgrsvp-guestbook__submit' ).click();

		await expect( page.locator( '#wgrsvp-gb-name' ) ).toHaveJSProperty( 'validity.valid', false );
		await expect( page.locator( '#wgrsvp-gb-message' ) ).toHaveJSProperty( 'validity.valid', false );
		await expect( page.locator( '.wgrsvp-guestbook__status' ) ).toBeHidden();
	} );
} );
