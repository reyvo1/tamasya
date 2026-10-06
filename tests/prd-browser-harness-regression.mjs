import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=process.argv[2]||path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=r=>fs.readFileSync(path.join(root,r),'utf8');
let passed=0;const test=(n,f)=>{f();passed++;console.log(`PASS ${n}`)};
const browser=read('tests/uat_rc1/browser/rc1-ui.spec.mjs');
const memo=read('assets/internal-memo.js');

test('static wiring sweep snapshots real button handles instead of re-querying unstable nth indexes',()=>{
  assert.ok(browser.includes("page.locator('button').elementHandles()"));
  assert.ok(!browser.includes("const b=page.locator('button').nth(i)"));
});

test('every initially rendered button is still invoked and disposed',()=>{
  assert.ok(browser.includes('for(const b of buttonHandles)'));
  assert.ok(browser.includes('el.click()'));
  assert.ok(browser.includes('b.dispose().catch'));
});

test('internal memo still has explicit end-to-end create/archive/restore coverage beyond wiring sweep',()=>{
  assert.ok(browser.includes("test('Internal Memo UI: admin create, search, archive and restore without hard delete'"));
  for(const marker of ['data-archive','data-restore','async function update'])assert.ok(memo.includes(marker),marker);
});

test('wiring sweep still fails browser errors and server 5xx instead of weakening assertions',()=>{
  assert.ok(browser.includes('expect(errors).toEqual([])'));
  assert.ok(browser.includes('expect(serverErrors).toEqual([])'));
});

test('static page inventory thresholds remain enforced',()=>{
  assert.ok(browser.includes("['internal-memo.html',10]"));
  assert.ok(browser.includes('expect(controls.length).toBeGreaterThanOrEqual(minControls)'));
});
test('metric containment measures summary cards while audit hit testing waits for final scroll position',()=>{
 assert.ok(browser.includes('.tamasya-finance-summary > .glass-card:visible .tamasya-metric-value'));
 assert.ok(!browser.includes("'.glass-card:visible .tamasya-metric-value"));
 assert.ok(browser.includes('await expect.poll(()=>heading.evaluate'));
 assert.ok(browser.includes('document.elementFromPoint'));
});
console.log(`${passed} passed; 0 failed`);
