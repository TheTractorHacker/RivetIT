// Runs every capture script in capture/ one after the other (filename order) and reports which
// ones failed, so the whole set of user-guide screenshots can be regenerated in one go.
//
//   NODE_PATH=$(npm root -g) DEMO_URL=http://127.0.0.1:8080 node docs/user-guide/tools/run-all.cjs
//   node docs/user-guide/tools/run-all.cjs service-desk portal      # only the named groups
//
// The scripts are read-only with respect to app data, so this is safe to re-run at any time; run
// build-demo.sh first if you want every screenshot to come from a freshly rebuilt, consistent demo.

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const dir = path.join(__dirname, 'capture');
const wanted = process.argv.slice(2);
const files = fs
  .readdirSync(dir)
  .filter((f) => f.endsWith('.cjs'))
  .filter((f) => wanted.length === 0 || wanted.some((w) => f === `${w}.cjs` || f === w))
  .sort();

if (files.length === 0) {
  console.error('No capture scripts matched.');
  process.exit(2);
}

const results = [];
for (const f of files) {
  const started = Date.now();
  console.log(`\n=== ${f} ===`);
  const r = spawnSync(process.execPath, [path.join(dir, f)], {
    stdio: 'inherit',
    env: process.env,
    timeout: 15 * 60 * 1000,
  });
  results.push({ f, ok: r.status === 0, secs: Math.round((Date.now() - started) / 1000), signal: r.signal });
}

console.log('\n=== summary ===');
for (const r of results) {
  console.log(`${r.ok ? 'ok  ' : 'FAIL'}  ${r.f}  (${r.secs}s${r.signal ? `, ${r.signal}` : ''})`);
}
process.exit(results.every((r) => r.ok) ? 0 : 1);
