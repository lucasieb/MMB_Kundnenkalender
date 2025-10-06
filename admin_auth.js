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
  let ensurePromise = null;
  let authenticated = false;
  let publicMode = false;

  function setPublicMode(){
    if (publicMode) {
      return;
    }
    publicMode = true;
    authenticated = true;
  }

  function ensureStyle(){
    if (document.getElementById(STYLE_ID)) {
      return;
    }
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
      .mmb-login-overlay{position:fixed;inset:0;background:rgba(17,24,39,.65);display:flex;align-items:center;justify-content:center;z-index:9999;padding:1.5rem;}
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
    ensureStyle();
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

  function shouldFallbackToPublicMode(error){
    if (!error) {
      return false;
    }
    if (error.isNetworkError) {
      return true;
    }
    if (typeof error.status === 'number' && (error.status === 0 || error.status === 401 || error.status === 403)) {
      return true;
    }
    return false;
  }

  async function requestState(credentialsMode){
    let res;
    try {
      res = await fetch(TOKEN_ENDPOINT, { credentials: credentialsMode });
    } catch (error) {
      if (error && typeof error === 'object') {
        error.isNetworkError = true;
      }
      throw error;
    }

    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      const err = new Error('Unexpected response from server');
      err.cause = error;
      err.status = res.status;
      err.raw = text;
      throw err;
    }

    if (!res.ok || !data || data.ok !== true) {
      const err = new Error(data && data.error ? data.error : `HTTP ${res.status}`);
      err.isFatal = res.status >= 400 && res.status < 500;
      err.status = res.status;
      err.payload = data;
      throw err;
    }

    csrfToken = data.token || null;
    if (data.auth_disabled || (data.authenticated && !data.token)) {
      setPublicMode();
    }
    if (data.authenticated) {
      authenticated = true;
    }
    return data;
  }

  async function fetchState(){
    try {
      return await requestState('include');
    } catch (error) {
      if (!shouldFallbackToPublicMode(error)) {
        throw error;
      }
      try {
        const fallbackState = await requestState('omit');
        setPublicMode();
        return fallbackState;
      } catch (fallbackError) {
        fallbackError.originalError = error;
        throw fallbackError;
      }
    }
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
    if (!csrfToken) {
      await fetchState();
    }
    const body = new URLSearchParams();
    body.set('username', username);
    body.set('password', password);
    if (csrfToken) {
      body.set('csrf_token', csrfToken);
    }
    const res = await fetch(LOGIN_ENDPOINT, {
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
      hideOverlay();
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
      if (error && error.isFatal && pendingReject) {
        pendingReject(error);
        pendingResolve = null;
        pendingReject = null;
        hideOverlay();
      }
      return;
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
      }
    }
  }

  async function ensureSession(){
    if (authenticated || publicMode) {
      return;
    }
    if (ensurePromise) {
      return ensurePromise;
    }
    ensurePromise = (async () => {
      const state = await fetchState();
      if (publicMode || state.authenticated) {
        authenticated = true;
        return;
      }
      return new Promise((resolve, reject) => {
        pendingResolve = () => {
          authenticated = true;
          resolve();
        };
        pendingReject = reject;
        showOverlay();
      });
    })();
    try {
      await ensurePromise;
    } finally {
      ensurePromise = null;
    }
  }

  global.MMBAdminAuth = {
    ensureSession,
    getApiBase: () => API_BASE,
    shouldSendCredentials: () => !publicMode,
    enterPublicMode: () => { setPublicMode(); }
  };
})(window);
