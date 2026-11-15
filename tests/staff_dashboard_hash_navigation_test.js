import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(__dirname, '..', 'pages', 'staff', 'dashboard-staff.php'), 'utf8');
const sidebar = fs.readFileSync(path.join(__dirname, '..', 'includes', 'staff-sidebar.php'), 'utf8');
const dashboardScript = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'dashboard.js'), 'utf8');
const start = source.indexOf("const hashTab = window.location.hash.replace('#', '');");
const end = source.indexOf('    // Initialize Charts', start);

assert.notEqual(start, -1, 'dashboard hash restoration should exist');
assert.notEqual(end, -1, 'dashboard initialization should follow hash restoration');
const restoreNavigation = source.slice(start, end);
const switchCalls = [];

vm.runInNewContext(
  'const freshLogin = false;\nconst sections = [];\n' + restoreNavigation,
  {
    window: { location: { hash: '#reports-all' } },
    document: { getElementById: id => ({ id }) },
    localStorage: {
      getItem: key => key === 'staffActiveTab' ? 'appointments' : null,
      removeItem() {}
    },
    switchSection: id => switchCalls.push(id)
  }
);

assert.deepEqual(switchCalls, ['reports-all'], 'URL hash should navigate to All Reports even if the saved tab is Appointments');
assert.doesNotMatch(restoreNavigation, /targetLink\.click\(\)/, 'hash restoration should not depend on a legacy sidebar selector');
assert.match(sidebar, /function sbHref\(\$href\)/, 'sidebar URLs should be resolved against the application root');
assert.equal((sidebar.match(/htmlspecialchars\(sbHref\(\$item\['href'\] \?\? '#'\)/g) || []).length, 3,
  'all sidebar item anchors should use root-resolved application URLs');

const geoPageUrl = new URL('http://localhost/dcvs/dashboard-staff-geo.php');
const allReportsHref = new URL('/dcvs/dashboard-staff.php#reports-all', geoPageUrl);
assert.equal(allReportsHref.pathname, '/dcvs/dashboard-staff.php');
assert.equal(allReportsHref.hash, '#reports-all');
assert.match(sidebar, /if \(section === 'reports-all'\) \{\s*e\.preventDefault\(\);\s*navigateToStaffSection\(item, section\);/,
  'All Reports should use explicit navigation instead of the current appointment page state');

const staffNavigation = sidebar.match(/function navigateToStaffSection\(item, sectionId\) \{[\s\S]*?\n  \}/);
assert.ok(staffNavigation, 'dashboard section navigation helper should exist');
let assignedUrl = '';
vm.runInNewContext(staffNavigation[0] + '\nnavigateToStaffSection(item, "reports-all");', {
  URL,
  item: { href: '/dcvs/dashboard-staff.php#reports-all' },
  window: {
    location: { href: 'http://localhost/dcvs/dashboard-staff.php?appt_status=all#appointments', assign: url => { assignedUrl = url; } }
  }
});
assert.equal(assignedUrl, 'http://localhost/dcvs/dashboard-staff.php#reports-all');

const switchSectionSource = dashboardScript.match(/function switchSection\(sectionId\) \{[\s\S]*?\n\}/);
assert.ok(switchSectionSource, 'dashboard section switcher should exist');

function runSectionSwitch(initialUrl, sectionId) {
  let currentUrl = new URL(initialUrl);
  const location = {
    get href() { return currentUrl.href; },
    get pathname() { return currentUrl.pathname; },
    get search() { return currentUrl.search; },
    get hash() { return currentUrl.hash; },
    set hash(value) { currentUrl.hash = value; }
  };
  const context = {
    URL,
    window: { location, scrollTo() {} },
    document: {
      getElementById: () => ({ style: {} }),
      querySelectorAll: () => [],
      querySelector: () => null,
      body: { classList: { toggle() {} } }
    },
    history: {
      replaceState: (_state, _title, url) => { currentUrl = new URL(url, currentUrl); }
    },
    localStorage: { setItem() {} }
  };
  vm.runInNewContext(switchSectionSource[0] + '\nswitchSection(' + JSON.stringify(sectionId) + ');', context);
  return currentUrl;
}

const reportsUrl = runSectionSwitch(
  'http://localhost/dcvs/dashboard-staff.php?appt_search=&appt_from=&appt_to=&appt_status=all&appt_service=all#adoption',
  'reports-all'
);
assert.equal(reportsUrl.search, '', 'leaving Appointments should clear its stale query filters');
assert.equal(reportsUrl.hash, '#reports-all');

const appointmentsUrl = runSectionSwitch(
  'http://localhost/dcvs/dashboard-staff.php?appt_status=pending#appointments',
  'appointments'
);
assert.equal(appointmentsUrl.search, '?appt_status=pending', 'switching within Appointments should preserve its filters');
assert.equal(appointmentsUrl.hash, '#appointments');

console.log('Staff dashboard hash navigation checks passed.');
