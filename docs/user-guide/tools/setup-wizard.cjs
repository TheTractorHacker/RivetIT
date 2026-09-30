// Walks the browser installer (/setup/) on a BRAND-NEW instance and screenshots each step.
//
// Unlike the scripts in capture/, this one is NOT read-only: it installs the app. Point it at an
// instance that has no config.php yet and an empty, pre-created database:
//
//   DEMO_URL=http://127.0.0.1:8081 WIZ_DB=rivetit_wiz WIZ_DB_USER=rivetit_demo WIZ_DB_PASS='...' \
//     NODE_PATH=$(npm root -g) node docs/user-guide/tools/setup-wizard.cjs
//
// Optional: WIZ_AVATAR=/path/to/small.png uploads a profile picture on the "user" step (this also
// exercises the avatar-folder code in setup/index.php).

const { launch, shot, goto, BASE } = require('./lib.cjs');

const DB = process.env.WIZ_DB || 'rivetit_wiz';
const DB_USER = process.env.WIZ_DB_USER || 'rivetit_demo';
const DB_PASS = process.env.WIZ_DB_PASS || 'DemoPass_2026';
const AVATAR = process.env.WIZ_AVATAR || '';

// The wizard's country/locale/currency/timezone lists are select2 widgets over a hidden <select>.
async function setSelect(page, name, value) {
  await page.evaluate(({ name, value }) => {
    const el = document.querySelector(`select[name="${name}"]`);
    if (!el) throw new Error(`no select named ${name}`);
    el.value = value;
    if (window.jQuery) window.jQuery(el).val(value).trigger('change');
    else el.dispatchEvent(new Event('change', { bubbles: true }));
  }, { name, value });
}

(async () => {
  const { browser, page } = await launch({ width: 1440, height: 900 });
  const g = 'setup-wizard';

  await goto(page, '/setup/');
  await shot(page, `${g}/01-welcome`);

  await goto(page, '/setup/?checks');
  await shot(page, `${g}/02-checks`, { fullPage: true });

  await goto(page, '/setup/?database');
  await page.fill('input[name=database]', DB);
  await page.fill('input[name=host]', 'localhost');
  await page.fill('input[name=username]', DB_USER);
  await page.fill('input[name=password]', DB_PASS);
  await shot(page, `${g}/03-database`);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_database]')]);

  await goto(page, '/setup/?user');
  await page.fill('input[name=name]', 'Wizard Tester');
  await page.fill('input[name=email]', 'wizard@test.example');
  await page.fill('input[name=password]', 'WizardPass#2026');
  if (AVATAR) await page.setInputFiles('input[name=file]', AVATAR);
  await shot(page, `${g}/04-first-user`);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_user]')]);

  await goto(page, '/setup/?company');
  await page.fill('input[name=name]', 'Wizard Test Company');
  await page.fill('input[name=address]', '1200 Ridge Road');
  await page.fill('input[name=city]', 'Madison');
  await page.fill('input[name=state]', 'WI');
  await page.fill('input[name=zip]', '53703');
  await setSelect(page, 'country', 'United States');
  await page.fill('input[name=phone]', '6085550100');
  await page.fill('input[name=email]', 'it@test.example');
  await page.fill('input[name=website]', 'test.example');
  await shot(page, `${g}/05-company`, { fullPage: true });
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_company_settings]')]);

  await goto(page, '/setup/?localization');
  await setSelect(page, 'locale', 'en_US');
  await setSelect(page, 'currency_code', 'USD');
  await setSelect(page, 'timezone', 'America/Chicago');
  await shot(page, `${g}/06-localization`);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_localization_settings]')]);

  await goto(page, '/setup/?telemetry');
  await shot(page, `${g}/07-telemetry`, { fullPage: true });
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_telemetry]')]);
  // PHP's opcache can keep serving the old config.php (without the "setup finished" flag) for a couple
  // of seconds, which bounces the very first login request back to /setup - give it a moment.
  await page.waitForTimeout(3500);
  await goto(page, '/login.php');
  console.log('finished at:', page.url());
  await shot(page, `${g}/08-first-sign-in`);

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
