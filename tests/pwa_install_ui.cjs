const assert = require('node:assert/strict');
const {mkdirSync} = require('node:fs');
const {join} = require('node:path');

// Called by pwa_browser.cjs against its isolated PHP fixture. User-agent emulation
// checks our UI branches, not the operating system's native installation flow.
exports.checkInstallUi = async (browser, base, artifacts) => {
  const agents = {
    ios: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
    android: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
  };
  mkdirSync(artifacts, {recursive:true});
  for (const platform of ['ios', 'android']) {
    const context = await browser.newContext({userAgent:agents[platform], locale:'ru-RU',
      viewport:{width:390,height:844}, isMobile:true, hasTouch:true});
    const errors = [];
    try {
      await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(base + '/login?token=do-not-copy#private');
      await page.locator('[data-pwa-install]').click();
      const help = page.locator('[data-pwa-help]');
      assert.equal(await help.isVisible(), true);
      assert.equal(await page.locator(`[data-pwa-platform="${platform}"]`).isVisible(), true);
      assert.equal(await page.locator('[data-pwa-platform="desktop"]').isVisible(), false);
      assert.equal(await page.locator(`[data-pwa-platform="${platform === 'ios' ? 'android' : 'ios'}"]`).isVisible(), false);
      assert.equal(await page.locator('[data-pwa-url]').inputValue(), base + '/', 'never copy query parameters or fragments');
      for (const width of [320, 390]) {
        await page.setViewportSize({width,height:844});
        const box = await help.boundingBox();
        assert(box.x >= 0 && box.x + box.width <= width + 1 && box.y >= 0, 'install sheet fits narrow phones');
        assert.equal(await help.evaluate(el => el.scrollWidth <= el.clientWidth), true);
      }
      await page.screenshot({path:join(artifacts, 'install-' + platform + '.png')});
      await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', {configurable:true, value:{writeText:async text => { window.copiedPortalAddress = text; }}});
      });
      await page.locator('[data-pwa-copy]').click();
      assert.equal(await page.evaluate(() => window.copiedPortalAddress), base + '/');
      assert.equal(await page.locator('[data-pwa-copy-status]').textContent(), 'Адрес скопирован');
      await page.evaluate(() => { navigator.clipboard.writeText = async () => { throw new Error('denied'); }; });
      await page.locator('[data-pwa-copy]').click();
      assert.match(await page.locator('[data-pwa-copy-status]').textContent(), /Адрес выделен/);
      assert.equal(await page.locator('[data-pwa-url]').evaluate(el => el.selectionEnd - el.selectionStart), (base + '/').length);
      await page.keyboard.press('Escape');
      assert.equal(await help.isVisible(), false);
      assert.equal(await page.locator('[data-pwa-install]').evaluate(el => el === document.activeElement), true);

      await page.evaluate(() => {
        const event = new Event('beforeinstallprompt', {cancelable:true});
        window.nativeInstallCalls = 0;
        event.prompt = async () => { window.nativeInstallCalls++; };
        event.userChoice = Promise.resolve({outcome:'dismissed'});
        window.dispatchEvent(event);
      });
      await page.locator('[data-pwa-install]').click();
      assert.equal(await page.evaluate(() => window.nativeInstallCalls), 1);
      assert.equal(await help.isVisible(), false, 'native prompt takes priority over instructions');
      await page.evaluate(() => window.dispatchEvent(new Event('appinstalled')));
      assert.equal(await page.locator('[data-pwa-controls]').isVisible(), false);
      await page.evaluate(() => localStorage.removeItem('sesame-portal-pwa-install-dismissed-until'));

      await context.request.post(base + '/login', {form:{login:'user',password:'pwa-password'}});
      await page.goto(base + '/');
      assert.equal(await page.locator('[data-pwa-suggestion]').isVisible(), true);
      await page.locator('[data-pwa-suggestion]').screenshot({path:join(artifacts, 'install-banner-' + platform + '.png')});
      await page.locator('[data-pwa-dismiss]').click();
      assert.equal(await page.locator('[data-pwa-suggestion]').isVisible(), false);
      await page.reload();
      assert.equal(await page.locator('[data-pwa-suggestion]').isVisible(), false, 'Later survives navigation');
      assert.equal(await page.locator('.sidebar-foot [data-pwa-install]').isVisible(), true, 'menu entry remains available');
      await page.evaluate(() => localStorage.setItem('sesame-portal-pwa-install-dismissed-until', String(Date.now() - 1)));
      await page.reload();
      assert.equal(await page.locator('[data-pwa-suggestion]').isVisible(), true, 'expired dismissal shows suggestion again');
      await context.addInitScript(() => Object.defineProperty(navigator, 'standalone', {value:true, configurable:true}));
      await page.reload();
      assert.equal(await page.locator('[data-pwa-suggestion]').isVisible(), false);
      assert.equal(await page.locator('.sidebar-foot [data-pwa-install]').isVisible(), false, 'installed mode hides installation UI');
      assert.deepEqual(errors, []);
    } finally {
      await context.close();
    }
  }
  console.log('PWA installation UI: iPhone/Android instructions, narrow layouts, clean URL copy/fallback, native prompt, dismissal and installed mode passed (Chrome emulation).');
};
