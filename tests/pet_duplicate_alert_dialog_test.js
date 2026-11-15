import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const dashboard = fs.readFileSync(path.join(root, 'pages', 'resident', 'dashboard-resident.php'), 'utf8');
const scriptStart = dashboard.indexOf('var addPetForm = document.getElementById');
const scriptEnd = dashboard.indexOf('</script>', scriptStart);
assert.ok(scriptStart >= 0 && scriptEnd > scriptStart, 'Pet registration script should exist.');

const registrationScript = dashboard
  .slice(scriptStart, scriptEnd)
  .replace(/<\?php[\s\S]*?\?>/g, JSON.stringify('/dcvs/api/resident/check-pet-duplicates.php'));
assert.doesNotMatch(registrationScript, /<\?php/);
assert.match(dashboard, /<dialog id="petDuplicateAlertDialog" role="alertdialog"/);
assert.doesNotMatch(registrationScript, /window\.confirm/);

function createRegistrationFlow() {
  const listeners = {};
  const submitButton = { disabled: false, textContent: 'Register Pet' };
  const formFields = {
    pet_name: { value: 'Bruno' },
    species: { value: 'dog' },
    species_custom: { value: '' },
    breed: { value: 'Aspin' },
    gender: { value: 'male' },
    age: { value: '5' },
    color: { value: 'brown' },
    date_of_birth: { value: '' },
    microchip_id: { value: '' },
    csrf_token: { value: 'test-token' }
  };
  const form = {
    dataset: {},
    elements: { namedItem: (name) => formFields[name] || null },
    addEventListener: (type, callback) => { listeners[type] = callback; },
    querySelector: (selector) => selector === 'button[type="submit"]' ? submitButton : null,
    submitCount: 0,
    submit() { this.submitCount += 1; }
  };
  const dialogListeners = {};
  const dialog = {
    open: false,
    returnValue: '',
    addEventListener(type, callback) { dialogListeners[type] = callback; },
    showModal() { this.open = true; },
    close(value) {
      this.open = false;
      this.returnValue = value;
      dialogListeners.close();
    }
  };
  const description = { textContent: '' };
  const cancel = { addEventListener(type, callback) { this[type] = callback; } };
  const proceed = { addEventListener(type, callback) { this[type] = callback; } };
  const elements = {
    addPetForm: form,
    petDuplicateAlertDialog: dialog,
    petDuplicateAlertDescription: description,
    petDuplicateAlertCancel: cancel,
    petDuplicateAlertContinue: proceed
  };
  const alerts = [];

  vm.runInNewContext(registrationScript, {
    document: { getElementById: (id) => elements[id] || null },
    window: { alert: (message) => alerts.push(message) },
    URLSearchParams,
    Promise,
    fetch: (url) => {
      assert.equal(url, '/dcvs/api/resident/check-pet-duplicates.php');
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          success: true,
          resident_matches: [{ pet_name: 'Bruno', pet_type: 'dog', matched_fields: ['breed', 'gender'] }],
          staff_review_possible: false
        })
      });
    }
  });

  return {
    form,
    submitButton,
    dialog,
    description,
    cancel,
    proceed,
    alerts,
    submit: () => listeners.submit({ preventDefault() {} })
  };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

const continued = createRegistrationFlow();
continued.submit();
await flush();
assert.equal(continued.dialog.open, true);
assert.match(continued.description.textContent, /Bruno \(dog\)/);
assert.match(continued.description.textContent, /breed and gender also match/);
assert.match(continued.description.textContent, /A matching name alone does not trigger this warning/);
continued.proceed.click();
await flush();
assert.equal(continued.form.submitCount, 1);
assert.deepEqual(continued.alerts, []);

const cancelled = createRegistrationFlow();
cancelled.submit();
await flush();
assert.equal(cancelled.dialog.open, true);
cancelled.cancel.click();
await flush();
assert.equal(cancelled.form.submitCount, 0);
assert.equal(cancelled.submitButton.disabled, false);
assert.deepEqual(cancelled.alerts, []);

console.log('Pet duplicate alert dialog tests passed.');
