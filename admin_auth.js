(function(global){
  'use strict';

  const scriptInfo = (function(){
    const resolveUrl = (value, base) => {
      if (!value) return null;
      try {
        return new URL(value, base || global.location.href);
      } catch (_) {
        return null;
      }
    };

    const current = document.currentScript;
    if (current) {
      const resolved = resolveUrl(current.getAttribute('src') || current.src);
      if (resolved) {
        return { element: current, url: resolved };
      }
    }

    const scripts = document.getElementsByTagName('script');
    for (let i = scripts.length - 1; i >= 0; i--) {
      const candidate = scripts[i];
      if (!candidate || !candidate.src) {
        continue;
      }
      if (candidate.src.indexOf('admin_auth.js') === -1) {
        continue;
      }
      const resolved = resolveUrl(candidate.src);
      if (resolved) {
        return { element: candidate, url: resolved };
      }
    }

    const fallback = resolveUrl('admin_auth.js');
    return {
      element: current || null,
      url: fallback || new URL(global.location.href)
    };
  })();

  const scriptElement = scriptInfo.element;
  const scriptBaseUrl = scriptInfo.url;

  const apiBaseUrl = (function(){
    const resolveUrl = (value) => {
      if (!value) return null;
      try {
        return new URL(value, scriptBaseUrl);
      } catch (_) {
        return null;
      }
    };

    let configured = null;
    if (scriptElement) {
      configured = resolveUrl(scriptElement.getAttribute('data-api-base') || scriptElement.getAttribute('data-auth-base'));
    }
    if (!configured) {
      configured = resolveUrl('./');
    }
    if (!configured) {
      configured = new URL(global.location.href);
    }
    if (global.location.protocol === 'https:' && configured.protocol !== 'https:') {
      configured.protocol = 'https:';
    }
    return configured;
  })();
  const API_BASE = apiBaseUrl.href;
  const TOKEN_ENDPOINT = new URL('admin_login_token.php', apiBaseUrl).href;
  const LOGIN_ENDPOINT = new URL('admin_login.php', apiBaseUrl).href;
  const STYLE_ID = 'mmb-admin-auth-style';

  let overlay;
  let form;
  let usernameInput;
  let passwordInput;
  let errorBox;
  let submitButton;
  let pendingResolve = null;
  let pendingReject = null;
  let csrfToken = null;
  let sessionToken = null;
  let ensurePromise = null;
  let authenticated = false;

  // Same-origin srcdoc frames share a login and its in-memory bearer. Never
  // persist credentials or tokens in URLs, localStorage or sessionStorage.
  function getSessionCoordinator(){
    let host = global;
    try {
      if (global.parent && global.parent.document) host = global.parent;
    } catch (_) { /* A standalone/cross-origin embed keeps its own login. */ }
    const key = '__mmbAdminLoginV4';
    let shared = host[key];
    if (!shared || shared.apiBase !== API_BASE || (shared.owner && shared.owner.closed)) {
      shared = { apiBase: API_BASE, pending: null, owner: null, authenticated: false };
      host[key] = shared;
    }
    // Frames can finish loading in either order. Reserve the login for the
    // booking list even when the calendar reaches ensureSession first.
    if (!shared.owner && host !== global) {
      const frames = host.document.querySelectorAll?.('iframe') || [];
      for (const frame of frames) {
        const source = frame.getAttribute('srcdoc') || '';
        if (source.includes('id="mmb-admin-list"') && source.includes(API_BASE)) {
          shared.owner = frame.contentWindow;
          break;
        }
      }
    }
    return shared;
  }

  function setPendingLayout(pending){
    ensureStyle();
    document.documentElement.classList.add('mmb-admin-document');
    document.body.classList.add('mmb-admin-embed');
    document.body.classList.toggle('mmb-auth-pending', pending);
  }

  let waiting;
  function showWaiting(){
    setPendingLayout(true);
    document.body.classList.add('mmb-auth-waiting');
    if (!waiting) {
      waiting = document.createElement('div');
      waiting.className = 'mmb-login-waiting';
      waiting.setAttribute('role', 'status');
      waiting.textContent = 'Bitte oben in der Buchungsübersicht anmelden. Beide Bereiche werden gemeinsam freigeschaltet.';
      document.body.appendChild(waiting);
    }
    waiting.hidden = false;
  }

  function readyLayout(){
    document.body.classList.remove('mmb-auth-waiting');
    if (waiting) waiting.hidden = true;
    hideOverlay();
    setPendingLayout(false);
    updateViewportHeight();
  }

  function updateViewportHeight(){
    let host = global;
    try { if (global.parent?.document) host = global.parent; } catch (_) {}
    const header = host.document.querySelector?.('header');
    const headerHeight = header?.getBoundingClientRect().height || 80;
    const height = Math.max(240, (host.visualViewport?.height || host.innerHeight || 720) - headerHeight - 24);
    document.documentElement.style.setProperty('--mmb-viewport-height', height + 'px');
    let panelTop = 0;
    if (host !== global && global.frameElement) {
      const frame = global.frameElement.getBoundingClientRect();
      const headerBottom = header?.getBoundingClientRect().bottom || headerHeight;
      panelTop = Math.min(Math.max(0, headerBottom + 12 - frame.top), Math.max(0, frame.height - 240));
    }
    document.documentElement.style.setProperty('--mmb-panel-top', panelTop + 'px');
  }

  function focusPanel(){
    updateViewportHeight();
    try {
      if (global.parent !== global && global.frameElement && global.parent.innerWidth <= 900) {
        const host = global.parent;
        const header = host.document.querySelector('header');
        host.scrollTo({top:host.scrollY + global.frameElement.getBoundingClientRect().top - (header?.getBoundingClientRect().height || 80) - 12, behavior:'instant'});
      }
    } catch (_) {}
  }
  function focusSection(id){
    if (!['mmb-admin-list','mmb-admin-cal'].includes(id)) return;
    try {
      const host = global.parent;
      const frame = Array.from(host.document.querySelectorAll('iframe')).find(el=>(el.getAttribute('srcdoc') || '').includes('id="' + id + '"'));
      if (frame) {
        const header = host.document.querySelector('header');
        host.scrollTo({top:host.scrollY + frame.getBoundingClientRect().top - (header?.getBoundingClientRect().height || 80) - 12, behavior:'smooth'});
      }
    } catch (_) {}
  }
  global.addEventListener?.('resize', updateViewportHeight);
  try {
    const host = global.parent;
    host.visualViewport?.addEventListener('resize', updateViewportHeight);
    host.addEventListener?.('scroll', updateViewportHeight, {passive:true});
    global.addEventListener?.('pagehide', () => {
      host.visualViewport?.removeEventListener('resize', updateViewportHeight);
      host.removeEventListener?.('scroll', updateViewportHeight);
    }, {once:true});
  } catch (_) {}

  function ensureStyle(){
    if (document.getElementById(STYLE_ID)) {
      return;
    }
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
      html.mmb-admin-document{height:auto!important;min-height:720px;overflow:visible!important;}
      body.mmb-admin-embed{height:auto!important;min-height:720px!important;min-width:0;}
      body.mmb-auth-pending #mmb-admin-shell{display:none;}
      body.mmb-admin-embed #mmb-admin-shell{min-width:0;}
      .mmb-login-overlay{position:relative;background:rgba(17,24,39,.65);display:flex;align-items:center;justify-content:center;flex:1;width:100%;min-height:720px;box-sizing:border-box;padding:2rem 1rem;}
      .mmb-login-overlay[hidden]{display:none;}
      .mmb-login-dialog{background:#fff;border-radius:10px;box-shadow:0 20px 45px rgba(15,23,42,.35);max-width:420px;width:100%;padding:2rem;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
      .mmb-login-dialog h2{margin:0 0 .75rem;font-size:1.35rem;font-weight:600;color:#111827;}
      .mmb-login-hint{margin:0 0 1rem;font-size:.95rem;color:#4b5563;}
      .mmb-login-error{margin:0 0 1rem;padding:.75rem;border-radius:.5rem;background:#fee2e2;color:#b91c1c;font-size:.9rem;font-weight:500;}
      .mmb-login-field{display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;font-size:.95rem;color:#374151;}
      .mmb-login-field input{padding:.75rem .85rem;border:1px solid #d1d5db;border-radius:.5rem;font-size:1rem;font-family:inherit;transition:border-color .2s,box-shadow .2s;}
      .mmb-login-field input:focus{outline:none;border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.2);}
      .mmb-login-submit{width:100%;margin-top:.5rem;background:#0ea5e9;border:none;border-radius:.5rem;color:#fff;font-size:1rem;font-weight:600;padding:.85rem 1rem;cursor:pointer;transition:background .2s,transform .2s;}
      .mmb-login-submit:hover{background:#0284c7;}
      .mmb-login-submit[disabled]{opacity:.7;cursor:progress;}
      .mmb-login-footer{margin-top:1rem;font-size:.85rem;color:#6b7280;text-align:center;}
      .mmb-login-waiting{display:flex;align-items:center;justify-content:center;flex:1;min-height:720px;padding:2rem;box-sizing:border-box;color:#e5e7eb;font-family:system-ui,sans-serif;text-align:center;}
      .mmb-login-waiting[hidden]{display:none;}
      @media(max-width:900px){
        .mmb-detail-overlay{top:var(--mmb-panel-top,0px)!important;height:min(calc(100% - var(--mmb-panel-top,0px)),var(--mmb-viewport-height,640px))!important;bottom:auto!important;}
        html.mmb-admin-document{min-height:0;}
        body.mmb-admin-embed{min-height:600px!important;}
        .mmb-login-overlay{min-height:600px;padding:1rem .75rem;}
        .mmb-login-dialog{padding:1.25rem;box-sizing:border-box;min-width:0;}
        .mmb-login-field input{font-size:16px;min-width:0;min-height:44px;}
        .mmb-login-submit{min-height:48px;}
        body.mmb-auth-waiting{min-height:160px!important;}
        .mmb-login-waiting{min-height:160px;padding:1rem;}
      }
    `;
    document.head.appendChild(style);
  }

  function ensureOverlay(){
    if (overlay) {
      return;
    }
    overlay = document.createElement('div');
    overlay.className = 'mmb-login-overlay';
    overlay.hidden = true;
    overlay.innerHTML = `
      <div class="mmb-login-dialog" role="dialog" aria-modal="true" aria-labelledby="mmb-login-title">
        <form class="mmb-login-form" novalidate>
          <h2 id="mmb-login-title">Admin-Login</h2>
          <p class="mmb-login-hint">Bitte melden Sie sich an, um fortzufahren.</p>
          <div class="mmb-login-error" role="alert" hidden></div>
          <label class="mmb-login-field">
            <span>Benutzername</span>
            <input type="text" name="username" autocomplete="username" required />
          </label>
          <label class="mmb-login-field">
            <span>Passwort</span>
            <input type="password" name="password" autocomplete="current-password" required />
          </label>
          <button type="submit" class="mmb-login-submit">Anmelden</button>
          <p class="mmb-login-footer">Die Übertragung erfolgt ausschließlich über HTTPS.</p>
        </form>
      </div>
    `;
    document.body.appendChild(overlay);
    form = overlay.querySelector('form');
    usernameInput = overlay.querySelector('input[name="username"]');
    passwordInput = overlay.querySelector('input[name="password"]');
    errorBox = overlay.querySelector('.mmb-login-error');
    submitButton = overlay.querySelector('.mmb-login-submit');
    form.addEventListener('submit', onSubmit);
  }

  function showOverlay(){
    setPendingLayout(true);
    ensureOverlay();
    overlay.hidden = false;
    overlay.classList.add('is-visible');
    setTimeout(() => { usernameInput?.focus(); }, 50);
  }

  function hideOverlay(){
    if (!overlay) return;
    overlay.hidden = true;
    overlay.classList.remove('is-visible');
  }

  function showError(message){
    if (!errorBox) return;
    if (!message) {
      errorBox.hidden = true;
      errorBox.textContent = '';
      return;
    }
    errorBox.hidden = false;
    errorBox.textContent = message;
  }

  function rememberSession(data){
    if (data.session_token) {
      sessionToken = data.session_token;
      getSessionCoordinator().sessionToken = sessionToken;
    }
  }

  async function apiFetch(url, options = {}){
    const target = new URL(url, apiBaseUrl);
    if (target.origin !== apiBaseUrl.origin || !target.pathname.startsWith(apiBaseUrl.pathname)) {
      throw new Error('Ungültige Admin-API-Adresse');
    }
    const headers = new Headers(options.headers || {});
    const token = getSessionCoordinator().sessionToken || sessionToken;
    if (token) headers.set('Authorization', 'Bearer ' + token);
    return fetch(target.href, {...options, headers, credentials:'include', cache:'no-store'});
  }

  async function fetchState(){
    let res = await apiFetch(TOKEN_ENDPOINT);
    if (res.status === 401) {
      sessionToken = null;
      const shared = getSessionCoordinator();
      shared.sessionToken = null;
      shared.authenticated = false;
      res = await apiFetch(TOKEN_ENDPOINT);
    }
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      const err = new Error('Unexpected response from server');
      err.cause = error;
      throw err;
    }
    if (!res.ok || !data || data.ok !== true) {
      const err = new Error(data && data.error ? data.error : `HTTP ${res.status}`);
      err.isFatal = res.status >= 400 && res.status < 500;
      throw err;
    }
    csrfToken = data.token || null;
    rememberSession(data);
    authenticated = Boolean(data.authenticated);
    return data;
  }

  function formatErrors(errors){
    if (!errors) return '';
    if (typeof errors === 'string') return errors;
    const parts = [];
    for (const key in errors) {
      if (Object.prototype.hasOwnProperty.call(errors, key)) {
        const value = errors[key];
        if (Array.isArray(value)) {
          parts.push(value.join(' '));
        } else if (value) {
          parts.push(String(value));
        }
      }
    }
    return parts.join(' ') || 'Unbekannter Fehler';
  }

  async function performLogin(username, password){
    // Re-read the shared session before submitting: another frame may
    // have refreshed its CSRF token or already completed the login.
    const state = await fetchState();
    if (state.authenticated) return state;
    const body = new URLSearchParams();
    body.set('username', username);
    body.set('password', password);
    if (csrfToken) {
      body.set('csrf_token', csrfToken);
    }
    const res = await apiFetch(LOGIN_ENDPOINT, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      const err = new Error('Unexpected response from server');
      err.cause = error;
      err.isFatal = false;
      throw err;
    }
    if (!res.ok || !data || data.ok !== true) {
      const message = data && (data.error || formatErrors(data.errors)) ? (data.error || formatErrors(data.errors)) : `HTTP ${res.status}`;
      const err = new Error(message);
      err.details = data && data.errors ? data.errors : null;
      err.isFatal = res.status === 400 || res.status === 403;
      throw err;
    }
    authenticated = true;
    rememberSession(data);
    csrfToken = null;
    return data;
  }

  async function onSubmit(event){
    event.preventDefault();
    if (!form) return;
    showError('');
    const username = usernameInput ? usernameInput.value.trim() : '';
    const password = passwordInput ? passwordInput.value : '';
    if (!username || !password) {
      showError('Bitte Benutzername und Passwort eingeben.');
      return;
    }
    if (submitButton) {
      submitButton.disabled = true;
    }
    try {
      await performLogin(username, password);
      readyLayout();
      showError('');
      form.reset();
      if (pendingResolve) {
        const resolver = pendingResolve;
        pendingResolve = null;
        pendingReject = null;
        resolver();
      }
    } catch (error) {
      console.error('Admin login failed', error);
      showError(error && error.message ? error.message : 'Login fehlgeschlagen');
      // Keep the form visible so a wrong password or transient network error
      // can be corrected without reloading both widgets.
      return;
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
      }
    }
  }

  async function ensureSession(){
    if (authenticated) {
      return;
    }
    if (ensurePromise) {
      return ensurePromise;
    }
    setPendingLayout(false);
    const shared = getSessionCoordinator();
    if (shared.authenticated) {
      authenticated = true;
      readyLayout();
      return;
    }
    if (!shared.pending) {
      shared.owner = shared.owner || global;
      shared.started = false;
      shared.pending = new Promise((resolve, reject) => {
        shared.resolve = resolve;
        shared.reject = reject;
      });
    }
    const operation = shared.pending;
    if (shared.owner === global && !shared.started) {
      shared.started = true;
      const login = (async () => {
        let initialError = null;
        try {
          const state = await fetchState();
          if (state.authenticated) return;
        } catch (error) { initialError = error; }
        return new Promise((resolve, reject) => {
          pendingResolve = () => { authenticated = true; resolve(); };
          pendingReject = reject;
          showOverlay();
          if (initialError) showError('Die Anmeldung konnte noch nicht geladen werden. Bitte erneut anmelden.');
        });
      })();
      login.then(shared.resolve, shared.reject);
    } else {
      showWaiting();
    }
    ensurePromise = operation.then(() => {
      authenticated = true;
      shared.authenticated = true;
      readyLayout();
    }).finally(() => {
      if (shared.pending === operation && shared.owner === global) {
        shared.pending = null;
        shared.owner = null;
        shared.resolve = null;
        shared.reject = null;
        shared.started = false;
      }
    });
    try {
      await ensurePromise;
    } finally {
      ensurePromise = null;
    }
  }

  global.MMBAdminAuth = {
    ensureSession,
    fetch: apiFetch,
    focusPanel,
    focusSection,
    getApiBase: () => API_BASE
  };
})(window);
