(function (global) {
  'use strict';

  function resolveCurrentScript() {
    if (document.currentScript) {
      return document.currentScript;
    }
    const scripts = document.getElementsByTagName('script');
    return scripts.length ? scripts[scripts.length - 1] : null;
  }

  function resolveBaseUrl() {
    const script = resolveCurrentScript();
    const baseAttr = script ? script.getAttribute('data-api-base') : null;
    try {
      return new URL(baseAttr || './', global.location ? global.location.href : 'https://example.com/');
    } catch (_) {
      return new URL('./', global.location ? global.location.href : 'https://example.com/');
    }
  }

  const baseUrl = resolveBaseUrl();
  const STORAGE_KEY = 'mmb-admin-password';
  const unauthorizedListeners = new Set();

  let storedPassword = '';
  try {
    storedPassword = sessionStorage.getItem(STORAGE_KEY) || '';
  } catch (_) {
    storedPassword = '';
  }

  function notifyUnauthorized() {
    setPassword('');
    unauthorizedListeners.forEach((listener) => {
      try {
        listener();
      } catch (_) {
        /* ignore listener errors */
      }
    });
  }

  function getPassword() {
    return storedPassword;
  }

  function setPassword(value) {
    storedPassword = typeof value === 'string' ? value : '';
    try {
      if (storedPassword) {
        sessionStorage.setItem(STORAGE_KEY, storedPassword);
      } else {
        sessionStorage.removeItem(STORAGE_KEY);
      }
    } catch (_) {
      /* ignore storage errors (private mode, etc.) */
    }
  }

  async function validatePassword(candidate) {
    if (!candidate) {
      return false;
    }

    const response = await fetch(new URL('admin_validate_password.php', baseUrl).href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({ password: candidate }),
      mode: 'cors',
      credentials: 'omit',
    });

    if (response.status === 401) {
      return false;
    }

    if (!response.ok) {
      throw new Error(`Serverfehler (${response.status})`);
    }

    const payload = await response.json().catch(() => ({}));
    return !!payload.ok;
  }

  function normalizeRequestBody(body, headers) {
    if (!body || typeof body === 'string' || body instanceof String || body instanceof FormData || body instanceof Blob) {
      return body;
    }
    if (typeof ArrayBuffer !== 'undefined' && (body instanceof ArrayBuffer || body instanceof Uint8Array)) {
      return body;
    }
    if (!headers['Content-Type']) {
      headers['Content-Type'] = 'application/json';
    }
    return JSON.stringify(body);
  }

  async function request(path, options = {}) {
    const method = options.method || 'GET';
    const headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
    const password = getPassword();

    if (!password) {
      const error = new Error('Kein Admin-Passwort gesetzt.');
      error.code = 'NO_PASSWORD';
      throw error;
    }

    headers['X-Admin-Password'] = password;
    const payload = normalizeRequestBody(options.body, headers);
    const url = new URL(path, baseUrl).href;

    const response = await fetch(url, {
      method,
      headers,
      body: payload,
      mode: options.mode || 'cors',
      credentials: 'omit',
    });

    if (response.status === 401) {
      notifyUnauthorized();
      const error = new Error('Passwort ist ungültig oder abgelaufen.');
      error.code = 'UNAUTHORIZED';
      throw error;
    }

    if (!response.ok) {
      const text = await response.text();
      const error = new Error(`Serverfehler (${response.status})`);
      error.code = 'HTTP_ERROR';
      error.status = response.status;
      error.body = text;
      throw error;
    }

    const text = await response.text();
    if (!text) {
      return null;
    }

    try {
      return JSON.parse(text);
    } catch (_) {
      return text;
    }
  }

  function onUnauthorized(callback) {
    if (typeof callback === 'function') {
      unauthorizedListeners.add(callback);
    }
    return function detach() {
      unauthorizedListeners.delete(callback);
    };
  }

  function renderLogin(container, options) {
    const title = options && options.title ? String(options.title) : 'Admin-Zugriff';
    const description = options && options.description
      ? String(options.description)
      : 'Bitte gib das Admin-Passwort ein, um fortzufahren.';

    container.innerHTML = '';

    const form = document.createElement('form');
    form.className = 'admin-login-form';
    form.innerHTML = `
      <div class="admin-login-form__heading">
        <h2>${title}</h2>
        <p>${description}</p>
      </div>
      <label class="admin-login-form__field">
        <span>Passwort</span>
        <input type="password" name="password" autocomplete="current-password" required />
      </label>
      <button type="submit">Anmelden</button>
      <p class="admin-login-form__message" aria-live="assertive"></p>
    `;

    container.appendChild(form);

    const input = form.querySelector('input[name="password"]');
    const button = form.querySelector('button[type="submit"]');
    const message = form.querySelector('.admin-login-form__message');

    function setMessage(text, type) {
      if (!message) {
        return;
      }
      message.textContent = text || '';
      message.classList.remove('is-error', 'is-success');
      if (type) {
        message.classList.add(type === 'error' ? 'is-error' : 'is-success');
      }
    }

    async function handleSubmit(event) {
      event.preventDefault();
      if (!input || !button) {
        return;
      }
      const value = input.value.trim();
      if (!value) {
        setMessage('Bitte Passwort eingeben.', 'error');
        input.focus();
        return;
      }
      button.disabled = true;
      button.textContent = 'Prüfe Passwort …';
      setMessage('', null);
      try {
        const ok = await validatePassword(value);
        if (!ok) {
          setMessage('Passwort ist ungültig.', 'error');
          button.disabled = false;
          button.textContent = 'Anmelden';
          input.focus();
          input.select();
          return;
        }
        setPassword(value);
        setMessage('Erfolgreich angemeldet.', 'success');
        if (typeof options.onSuccess === 'function') {
          await options.onSuccess();
        }
      } catch (error) {
        setMessage(error && error.message ? String(error.message) : 'Unbekannter Fehler.', 'error');
        button.disabled = false;
        button.textContent = 'Anmelden';
      }
    }

    form.addEventListener('submit', handleSubmit);

    if (input) {
      input.focus();
    }

    return {
      setMessage,
      focus() {
        if (input) {
          input.focus();
          input.select();
        }
      },
    };
  }

  function mount(container, options = {}) {
    if (!container) {
      throw new Error('Container fehlt.');
    }

    const shell = document.createElement('div');
    shell.className = 'admin-shell';
    const loginHost = document.createElement('div');
    loginHost.className = 'admin-shell__login';
    const contentHost = document.createElement('div');
    contentHost.className = 'admin-shell__content';

    shell.appendChild(loginHost);
    shell.appendChild(contentHost);

    container.innerHTML = '';
    container.appendChild(shell);

    let cleanupUnauthorized = null;

    async function showApp() {
      shell.classList.add('admin-shell--authenticated');
      contentHost.innerHTML = '';
      if (typeof options.renderApp === 'function') {
        await options.renderApp(contentHost);
      }
    }

    function showLogin(messageText) {
      shell.classList.remove('admin-shell--authenticated');
      contentHost.innerHTML = '';
      const login = renderLogin(loginHost, {
        title: options.loginTitle,
        description: options.loginDescription,
        async onSuccess() {
          await showApp();
        },
      });
      if (messageText) {
        login.setMessage(messageText, 'error');
      }
    }

    cleanupUnauthorized = onUnauthorized(() => {
      showLogin('Bitte Passwort erneut eingeben.');
    });

    showLogin();

    const existing = getPassword();
    if (existing) {
      validatePassword(existing)
        .then((ok) => {
          if (ok) {
            showApp();
          } else {
            setPassword('');
          }
        })
        .catch(() => {
          setPassword('');
        });
    }

    return {
      destroy() {
        if (cleanupUnauthorized) {
          cleanupUnauthorized();
          cleanupUnauthorized = null;
        }
      },
      refresh() {
        if (shell.classList.contains('admin-shell--authenticated')) {
          showApp();
        }
      },
    };
  }

  global.MMBAdminPortal = {
    mount,
    request,
    validatePassword,
    getPassword,
    setPassword,
    onUnauthorized,
    getApiBase() {
      return baseUrl.href;
    },
  };
})(typeof window !== 'undefined' ? window : this);
