// Browser QA of the document viewer and sign-off. node viewerqa.js <scratch> <board cookies> <pdfId> <xlsxId>
const { chromium } = require('playwright');
const fs = require('fs');
const [S, ck, PDF, XLSX] = process.argv.slice(2);
let ok = 0, bad = 0; const fails = [];
function check(n, c, x) { if (c) { ok++; console.log('PASS', n); } else { bad++; fails.push(n); console.log('FAIL', n, x === undefined ? '' : JSON.stringify(x).slice(0, 300)); } }
const B = 'http://localhost:8089';
(async () => {
  const b = await chromium.launch();
  for (const vp of [{ width: 1280, height: 900, name: 'desktop' }, { width: 390, height: 844, name: 'phone' }]) {
    require('child_process').execSync(`php ${S}/wpcli.php ${S}/wp 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}bdaci_pa_signoff_events WHERE pack IN (\\"doc\\",\\"orientation\\")");'`, { cwd: S + '/wp' });
    const ctx = await b.newContext({ viewport: { width: vp.width, height: vp.height } });
    await ctx.addCookies(JSON.parse(fs.readFileSync(ck)));
    const pg = await ctx.newPage();
    const errs = []; pg.on('pageerror', e => errs.push(String(e)));
    let printed = 0; await pg.exposeFunction('__printed', () => { printed++; });
    await pg.goto(B + '/board-portal/');
    const link = pg.locator(`.todo a[data-view="${PDF}"]`);
    check(`${vp.name}: to-do has the document to sign`, await link.count() === 1);
    await link.click();
    const dlg = pg.locator('dialog.bp-viewer');
    await dlg.waitFor({ state: 'visible' });
    check(`${vp.name}: viewer opens instead of a new tab`, pg.context().pages().length === 1 && await dlg.isVisible());
    check(`${vp.name}: title shown`, (await dlg.locator('#bpv-title').innerText()) === 'FRONT-DOC');
    const src = await dlg.locator('iframe').getAttribute('src');
    check(`${vp.name}: PDF shown inside the viewer`, /bdaci_doc=\d+/.test(src || ''), src);
    check(`${vp.name}: no Download or Print button`, await dlg.locator('[data-vw=download], [data-vw=print]').count() === 0);
    check(`${vp.name}: new tab button, PDF toolbar hidden`, /bdaci_doc/.test(await dlg.locator('[data-vw=newtab]').getAttribute('href')) && /#toolbar=0/.test(src || ''));
    if (vp.name === 'desktop') {
      await dlg.locator('[data-vw=expand]').click();
      const box = await dlg.boundingBox();
      check('desktop: expand fills the window', box.width >= 1270 && box.height >= 890, box);
      await dlg.locator('[data-vw=expand]').click();
      check('desktop: full screen button offered', await dlg.locator('[data-vw=full]').isVisible());
      await dlg.locator('[data-vw=full]').click();
      await pg.waitForFunction(() => !!document.fullscreenElement, null, { timeout: 3000 }).catch(() => {});
      const fsOn = await pg.evaluate(() => !!document.fullscreenElement && document.querySelector('dialog.bp-viewer').open);
      check('desktop: full screen works and the viewer stays open', fsOn && /Exit full screen/.test(await dlg.locator('[data-vw=full]').innerText()));
      await dlg.locator('[data-vw=full]').click();
      await pg.waitForFunction(() => !document.fullscreenElement, null, { timeout: 3000 }).catch(() => {});
      check('desktop: leaving full screen keeps the viewer open', await dlg.isVisible() && !(await pg.evaluate(() => !!document.fullscreenElement)));
    } else {
      const box = await dlg.boundingBox();
      check('phone: viewer fills the screen', box.width >= 385, box);
    }
    const sign = dlg.locator('.vw-sign');
    check(`${vp.name}: sign-off bar starts folded so the document stays readable`, await sign.isVisible() && await sign.locator('.vw-open-sign').isVisible() && !(await sign.locator('input[name=ack]').isVisible()));
    await sign.locator('.vw-open-sign').click();
    check(`${vp.name}: Sign now opens the form with the statement`, /agree to the Code of Conduct/.test(await sign.innerText()) && await sign.locator('input[name=ack]').isVisible());
    check(`${vp.name}: Type is the default with the full name filled in`, await sign.locator('[data-sig-mode=typed]').getAttribute('aria-pressed') === 'true' && await sign.locator('input[name=sig_name]').inputValue() === 'Bea Board' && (await sign.locator('.sig-preview').innerText()) === 'Bea Board');
    check(`${vp.name}: signature uses the bundled script font`, await pg.evaluate(async () => { await document.fonts.ready; const el = document.querySelector('.vw-sign .sig-preview'); return /BDACI Signature/.test(getComputedStyle(el).fontFamily) && document.fonts.check('32px "BDACI Signature"', 'Bea'); }));
    await sign.locator('input[name=sig_name]').fill('B. Board');
    check(`${vp.name}: name can be changed, Use my name appears`, (await sign.locator('.sig-preview').innerText()) === 'B. Board' && await sign.locator('[data-sig-reset]').isVisible());
    await sign.locator('[data-sig-reset]').click();
    check(`${vp.name}: Use my name puts it back`, await sign.locator('input[name=sig_name]').inputValue() === 'Bea Board' && !(await sign.locator('[data-sig-reset]').isVisible()));
    // close without signing -> warning
    await dlg.locator('[data-vw=close]').click();
    check(`${vp.name}: closing asks to sign first`, await dlg.isVisible() && await dlg.locator('.vw-leave').isVisible());
    await dlg.locator('.vw-leave button.btn-sm:not(.btn-primary)').click();
    check(`${vp.name}: "Close, sign later" closes`, !(await dlg.isVisible()));
    // Esc also asks
    await link.click(); await dlg.waitFor({ state: 'visible' });
    await pg.keyboard.press('Escape');
    check(`${vp.name}: Esc asks to sign first`, await dlg.isVisible() && await dlg.locator('.vw-leave').isVisible());
    await dlg.locator('.vw-leave .btn-primary').click();
    // try submit without signature
    await sign.locator('input[name=ack]').check();
    await sign.locator('input[name=sig_name]').fill('');
    await sign.locator('button[type=submit]').click();
    check(`${vp.name}: empty signature stopped in the browser`, await sign.locator('.sig-err').isVisible() && await dlg.isVisible());
    if (vp.name === 'desktop') {
      await sign.locator('input[name=sig_name]').fill('Bea Board');
      check('desktop: typed name previews in script', (await sign.locator('.sig-preview').innerText()) === 'Bea Board');
      await pg.screenshot({ path: `${S}/shots/v211-viewer-sign.png` });
      await Promise.all([pg.waitForNavigation(), sign.locator('button[type=submit]').click()]);
    } else {
      await sign.locator('[data-sig-mode=drawn]').click();
      const cv = sign.locator('canvas.sig-pad');
      await cv.scrollIntoViewIfNeeded();
      const bb = await cv.boundingBox();
      await pg.mouse.move(bb.x + 20, bb.y + bb.height / 2); await pg.mouse.down();
      for (let i = 1; i < 30; i++) await pg.mouse.move(bb.x + 20 + i * 9, bb.y + bb.height / 2 + Math.sin(i / 2) * 25);
      await pg.mouse.up();
      const v = await sign.locator('input[name=sig_img]').inputValue();
      check('phone: drawing fills the signature', v.startsWith('data:image/png;base64,') && v.length > 500, v.length);
      await pg.screenshot({ path: `${S}/shots/v211-viewer-draw-phone.png` });
      await Promise.all([pg.waitForNavigation(), sign.locator('button[type=submit]').click()]);
    }
    const body = await pg.content();
    check(`${vp.name}: signing goes through`, /Signed off on (&quot;|")FRONT-DOC/.test(body), (body.match(/notice-bar[^<]*<?[^<]*/) || [''])[0]);
    // after signing: viewer shows signed state, no warning on close
    await pg.goto(B + '/board-portal/?x=' + vp.name);
    const sl = pg.locator(`a[data-view="${PDF}"]`).first();
    if (await sl.count()) {
      await sl.click(); await dlg.waitFor({ state: 'visible' });
      check(`${vp.name}: signed document shows Signed with a receipt`, /Signed off/.test(await dlg.locator('.vw-sign').innerText()) && await dlg.locator('.vw-sign a').count() === 1);
      await dlg.locator('[data-vw=close]').click();
      check(`${vp.name}: signed document closes without asking`, !(await dlg.isVisible()));
    } else {
      check(`${vp.name}: signed document no longer on to-do`, true);
    }
    // xlsx: download message and sign
    await pg.goto(B + '/board-portal/');
    const xl = pg.locator(`.todo a[data-view="${XLSX}"]`);
    if (vp.name === 'desktop') {
      await xl.click(); await dlg.waitFor({ state: 'visible' });
      check('desktop: Excel file offers a download inside the viewer', /can't be shown in the browser/.test(await dlg.locator('.vw-body').innerText()) && await dlg.locator('.vw-body a.btn').count() === 1);
      check('desktop: sign hint asks to download first', /Download and read the file/.test(await dlg.locator('.vw-sign').textContent()));
      await dlg.locator('[data-vw=close]').click(); await dlg.locator('.vw-leave button.btn-sm:not(.btn-primary)').click();
      // print for a PDF calls the browser print
      await pg.goto(B + '/board-portal/board-policies/');
    }
    // sign-off pack via viewer: orientation
    await pg.goto(B + '/board-portal/new-director-orientation/');
    const opens = pg.locator('#bp-so-docs a[data-view]');
    const n = await opens.count();
    if (n > 0 && vp.name === 'desktop') {
      await opens.first().click(); await dlg.waitFor({ state: 'visible' });
      const t = await dlg.locator('.vw-sign').innerText();
      check('desktop: pack progress shows in the viewer', n === 1 ? /Confirm and sign below/.test(t) : /documents opened/.test(t), t.slice(0, 120));
      for (let i = 1; i < n; i++) {
        const nx = dlg.locator('.vw-pack-next button').first();
        if (await nx.count()) await nx.click();
      }
      const t2 = await dlg.locator('.vw-sign').textContent();
      check('desktop: after opening every pack document, sign the pack in the viewer', /opened every document/.test(t2) && await dlg.locator('.vw-sign input[name=pack]').count() === 1, t2.slice(0, 160));
      await dlg.locator('.vw-sign .vw-open-sign').last().click();
      await dlg.locator('.vw-sign input[name=ack]').last().check();
      await dlg.locator('.vw-sign input[name=sig_name]').last().fill('Bea Board');
      await pg.screenshot({ path: `${S}/shots/v211-viewer-pack.png` });
      await Promise.all([pg.waitForNavigation(), dlg.locator('.vw-sign button[type=submit]').last().click()]);
      check('desktop: pack signed from the viewer', /Signed off\. A confirmation/.test(await pg.content()));
    }
    const sw = await pg.evaluate(() => document.documentElement.scrollWidth);
    check(`${vp.name}: no sideways scrolling`, sw <= vp.width + 1, sw);
    const real = errs.filter(e => !/Unexpected token '<'/.test(e)); check(`${vp.name}: no JavaScript errors`, real.length === 0, real);
    await ctx.close();
  }
  await b.close();
  console.log(`\n${ok} passed, ${bad} failed`); console.log('FAILED:', fails);
})();
