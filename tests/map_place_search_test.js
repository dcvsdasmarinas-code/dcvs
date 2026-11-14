import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function createElement(tagName) {
  const listeners = {};
  const element = {
    tagName,
    children: [],
    attributes: {},
    className: '',
    classList: { add() {} },
    hidden: false,
    listeners,
    textContent: '',
    value: '',
    addEventListener(name, listener) { listeners[name] = listener; },
    appendChild(child) { this.children.push(child); return child; },
    click() { if (listeners.click) listeners.click({ target: this }); },
    contains() { return false; },
    getAttribute(name) { return this.attributes[name] ?? null; },
    querySelector(selector) {
      if (selector === 'input[type="search"]') return input;
      if (selector === '.dcvs-place-results') return results;
      return null;
    },
    replaceChildren() { this.children = []; },
    setAttribute(name, value) { this.attributes[name] = value; }
  };
  return element;
}

const input = createElement('input');
input.value = 'San Jose';
const results = createElement('div');
const status = createElement('div');
const mapElement = createElement('div');
const wrapper = createElement('div');
wrapper.attributes = {
  'data-status-target': '#place-status',
  'data-map-target': 'geoMap',
  'data-geocode-endpoint': '/api/shared/location-geocode.php',
  'data-portal': 'staff'
};

const document = {
  addEventListener() {},
  createElement,
  getElementById(id) { return id === 'geoMap' ? mapElement : null; },
  querySelector(selector) { return selector === '#place-status' ? status : null; },
  querySelectorAll(selector) { return selector === '.dcvs-place-search' ? [wrapper] : []; }
};
const window = {
  MapCN: {
    barangayOverlay: {
      suggest() { return [{ id: 8, name: 'San Jose' }]; },
      search(name) {
        assert.equal(name, 'San Jose');
        return true;
      }
    }
  }
};
const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'map-place-search.js'), 'utf8');

vm.runInNewContext(source, {
  clearTimeout() {},
  document,
  setTimeout() { return 1; },
  window
});

input.listeners.input();
const barangayGroup = results.children.find(child => child.attributes['aria-label'] === 'Barangays');
assert.ok(barangayGroup, 'barangay suggestions should be grouped as barangays');
const option = barangayGroup.children.find(child => child.className === 'dcvs-place-option');
assert.ok(option);
assert.equal(option.children[0].textContent, 'San Jose');
assert.equal(option.children[2].textContent, 'Barangay');

option.click();
assert.equal(input.value, 'San Jose');
assert.equal(status.textContent, 'Showing San Jose on the map.');

console.log('Map place search barangay selection checks passed.');
