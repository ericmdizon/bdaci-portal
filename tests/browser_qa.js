// Browser QA of the QA Testing page and section search. node browserqa.js <scratch> <cookies.json>
const { chromium } = require('playwright');
const fs = require('fs');
const S = process.argv[2], ck = process.argv[3];
let ok = 0, bad = 0; const fails = [];
function check(n, c, x) { if (c) { ok++; console.log('PASS', n); } else { bad++; fails.push(n); console.log('FAIL', n, x === undefined ? '' : JSON.stringify(x).slice(0, 300)); } }
const PNG = Buffer.from('89504e470d0a1a0a0000000d4948445200000001000000010802000000907753de0000000c4944415408d763f8cfc000000301010018dd8db00000000049454e44ae426082', 'hex');
(async () => {
  const b = await chromium.launch();
  for (const vp of [{ width: 1280, height: 900, name: 'desktop' }, { width: 390, height: 844, name: 'phone' }]) {
    const ctx = await b.newContext({ viewport: { width: vp.width, height: vp.height } });
    await ctx.addCookies(JSON.parse(fs.readFileSync(ck)));
    const pg = await ctx.newPage();
    const errs = []; pg.on('pageerror', e => errs.push(String(e)));
    await pg.goto('http://localhost:8089/board-portal/?manage=qa&group=sign');
    const sw = await pg.evaluate(() => document.documentElement.scrollWidth);
    check(`${vp.name}: no sideways scrolling on QA page`, sw <= vp.width + 1, sw);
    // paste an image into the first card's note box
    const card = pg.locator('article.qa-case').first();
    const ta = card.locator('textarea');
    await ta.click();
    await pg.evaluate(async (hex) => {
      const bytes = new Uint8Array(hex.match(/../g).map(h => parseInt(h, 16)));
      const file = new File([bytes], 'image.png', { type: 'image/png' });
      const dt = new DataTransfer(); dt.items.add(file);
      const ev = new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true });
      document.querySelector('article.qa-case textarea').dispatchEvent(ev);
    }, PNG.toString('hex'));
    let picked = await card.locator('.qa-picked img').count();
    check(`${vp.name}: pasted screenshot shows as ready`, picked === 1, picked);
    // drop a too-big file and a text file
    await pg.evaluate(() => {
      const big = new File([new Uint8Array(20 * 1024 * 1024)], 'huge.png', { type: 'image/png' });
      const txt = new File(['hi'], 'notes.txt', { type: 'text/plain' });
      const dt = new DataTransfer(); dt.items.add(big); dt.items.add(txt);
      const c = document.querySelector('article.qa-case');
      c.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
    });
    const pickedText = await card.locator('.qa-picked').innerText();
    picked = await card.locator('.qa-picked img').count();
    check(`${vp.name}: too-big and non-picture drops are left out and named`, picked === 1 && /huge\.png/.test(pickedText) && /notes\.txt/.test(pickedText), pickedText);
    // type note and save note only
    await ta.fill(`Browser QA ${vp.name}`);
    await Promise.all([pg.waitForNavigation(), card.locator('button[value="note"]').click()]);
    const body = await pg.content();
    check(`${vp.name}: save note only with pasted screenshot`, /Note saved on SIGN-01\. 1 screenshot added/.test(body), body.match(/notice[^<]*<[^>]*>[^<]*/)?.[0]);
    const thumbs = await pg.locator('#qa-SIGN-01 .qa-notes .qa-shots img').count();
    check(`${vp.name}: thumbnail appears on the card`, thumbs >= 1, thumbs);
    const natural = await pg.locator('#qa-SIGN-01 .qa-notes .qa-shots img').first().evaluate(i => i.complete && i.naturalWidth > 0);
    check(`${vp.name}: thumbnail image actually loads`, natural);
    // pass button keeps the page on the card
    const c2 = pg.locator('#qa-SIGN-02');
    await Promise.all([pg.waitForNavigation(), c2.locator('button[value="pass"]').click()]);
    check(`${vp.name}: pass returns to the same card`, pg.url().includes('#qa-SIGN-02') && (await pg.locator('#qa-SIGN-02 button[value="pass"]').getAttribute('aria-pressed')) === 'true', pg.url());
    // filters
    await pg.goto('http://localhost:8089/board-portal/?manage=qa&group=sign&show=todo');
    const todoHasSign2 = await pg.locator('#qa-SIGN-02').count();
    check(`${vp.name}: Not tested filter hides tested scenarios`, todoHasSign2 === 0);
    await pg.goto('http://localhost:8089/board-portal/?manage=qa&show=mine');
    check(`${vp.name}: Marked by me shows my scenario`, (await pg.locator('#qa-SIGN-02').count()) === 1);
    // where-to-find open button opens new tab
    await pg.goto('http://localhost:8089/board-portal/?manage=qa&group=docs');
    const [pop] = await Promise.all([ctx.waitForEvent('page'), pg.locator('#qa-DOC-01 .qa-where a').click()]);
    await pop.waitForLoadState();
    check(`${vp.name}: Open goes to Sections & Uploads in a new tab`, pop.url().includes('manage=docs') && /Sections &amp; Uploads|Sections & Uploads/.test(await pop.content()), pop.url());
    // section search on upload form
    await pop.fill('.sec-q', 'finance');
    const vis = await pop.$$eval('form .secgroups', gs => { const g = gs[0]; return [...g.querySelectorAll('.pick')].filter(p => !p.hidden && !p.closest('[hidden]')).map(p => p.innerText.split('\n')[0]); });
    check(`${vp.name}: section search "finance" shows only finance sections`, vis.length >= 1 && vis.every(v => /financ/i.test(v)), vis);
    await pop.fill('.sec-q', 'zzzz');
    check(`${vp.name}: no-match message shows`, await pop.locator('form .sec-none').first().isVisible());
    await pop.keyboard.press('Enter');
    check(`${vp.name}: Enter in search does not submit the upload`, pop.url().includes('manage=docs') && !(await pop.content()).includes('Choose at least one file'));
    const sw2 = await pop.evaluate(() => document.documentElement.scrollWidth);
    check(`${vp.name}: no sideways scrolling on Sections & Uploads`, sw2 <= vp.width + 1, sw2);
    await pg.screenshot({ path: `${S}/shots/bqa-${vp.name}.png` });
    const real = errs.filter(e => !/Unexpected token '<'/.test(e)); check(`${vp.name}: no JavaScript errors from the plugin (test-server noise ignored)`, real.length === 0, real);
    await ctx.close();
  }
  await b.close();
  console.log(`\n${ok} passed, ${bad} failed`); console.log('FAILED:', fails);
})();
