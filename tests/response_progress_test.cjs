const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const window = {};
vm.runInNewContext(fs.readFileSync('assets/js/response-workflow.js', 'utf8'), {
  window,
  document: {},
  Date,
  Intl,
  isNaN
});

const pending = window.responseWorkflowProgressModel({ statusRaw: 'pending' });
assert.deepStrictEqual(Array.from(pending.steps), [
  'Submitted', 'Barangay Review', 'DCVS Review', 'In Progress', 'Resolved'
]);
assert.strictEqual(pending.currentStep, 0);

const overdueResponse = window.responseWorkflowProgressModel({
  statusRaw: 'validated',
  responseState: 'barangay_responding',
  isOverdue: true,
  overdueReason: 'Progress update',
  overdueDeadline: '2026-03-15 11:00:00'
});
assert.strictEqual(overdueResponse.currentStep, 1);
assert.strictEqual(overdueResponse.overdue, true);

const html = window.responseWorkflowProgressHtml({
  statusRaw: 'validated',
  responseState: 'barangay_responding',
  isOverdue: true,
  overdueReason: 'Progress <update>',
  overdueDeadline: '2026-03-15 11:00:00'
});
assert.match(html, /is-current is-overdue/);
assert.match(html, /role="alert"/);
assert.match(html, /Overdue: Progress &lt;update&gt;/);

const dcvsOverdueHtml = window.responseWorkflowProgressHtml({
  statusRaw: 'confirmed',
  responseMode: 'dcvs_dispatch',
  responseState: 'dcvs_dispatch',
  submitted: 'Oct 16, 2026 1:03 AM',
  incidentDate: 'Sep 18, 2026',
  isOverdue: true,
  overdueReason: 'not scheduled',
  overdueAction: 'Dispatch',
  overdueBadge: 'Dispatch overdue',
  overdueDeadline: '2026-03-15 19:32:00'
});
assert.match(dcvsOverdueHtml, /Accepted/);
assert.match(dcvsOverdueHtml, /Escalated/);
assert.match(dcvsOverdueHtml, /Dispatched/);
assert.match(dcvsOverdueHtml, /Secured/);
assert.match(dcvsOverdueHtml, /Resolved/);
assert.match(dcvsOverdueHtml, /This report was submitted on Oct 16, 2026 at 1:03 AM, 28 days after the incident on Sep 18, 2026\./);
assert.match(dcvsOverdueHtml, /Dispatch is overdue for this case\./);
assert.doesNotMatch(dcvsOverdueHtml, /Current response status: Overdue:/);
assert.match(dcvsOverdueHtml, /Dispatch overdue/);
assert.match(dcvsOverdueHtml, /No team has been dispatched yet/);
assert.match(dcvsOverdueHtml, /Mar 15, 7:32 PM/);
assert.doesNotMatch(dcvsOverdueHtml, /Overdue: not scheduled/);

const overdueUpdate = window.responseWorkflowOverdueUpdate({
  isOverdue: true,
  overdueAction: 'Dispatch',
  overdueBadge: 'Dispatch overdue',
  overdueDeadline: '2026-03-15 19:32:00'
});
assert.strictEqual(overdueUpdate.action, 'current_overdue');
assert.strictEqual(overdueUpdate.title, 'Dispatch overdue');
assert.strictEqual(overdueUpdate.actorRole, 'system');
assert.strictEqual(overdueUpdate.reason, 'No team has been dispatched yet. This was due Mar 15, 7:32 PM.');
assert.strictEqual(overdueUpdate.createdAtRaw, '2026-03-15 19:32:00');
assert.strictEqual(window.responseWorkflowOverdueUpdate({ isOverdue: false }), null);

const dcvsEscalated = window.responseWorkflowProgressModel({
  statusRaw: 'confirmed',
  responseMode: 'dcvs_dispatch',
  responseState: 'escalated_to_dcvs'
});
assert.strictEqual(dcvsEscalated.currentStep, 1);
const dcvsProcessing = window.responseWorkflowProgressModel({
  statusRaw: 'confirmed',
  responseMode: 'dcvs_dispatch',
  responseState: 'dcvs_processing'
});
assert.strictEqual(dcvsProcessing.currentStep, 3);

const scheduledDcvsOverdue = window.responseWorkflowProgressHtml({
  statusRaw: 'confirmed',
  responseMode: 'dcvs_dispatch',
  responseState: 'dcvs_dispatch',
  isOverdue: true,
  overdueReason: 'past scheduled date',
  overdueAction: 'Visit',
  overdueBadge: 'Visit overdue',
  overdueDeadline: '2026-03-15 11:00:00'
});
assert.match(scheduledDcvsOverdue, /The visit was scheduled for Mar 15 and is not marked done/);

const schedulingOverdue = window.responseWorkflowProgressHtml({
  statusRaw: 'confirmed',
  responseMode: 'barangay_response',
  responseState: 'barangay_response_approved',
  isOverdue: true,
  overdueReason: 'not scheduled',
  overdueAction: 'Scheduling',
  overdueBadge: 'Scheduling overdue',
  overdueDeadline: '2026-03-15 19:32:00'
});
assert.match(schedulingOverdue, /Scheduling overdue/);
assert.match(schedulingOverdue, /No visit date has been set\. This was due Mar 15, 7:32 PM\./);

const reviewOverdue = window.responseWorkflowProgressHtml({
  statusRaw: 'validated',
  responseState: 'barangay_response_approved',
  isOverdue: true,
  overdueAction: 'Review',
  overdueBadge: 'Review overdue',
  overdueDeadline: '2026-03-15 19:32:00'
});
assert.match(reviewOverdue, /Barangay review was due Mar 15, 7:32 PM\./);

const resolutionOverdue = window.responseWorkflowProgressHtml({
  statusRaw: 'validated',
  responseState: 'barangay_responding',
  isOverdue: true,
  overdueAction: 'Resolution',
  overdueBadge: 'Resolution overdue',
  overdueDeadline: '2026-03-15 19:32:00'
});
assert.match(resolutionOverdue, /The case is still open past its target date\./);

const resolvedHandover = window.responseWorkflowProgressModel({
  statusRaw: 'resolved',
  type: 'stray_animal',
  turnoverStatus: 'pending'
});
assert.strictEqual(resolvedHandover.steps[5], 'Turned over');
assert.strictEqual(resolvedHandover.currentStep, 5);

console.log('Report progress checks passed.');
