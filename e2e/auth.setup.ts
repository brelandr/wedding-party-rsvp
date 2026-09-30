import fs from 'fs';
import path from 'path';
import { expect, test as setup } from '@playwright/test';

const authFile = path.join( __dirname, '.auth/admin.json' );

/**
 * Log in to wp-admin and persist cookies / local storage for later specs.
 * wp-env default credentials: admin / password.
 */
setup( 'authenticate as admin', async ( { page } ) => {
	fs.mkdirSync( path.dirname( authFile ), { recursive: true } );

	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();

	await page.waitForURL( /\/wp-admin/ );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	await page.context().storageState( { path: authFile } );
} );
