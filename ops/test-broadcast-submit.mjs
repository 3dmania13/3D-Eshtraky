// Run with the fetched dashboard PHP path. Uses its actual submit handler,
// a synthetic form, and a stubbed fetch: no broadcast is sent.
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';
const page = readFileSync(new URL('../nawa-notifications.php.remote', import.meta.url), 'utf8');
const dashboard = readFileSync(process.argv[2], 'utf8');
const start = dashboard.lastIndexOf("area.querySelectorAll('form').forEach(function(form)");
const end = dashboard.indexOf('function loadUsersList', start);
assert.ok(start >= 0 && end > start);
const handler = dashboard.slice(start, end).trim();
const formMarkup = page.match(/<form method="post"[\s\S]*?<\/form>/)[0];
assert.ok(page.includes('broadcast-alert ok nawa-alert'));
assert.ok(page.includes('broadcast-alert error nawa-alert danger'));
for (const failure of [false, true]) {
  let submit;
  const calls = [];
  const button = { innerHTML: 'Send', disabled: false };
  const form = {
    querySelector(selector) {
      if (selector === 'input[name="action"]') return formMarkup.includes('name="action"') ? {} : null;
      return button;
    },
    getAttribute() {
      return formMarkup.match(/action="([^"]+)"/)[1].replace(/<\?=.*?\?>/, '?fragment=1');
    },
    addEventListener(name, fn) { if (name === 'submit') submit = fn; },
  };
  const section = 'nawa-notifications.php?fragment=1';
  runInNewContext(handler, {
    area: { querySelectorAll: () => [form] },
    window: { nawaCurrentSectionUrl: section, location: { href: 'http://router/operators/home-modern.php' } },
    URL, console,
    FormData: class { constructor(f) { assert.equal(f, form); } },
    fetch: async (target, options) => {
      assert.equal(target, section);
      assert.equal(options.method, 'POST');
      calls.push('post');
      return { ok: true, text: async () => 'response' };
    },
    DOMParser: class { parseFromString() { return { querySelector: () => ({ textContent: failure ? 'failed' : 'queued', classList: { contains: () => failure } }) }; } },
    nawaInvalidateSection: url => { assert.equal(url, section); calls.push('invalidate'); },
    loadSection: async url => { assert.equal(url, section); calls.push('refresh'); },
    showNawaActionToast: (message, isError) => { assert.equal(isError, failure); assert.equal(message, failure ? 'failed' : 'queued'); calls.push('result'); },
    alert: () => assert.fail('Unexpected transport error'),
  });
  assert.equal(typeof submit, 'function', 'Dashboard must attach the submit handler');
  await submit({ defaultPrevented: false, preventDefault() {} });
  assert.deepEqual(calls, ['post', 'invalidate', 'refresh', 'result']);
}
console.log('PASS: correct POST endpoint, history refresh, success and failure feedback; no messages sent.');
