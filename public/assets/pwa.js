(() => {
  'use strict';
  if (!window.isSecureContext) return;

  const controls = [...document.querySelectorAll('[data-pwa-controls]')];
  const buttons = [...document.querySelectorAll('[data-pwa-install]')];
  const dialog = document.querySelector('[data-pwa-help]');
  const standalone = window.matchMedia('(display-mode: standalone)');
  const ios = /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const android = /Android/i.test(navigator.userAgent);
  const platform = ios ? 'ios' : android ? 'android' : 'desktop';
  const dismissalKey = 'sesame-portal-pwa-install-dismissed-until';
  let installPrompt = null;
  let installed = false;
  let dismissedUntil = 0;
  try { dismissedUntil = Number(localStorage.getItem(dismissalKey)) || 0; } catch (_) {}
  const inApp = () => installed || standalone.matches || navigator.standalone === true;
  const closeHelp = () => {
    if (!dialog?.open) return;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  };
  const refresh = () => {
    controls.forEach(control => {
      control.hidden = inApp() || (control.hasAttribute('data-pwa-suggestion')
        && (platform === 'desktop' || dismissedUntil > Date.now()));
    });
    if (inApp()) closeHelp();
  };
  const snooze = () => {
    dismissedUntil = Date.now() + 30 * 86400 * 1000;
    try { localStorage.setItem(dismissalKey, String(dismissedUntil)); } catch (_) {}
    refresh();
  };
  refresh();
  standalone.addEventListener('change', refresh);
  window.addEventListener('storage', event => {
    if (event.key === dismissalKey || event.key === null) {
      dismissedUntil = Number(event.newValue) || 0;
      refresh();
    }
  });
  if ((standalone.matches || navigator.standalone === true) && navigator.storage?.persist) {
    navigator.storage.persist().catch(() => {});
  }

  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    installPrompt = event;
    refresh();
  });
  window.addEventListener('appinstalled', () => {
    installPrompt = null;
    installed = true;
    snooze();
  });
  const showHelp = () => {
    if (!dialog || inApp()) return;
    dialog.querySelectorAll('[data-pwa-platform]').forEach(section => {
      section.hidden = section.dataset.pwaPlatform !== platform;
    });
    // Share only the portal entry point, never camera URLs, tokens or query strings.
    dialog.querySelector('[data-pwa-url]').value = new URL('/', location.href).href;
    dialog.querySelector('[data-pwa-copy-status]').textContent = '';
    if (!dialog.open) {
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
    }
  };
  document.querySelectorAll('[data-pwa-dismiss]').forEach(button => button.addEventListener('click', snooze));
  dialog?.querySelectorAll('[data-pwa-close]').forEach(button => button.addEventListener('click', closeHelp));
  dialog?.addEventListener('click', event => {
    const rect = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right
      || event.clientY < rect.top || event.clientY > rect.bottom)) closeHelp();
  });
  dialog?.querySelector('[data-pwa-copy]').addEventListener('click', async () => {
    const address = dialog.querySelector('[data-pwa-url]');
    const status = dialog.querySelector('[data-pwa-copy-status]');
    try {
      await navigator.clipboard.writeText(address.value);
      status.textContent = status.dataset.copied;
    } catch (_) {
      address.focus();
      address.select();
      address.setSelectionRange(0, address.value.length);
      status.textContent = status.dataset.select;
    }
  });
  buttons.forEach(button => {
    button.addEventListener('click', async () => {
      if (!installPrompt) {
        showHelp();
        return;
      }
      const prompt = installPrompt;
      installPrompt = null;
      buttons.forEach(item => { item.disabled = true; });
      try {
        await prompt.prompt();
        await prompt.userChoice;
      } catch (_) {
        showHelp();
      } finally {
        buttons.forEach(item => { item.disabled = false; });
      }
    });
  });

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js', {scope: '/', updateViaCache: 'none'})
      .catch(() => { /* Login and video remain available if registration is blocked. */ });
  }
})();
