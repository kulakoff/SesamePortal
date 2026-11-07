// Requires Playwright and Chrome. All credentials/data are isolated test fixtures.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const {execFileSync, spawn} = require('node:child_process');
const {mkdtempSync, mkdirSync, rmSync, writeFileSync} = require('node:fs');
const {tmpdir} = require('node:os');
const {join, resolve} = require('node:path');
const {createServer} = require('node:net');
const {once} = require('node:events');
const {createHash} = require('node:crypto');
const {checkInstallUi} = require('./pwa_install_ui.cjs');

(async () => {
  const root = resolve(__dirname, '..');
  const state = mkdtempSync(join(tmpdir(), 'portal-pwa-'));
  const artifacts = process.env.SESAME_BROWSER_ARTIFACTS || '/tmp/portal-pwa-browser';
  const env = {...process.env, SESAME_PORTAL_STATE_DIR:state,
    SESAME_PORTAL_CONFIG:join(state, 'config.php'), SESAME_PORTAL_DB_DSN:'sqlite:' + state + '/portal.sqlite',
    SESAME_PORTAL_SECRET:'pwa-test-only', SESAME_PORTAL_UPDATE_AUTO_CHECK:'0', SESAME_PORTAL_BASE_URL:''};
  let browser, server, installContext;
  const php = (code, ...args) => execFileSync('php', ['-r', 'require $argv[1] . "/app/Portal.php"; ' + code, root, ...args], {cwd:root, env}).toString();
  const sql = (query, args = []) => JSON.parse(php('$s = SesamePortal\\DB::pdo()->prepare($argv[2]); $s->execute(json_decode($argv[3], true)); echo json_encode($s->fetchAll());', query, JSON.stringify(args)));
  try {
    mkdirSync(join(state, 'sessions'));
    php('SesamePortal\\DB::migrate(); SesamePortal\\DB::migrate(); $s = SesamePortal\\DB::pdo()->prepare("INSERT INTO users(login,password_hash,role,created_at) VALUES(?,?,?,?)"); foreach (["admin", "user"] as $role) $s->execute([$role, password_hash("pwa-password", PASSWORD_DEFAULT), $role, SesamePortal\\Util::now()]);');
    const socket = createServer();
    await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    const base = 'http://127.0.0.1:' + port;
    server = spawn('php', ['-d', 'session.save_path=' + join(state, 'sessions'), '-S', '127.0.0.1:' + port, '-t', 'public', 'tests/video_walls_router.php'], {cwd:root, env, stdio:'ignore'});
    for (let attempt = 0; ; attempt++) {
      try { if ((await fetch(base + '/login')).ok) break; } catch (_) {}
      if (attempt >= 50) throw new Error('PHP fixture did not start');
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    browser = await chromium.launch({headless:true, channel:'chrome'});
    const errors = [];
    const context = async (cookies = []) => {
      const ctx = await browser.newContext({viewport:{width:390,height:844}, locale:'ru-RU'});
      await ctx.addCookies(cookies);
      await ctx.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
      ctx.on('page', page => page.on('pageerror', error => errors.push(error.message)));
      return ctx;
    };
    const login = async (ctx, remember = true, user = 'user', password = 'pwa-password') => {
      const page = await ctx.newPage();
      await page.goto(base + '/login');
      await page.locator('[name="login"]').fill(user);
      await page.locator('[name="password"]').fill(password);
      if (remember) await page.locator('[name="remember"]').check();
      await page.locator('form button.primary').click();
      await page.waitForURL(base + '/');
      return page;
    };
    const remembered = async ctx => (await ctx.cookies()).filter(cookie => cookie.name === 'sesame_portal_remember');
    const allowed = async ctx => (await ctx.request.get(base + '/settings', {maxRedirects:0})).status() === 200;

    const admin = await context();
    await login(admin, false, 'admin');
    const userId = sql('SELECT id FROM users WHERE login = ?', ['user'])[0].id;
    const updateUser = async data => assert.equal((await admin.request.patch(base + '/api/portal/v1/users/' + userId, {data})).status(), 200);
    await checkInstallUi(browser, base, artifacts);
    installContext = await chromium.launchPersistentContext(join(state, 'browser'), {headless:true, channel:'chrome', viewport:{width:390,height:844}, locale:'ru-RU'});
    await installContext.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const first = installContext;
    const page = await first.newPage();
    await page.goto(base + '/login');
    assert.equal(await page.locator('[name="remember"]').isChecked(), false);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'mobile login fits');
    mkdirSync(artifacts, {recursive:true});
    await page.screenshot({path:join(artifacts, 'login-mobile.png'), fullPage:true});
    await page.setViewportSize({width:1440,height:1000});
    await page.screenshot({path:join(artifacts, 'login-desktop.png'), fullPage:true});
    await page.evaluate(async () => { await navigator.serviceWorker.ready; });
    await page.reload();
    assert.equal(await page.evaluate(() => !!navigator.serviceWorker.controller), true);
    const cdp = await first.newCDPSession(page);
    const manifest = await cdp.send('Page.getAppManifest');
    assert.deepEqual(manifest.errors, [], 'browser accepts manifest');
    const data = JSON.parse(manifest.data);
    assert.equal(data.start_url, '/');
    assert.equal(data.display, 'standalone');
    assert.deepEqual((await cdp.send('Page.getInstallabilityErrors')).installabilityErrors, [], 'Chrome considers PWA installable');
    for (const icon of [...data.icons, {src:'/assets/pwa/icon-180.png',sizes:'180x180'}]) {
      const size = await page.evaluate(src => new Promise((resolve, reject) => {
        const image = new Image(); image.onload = () => resolve(`${image.naturalWidth}x${image.naturalHeight}`); image.onerror = reject; image.src = src;
      }), icon.src);
      assert.equal(size, icon.sizes);
    }
    await page.evaluate(() => {
      const event = new Event('beforeinstallprompt', {cancelable:true});
      window.promptCount = 0;
      event.prompt = async () => { window.promptCount++; };
      event.userChoice = Promise.resolve({outcome:'dismissed'});
      window.dispatchEvent(event);
    });
    await page.locator('[data-pwa-install]').click();
    assert.equal(await page.evaluate(() => window.promptCount), 1);
    await page.locator('[data-pwa-install]').click();
    assert.equal(await page.locator('[data-pwa-help]').isVisible(), true, 'manual instructions after native prompt is used');
    console.log('PWA: Chrome installability, icons and installation controls passed.');

    const signedInPage = await login(first);
    const cookies = await remembered(first);
    assert.equal(cookies.length, 1);
    assert.equal(cookies[0].httpOnly, true);
    assert.equal(cookies[0].sameSite, 'Lax');
    assert(Math.abs(cookies[0].expires - Date.now()/1000 - 400*86400) < 30);
    const row = sql('SELECT * FROM remembered_logins')[0];
    assert.equal(row.token_hash, createHash('sha256').update(cookies[0].value).digest('hex'), 'DB stores only token hash');
    assert.equal(await signedInPage.evaluate(() => document.cookie.includes('sesame_portal_remember')), false);
    assert.equal(await signedInPage.evaluate(() => localStorage.length), 0);
    await first.close();
    installContext = null;
    // Browser restart with no PHP session cookie: device cookie alone restores login.
    const restored = await context(cookies);
    const restoredPage = await restored.newPage();
    await restoredPage.goto(base + '/login');
    assert.equal(restoredPage.url(), base + '/');
    assert.equal(await allowed(restored), true);
    const parallel = await context(cookies);
    assert.equal(await allowed(parallel), true, 'Safari copied cookie can restore concurrently');
    const secondDevice = await context();
    await login(secondDevice);
    const secondCookies = await remembered(secondDevice);
    await restoredPage.goto(base + '/logout');
    assert.equal(restoredPage.url(), base + '/login');
    assert.equal((await remembered(restored)).length, 0);
    assert.equal(await allowed(parallel), false, 'logout revokes restored sessions sharing the token');
    const replay = await context(cookies);
    assert.equal(await allowed(replay), false, 'logged-out cookie cannot be replayed');
    assert.equal(await allowed(secondDevice), true, 'other devices remain logged in');
    console.log('PWA: restart, concurrent restore, logout and device isolation passed.');

    await updateUser({password:'changed-password'});
    assert.equal(await allowed(secondDevice), false, 'password change revokes existing session');
    const oldPasswordCookie = await context(secondCookies);
    assert.equal(await allowed(oldPasswordCookie), false);
    const blockedDevice = await context();
    await login(blockedDevice, true, 'user', 'changed-password');
    const blockedCookie = await remembered(blockedDevice);
    await updateUser({blocked:true});
    await updateUser({blocked:false});
    assert.equal(await allowed(blockedDevice), false, 'block/unblock does not resurrect remembered session');
    const unblocked = await context(blockedCookie);
    assert.equal(await allowed(unblocked), false);

    const oldDevice = await context();
    await login(oldDevice, true, 'user', 'changed-password');
    const oldCookies = await remembered(oldDevice);
    sql('UPDATE remembered_logins SET created_at = 1');
    assert.equal(await allowed(oldDevice), true, 'device credential has no fixed expiry');
    const oldRestart = await context([{...oldCookies[0], expires:Date.now()/1000 + 60}]);
    assert.equal(await allowed(oldRestart), true, 'old credential restores a fresh PHP session');
    assert((await remembered(oldRestart))[0].expires > Date.now()/1000 + 399*86400, 'browser cookie is renewed on restore');
    const tampered = await context([{...cookies[0], value:'a'.repeat(64)}]);
    assert.equal(await allowed(tampered), false);
    const malformed = await context([{...cookies[0], value:'not-a-token'}]);
    assert.equal(await allowed(malformed), false);
    const ordinary = await context();
    await login(ordinary, false, 'user', 'changed-password');
    assert.equal((await remembered(ordinary)).length, 0);
    const closedOrdinary = await context();
    assert.equal(await allowed(closedOrdinary), false);
    const failed = await context();
    assert.equal((await failed.request.post(base + '/login', {form:{login:'user',password:'wrong',remember:'1'}})).status(), 200);
    assert.equal((await remembered(failed)).length, 0);

    const offline = await context();
    const offlinePage = await login(offline, true, 'user', 'changed-password');
    await offlinePage.evaluate(async () => { await navigator.serviceWorker.ready; });
    await offlinePage.reload();
    const cacheUrls = await offlinePage.evaluate(async () => (await Promise.all((await caches.keys()).map(async key =>
      (await (await caches.open(key)).keys()).map(request => new URL(request.url).pathname)))).flat());
    assert.deepEqual(cacheUrls, ['/offline.html']);
    await offline.setOffline(true);
    await offlinePage.reload();
    assert.equal(await offlinePage.locator('h1').textContent(), 'Нет соединения с порталом');
    assert.equal(await offlinePage.locator('input[name="password"]').count(), 0);
    await offlinePage.screenshot({path:join(artifacts, 'offline-mobile.png')});
    await offline.setOffline(false);
    // The offline page reloads itself on the online event.
    await offlinePage.locator('.shell').waitFor();
    assert.equal(offlinePage.url(), base + '/');
    assert.equal(await offlinePage.locator('.shell').count(), 1);

    const disabledCookie = await remembered(offline);
    writeFileSync(join(state, 'config.php'), "<?php return ['remember_login_enabled' => false];");
    assert.equal(await allowed(offline), false, 'disabling feature ends remembered sessions');
    const disabledRestart = await context(disabledCookie);
    assert.equal(await allowed(disabledRestart), false);
    assert(!(await (await fetch(base + '/login')).text()).includes('name="remember"'));
    writeFileSync(join(state, 'config.php'), "<?php return ['base_url' => 'https://portal.example.test'];");
    const secureHeaders = await fetch(base + '/login');
    assert.match(secureHeaders.headers.get('set-cookie'), /; secure/i, 'HTTPS base_url secures cookies behind proxy');
    assert.match(secureHeaders.headers.get('set-cookie'), /; HttpOnly/i);
    assert.deepEqual(errors, []);
    console.log('PWA browser: installability, icons, mobile/desktop UI, offline/cache isolation, restart, parallel restore, logout, password/block revocation, unlimited device lifetime and cookie renewal, opt-out, invalid cookies and Secure/HttpOnly passed.');
  } finally {
    if (installContext) await installContext.close();
    if (browser) await browser.close();
    if (server && server.exitCode === null) { const exited = once(server, 'exit'); server.kill(); await exited; }
    rmSync(state, {recursive:true, force:true});
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
