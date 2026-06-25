// Mint a combined storageState for ONE auth profile: logs into BOTH the reference (legacy)
// and the rebuild in a single browser context, then saves cookies/localStorage so the parity
// harness can capture gated pages on both sides as that profile.
//
//   node login-state.mjs <email> <password> <out.json>
//
// TEMPLATE — login form selectors are app-specific. Point LEGACY/REBUILD at the same URLs as
// your visual-parity.config.mjs and edit the two login blocks to match each app's form.
// Then reference the file from authProfiles, e.g. authProfiles: { member: 'auth.member.json' }.
import { chromium } from 'playwright';

const [email = 'user@example.com', password = 'secret', outFile = 'auth.json'] = process.argv.slice(2);
const LEGACY  = 'https://reference.example.com';
const REBUILD = 'https://rebuild.example.test';

console.log('login as', email);
const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

// ── Reference (legacy) login — EDIT selectors to match the reference app ──
await page.goto(`${LEGACY}/login`, { waitUntil: 'networkidle' });
await page.fill('input[type="email"]', email);
await page.fill('input[type="password"]', password);
await page.getByRole('button', { name: /log ?in|sign ?in|aanmelden/i }).click();
await page.waitForLoadState('networkidle');
console.log('legacy  ->', page.url());

// ── Rebuild login — EDIT selectors to match the rebuild app ──
await page.goto(`${REBUILD}/login`, { waitUntil: 'networkidle' });
await page.fill('input#email', email);
await page.fill('input#password', password);
await page.locator('input#password').press('Enter');
await page.waitForLoadState('networkidle');
console.log('rebuild ->', page.url());

await ctx.storageState({ path: new URL('./' + outFile, import.meta.url).pathname });
console.log('saved', outFile);
await browser.close();
