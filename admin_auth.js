(function(global){
  'use strict';

  function resolveScript(){
    if (document.currentScript) {
      return document.currentScript;
    }
    const scripts = document.getElementsByTagName('script');
    for (let i = scripts.length - 1; i >= 0; i--) {
      const candidate = scripts[i];
      if (candidate && typeof candidate.src === 'string' && candidate.src.indexOf('admin_auth.js') !== -1) {
        return candidate;
      }
    }
    return null;
  }

  function resolveUrl(value, base){
    if (!value) {
      return null;
    }
    try {
      return new URL(value, base);
    } catch (_) {
      return null;
    }
  }

  const scriptElement = resolveScript();
  const scriptBase = (() => {
    if (scriptElement && scriptElement.src) {
      const resolved = resolveUrl(scriptElement.src, global.location.href);
      if (resolved) {
        return resolved;
      }
    }
    return new URL(global.location.href);
  })();

  const attributeBase = scriptElement
    ? (scriptElement.getAttribute('data-api-base') || scriptElement.getAttribute('data-auth-base'))
    : null;

  const apiBaseUrl = (() => {
    const configured = resolveUrl(attributeBase || './', scriptBase);
    if (configured) {
      return configured;
    }
    return new URL('./', scriptBase);
  })();

  let apiBase = apiBaseUrl.href;
  if (!apiBase.endsWith('/')) {
    apiBase += apiBase.indexOf('?') === -1 ? '/' : '';
  }

  const stateSnapshot = {
    authenticated: true,
    authDisabled: true,
    bootstrapSkipped: true,
    lastState: {
      reason: 'admin_auth_disabled',
      timestamp: new Date().toISOString(),
    },
  };

  function ensureSession(){
    return Promise.resolve(stateSnapshot);
  }

  function getSessionState(){
    return Object.assign({}, stateSnapshot);
  }

  function noop(){}

  const api = {
    ensureSession,
    getApiBase: () => apiBase,
    getSessionState,
    isAuthDisabled: () => true,
    wasBootstrapSkipped: () => true,
    shouldSendCredentials: () => false,
    enterPublicMode: noop,
    exitPublicMode: noop,
  };

  if (!global.__MMB_ADMIN_AUTH_NOTICE__) {
    global.__MMB_ADMIN_AUTH_NOTICE__ = true;
    try {
      console.info('MMBAdminAuth: Admin-Login ist deaktiviert. API-Aufrufe erfolgen ohne Sitzung.');
    } catch (_) {
      /* ignore */
    }
  }

  Object.defineProperty(global, 'MMBAdminAuth', {
    value: api,
    writable: false,
    configurable: false,
  });
})(window);
