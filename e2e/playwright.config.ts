import path from 'path';
import { defineConfig, devices } from '@playwright/test';

export const STORAGE_STATE = path.join( __dirname, '.auth/admin.json' );

/**
 * Browser tests against the local wp-env site (http://localhost:8888).
 * Auth setup writes a reusable admin storageState to e2e/.auth/admin.json.
 */
export default defineConfig( {
	testDir: '.',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: 'list',
	timeout: 60_000,
	expect: {
		timeout: 15_000,
	},
	use: {
		baseURL: 'http://localhost:8888',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'setup',
			testMatch: /auth\.setup\.ts/,
		},
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: STORAGE_STATE,
			},
			dependencies: [ 'setup' ],
			testMatch: /tests\/.*\.spec\.ts/,
		},
	],
} );
