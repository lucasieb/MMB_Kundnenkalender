import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../admin_auth.js', import.meta.url), 'utf8');
class Element {
  constructor() {
    this.children = [];
    this.queries = new Map();
    this.events = {};
    this.hidden = false;
    this.value = '';
    this.style = {setProperty(){}};
    const classes = new Set();
    this.classList = {
      add: name => classes.add(name),
      remove: name => classes.delete(name),
      toggle: (name, value) => value ? classes.add(name) : classes.delete(name),
      contains: name => classes.has(name),
    };
  }
  appendChild(child) { this.children.push(child); }
  setAttribute(name, value) { this[name] = value; }
  getAttribute(name) { return this[name] || null; }
  addEventListener(name, handler) { this.events[name] = handler; }
  querySelector(selector) {
    if (!this.queries.has(selector)) this.queries.set(selector, new Element());
    return this.queries.get(selector);
  }
  reset() {}
  focus() {}
}
function frame(parent, server) {
  const head = new Element(), body = new Element(), html = new Element();
  const script = new Element();
  script.src = 'https://backend.example/admin_auth.js';
  script['data-api-base'] = 'https://backend.example/';
  const document = {
    head, body, documentElement: html, currentScript: script,
    createElement: () => new Element(),
    getElementById: id => head.children.find(child => child.id === id),
    getElementsByTagName: () => [script],
  };
  const context = {
    document, parent, closed: false, location: {href:'https://www.mietmichbox.de/buchungsverwaltung',protocol:'https:'},
    URL, URLSearchParams, Headers, setTimeout, console: {error(){}}, fetch: server.fetch,
  };
  context.window = context;
  vm.runInNewContext(source, context);
  return vm.runInContext('window', context);
}
function backend() {
  const state = {authenticated:false,token:'initial',sessionToken:'anonymous.transport',gets:0,posts:0,offline:false};
  state.fetch = async (url, options = {}) => {
    if (state.offline) throw new Error('offline');
    const authorization = new Headers(options.headers).get('Authorization');
    if (url.endsWith('list_bookings.php')) {
      assert.equal(authorization,'Bearer ' + state.sessionToken, 'both frames must use the rotated login token');
      return {ok:state.authenticated,status:state.authenticated?200:401};
    }
    if (url.endsWith('admin_login_token.php')) {
      state.gets++;
      state.token = 'token-' + state.gets;
      return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,authenticated:state.authenticated,token:state.token,session_token:state.sessionToken})};
    }
    state.posts++;
    assert.equal(authorization,'Bearer ' + state.sessionToken, 'login must carry its session even without cookies');
    const body = new URLSearchParams(options.body);
    assert.equal(body.get('csrf_token'), state.token, 'CSRF must be refreshed before login');
    const ok = body.get('username') === 'test-user' && body.get('password') === 'test-password';
    if (ok) { state.authenticated = true; state.sessionToken='authenticated.rotated'; }
    return {ok,status:ok?200:401,text:async()=>JSON.stringify({ok,error:ok?'':'Login fehlgeschlagen',session_token:ok?state.sessionToken:null})};
  };
  return state;
}
const settle = () => new Promise(resolve => setImmediate(resolve));
function overlay(frame) {
  return frame.document.body.children.find(child => child.className === 'mmb-login-overlay');
}
async function submit(frame, password) {
  const dialog = overlay(frame);
  dialog.querySelector('input[name="username"]').value = 'test-user';
  dialog.querySelector('input[name="password"]').value = password;
  await dialog.querySelector('form').events.submit({preventDefault(){}});
}

test('one login unlocks both embedded tools; wrong password stays retryable', async () => {
  const server = backend(), parent = {document:{}};
  const top = frame(parent,server), bottom = frame(parent,server);
  const first = top.MMBAdminAuth.ensureSession();
  const second = bottom.MMBAdminAuth.ensureSession();
  await settle();
  assert.equal(server.gets,1, 'initial session request must be shared');
  assert.ok(overlay(top));
  assert.equal(overlay(bottom),undefined, 'second frame must not request credentials');
  assert.ok(bottom.document.body.children.some(child=>child.className==='mmb-login-waiting'));
  await submit(top,'wrong');
  assert.equal(overlay(top).hidden,false);
  server.token = 'changed-by-another-request';
  await submit(top,'test-password');
  await Promise.all([first,second]);
  assert.equal(server.posts,2);
  assert.equal(overlay(top).hidden,true);
  assert.equal(bottom.document.body.classList.contains('mmb-auth-pending'),false);
  assert.equal(top.document.body.classList.contains('mmb-auth-pending'),false);
  assert.equal((await top.MMBAdminAuth.fetch('list_bookings.php')).status,200);
  assert.equal((await bottom.MMBAdminAuth.fetch('list_bookings.php')).status,200);
  await assert.rejects(bottom.MMBAdminAuth.fetch('https://unrelated.example/list_bookings.php'),/API-Adresse/);
  const reloaded = frame(parent,server);
  await reloaded.MMBAdminAuth.ensureSession();
  assert.equal(overlay(reloaded),undefined, 'existing session must survive frame reload');
});

test('login participates in document sizing and recovers from initial connection failure', async () => {
  const server=backend(), parent={document:{}};
  server.offline=true;
  const widget=frame(parent,server);
  const pending=widget.MMBAdminAuth.ensureSession();
  await settle();
  assert.ok(overlay(widget),'connection failure must leave a usable login form');
  const css=widget.document.head.children.map(child=>child.textContent||'').join('');
  assert.match(css,/\.mmb-login-overlay\{position:relative/);
  assert.match(css,/min-height:720px/);
  assert.ok(widget.document.documentElement.classList.contains('mmb-admin-document'));
  server.offline=false;
  await submit(widget,'test-password');
  await pending;
  assert.equal(overlay(widget).hidden,true);
});

test('booking list owns the login even when the calendar finishes loading first', async () => {
  const server = backend(), parent = {document:{}};
  const top = frame(parent,server), bottom = frame(parent,server);
  parent.document.querySelectorAll = () => [{
    contentWindow:top,
    getAttribute:() => '<div id="mmb-admin-list"></div><script src="https://backend.example/admin_auth.js"></script>',
  }];
  const calendar = bottom.MMBAdminAuth.ensureSession();
  await settle();
  assert.equal(server.gets,0, 'calendar must wait for the booking list');
  assert.equal(overlay(bottom),undefined);
  const list = top.MMBAdminAuth.ensureSession();
  await settle();
  assert.equal(server.gets,1);
  assert.ok(overlay(top));
  await submit(top,'test-password');
  await Promise.all([list,calendar]);
  assert.equal(server.posts,1);
  assert.equal(top.document.body.classList.contains('mmb-auth-pending'),false);
  assert.equal(bottom.document.body.classList.contains('mmb-auth-pending'),false);
});

test('a late calendar uses the session already confirmed by the booking list', async () => {
  const server = backend(), parent = {document:{}};
  server.authenticated = true;
  const top = frame(parent,server), bottom = frame(parent,server);
  parent.document.querySelectorAll = () => [{
    contentWindow:top,
    getAttribute:() => '<div id="mmb-admin-list"></div><script src="https://backend.example/admin_auth.js"></script>',
  }];
  await top.MMBAdminAuth.ensureSession();
  await bottom.MMBAdminAuth.ensureSession();
  assert.equal(server.gets,1);
  assert.equal(overlay(top),undefined);
  assert.equal(overlay(bottom),undefined);
  assert.equal(bottom.document.body.classList.contains('mmb-auth-pending'),false);
});
