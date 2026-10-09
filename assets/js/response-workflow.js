(function () {
  'use strict';

  function esc(value) {
    return String(value || '').replace(/[&<>"']/g, function (character) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character];
    });
  }

  function field(label, name, type, required) {
    var id = 'responseField' + name;
    var input = type === 'textarea'
      ? '<textarea id="' + id + '" name="' + name + '" rows="3"' + (required ? ' required' : '') + '></textarea>'
      : '<input id="' + id + '" name="' + name + '" type="' + type + '"' + (required ? ' required' : '') + '>';
    return '<label class="response-workflow-field" for="' + id + '">' + esc(label) + (required ? ' <span class="response-workflow-required" aria-hidden="true">*</span>' : '') + input + '</label>';
  }

  function selectField(label, name, required, options) {
    var id = 'responseField' + name;
    return '<label class="response-workflow-field" for="' + id + '">' + esc(label) + (required ? ' <span class="response-workflow-required" aria-hidden="true">*</span>' : '') +
      '<select id="' + id + '" name="' + name + '"' + (required ? ' required' : '') + '><option value="">Choose disposition</option>' +
      options.map(function (option) { return '<option value="' + esc(option[0]) + '">' + esc(option[1]) + '</option>'; }).join('') +
      '</select></label>';
  }

  function isHighRisk(reportId) {
    var list = window.RPT_ITEMS || window.PENDING_ITEMS || window.REPORTS_DATA || [];
    var report = list.find(function (item) {
      return Number(item.id || item.report_id) === Number(reportId);
    });
    if (!report) return false;
    var text = [
      report.type, report.typeLabel, report.animalCount, report.animalBehavior,
      report.desc, report.description, report.urgencyRaw, report.urgency
    ].join(' ').toLowerCase();
    var count = String(report.animalCount || '').trim();
    return /rabies|bit(e|ten)|aggress|sever|malubha|critical/.test(text)
      || /^(?:high|critical)$/.test(String(report.urgencyRaw || report.urgency || '').toLowerCase())
      || /^(?:6\+|[6-9]\d*|\d{2,})$/.test(count);
  }

  function dispatchOfficerField() {
    var officers = Array.isArray(window.DCVS_RESPONSE_OFFICERS) ? window.DCVS_RESPONSE_OFFICERS : [];
    var options = officers.map(function (officer) {
      return '<option value="' + Number(officer.id) + '">' + esc(officer.name) + '</option>';
    }).join('');
    return '<label class="response-workflow-field" for="responseFielddispatch_officer_id">Assign DCVS officer <span class="response-workflow-required" aria-hidden="true">*</span>' +
      '<select id="responseFielddispatch_officer_id" name="dispatch_officer_id" required><option value="">Choose an active officer</option>' + options + '</select></label>';
  }

  function showAction(reportId, action, options) {
    options = options || {};
    var modal = document.getElementById('responseWorkflowModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'responseWorkflowModal';
      modal.className = 'modal';
      modal.style.zIndex = '1400';
      modal.innerHTML = '<div class="sd-dialog-content response-workflow-dialog" role="alertdialog" aria-modal="true" aria-labelledby="responseWorkflowTitle" aria-describedby="responseWorkflowDescription">' +
        '<div class="response-workflow-header"><h2 class="sd-dialog-title" id="responseWorkflowTitle"></h2><p class="sd-dialog-description" id="responseWorkflowDescription"></p></div>' +
        '<form id="responseWorkflowForm"><div id="responseWorkflowFields"></div>' +
        '<input type="hidden" name="report_id"><input type="hidden" name="action"><input type="hidden" name="csrf_token">' +
        '<div class="sd-dialog-footer" id="responseWorkflowFooter"><button type="button" class="sd-btn sd-btn-outline" id="responseWorkflowCancel">Cancel</button><button type="submit" class="sd-btn sd-btn-default" id="responseWorkflowSubmit">Continue</button></div></form></div>';
      document.body.appendChild(modal);
      document.getElementById('responseWorkflowCancel').addEventListener('click', function () { modal.classList.remove('active'); });
      modal.addEventListener('click', function (event) {
        if (event.target === modal) modal.classList.remove('active');
      });
    }

    var config = {
      staff_decide: {
        title: 'Dispatch DCVS Team',
        description: 'Assign an active DCVS officer to respond to this report.',
        submit: 'Dispatch DCVS Team',
        fields: dispatchOfficerField() + field('Dispatch notes', 'reason', 'textarea', false)
      },
      authorize_high_risk_override: {
        title: 'Override High-Risk Restriction',
        description: 'Record why this high-risk case can safely be handled by the barangay. This reason is added to case history.',
        submit: 'Record Override',
        fields: field('Required override reason', 'reason', 'textarea', true)
      },
      return_for_validation: {
        title: 'Return to Barangay',
        description: 'Explain what the barangay must clarify or correct before resubmitting the validation.',
        submit: 'Return to Barangay',
        fields: field('Reason', 'reason', 'textarea', true)
      },
      reject_report: {
        title: 'Reject Report',
        description: 'Explain why this validated report is being rejected. The resident and barangay will be notified.',
        submit: 'Reject Report',
        fields: field('Reason', 'reason', 'textarea', true)
      },
      update_progress: {
        title: 'Update Barangay Response Progress',
        description: 'Record what has been done and the next planned step. The next progress target is reset after this update.',
        submit: 'Save Progress Update',
        fields: field('Progress update', 'reason', 'textarea', true),
        alertDialog: true
      },
      start_response: {
        title: 'Start Barangay Response',
        description: 'Confirm that the barangay team is heading to the incident. This starts the response progress target.',
        submit: 'Start Response',
        fields: '',
        alertDialog: true
      },
      resolve_barangay: {
        title: 'Resolve Incident',
        description: 'Document the resolution and the animal disposition. The incident will be marked resolved; any live-animal turnover is tracked separately.',
        submit: 'Resolve Incident',
        fields: field('Resolution details', 'resolution_details', 'textarea', true) +
          selectField('Animal disposition', 'animal_disposition', true, [
            ['rescued', 'Rescued / secured'],
            ['reunited_with_owner', 'Reunited with owner'],
            ['transferred_to_shelter', 'Transferred to shelter'],
            ['under_veterinary_care', 'Under veterinary care'],
            ['disposed_per_local_protocol', 'Disposed under local protocol (dead animal)'],
            ['other', 'Other (explain in details)']
          ]) +
          '<label class="response-workflow-check response-workflow-check-description" data-turnover-option><input type="checkbox" name="turnover_required" value="1"> Create a separate DCVS turnover task for this live animal.</label>' +
          '<p class="response-workflow-help" data-dead-disposition-note hidden>Dead-animal cases require disposition details and do not create live-animal turnover tasks.</p>',
        alertDialog: true
      },
      mark_secured: {
        title: 'Mark Animal Secured',
        description: 'Add a photo, holding location, and current condition before recording barangay custody.',
        submit: 'Save Secured Animal',
        fields: field('Holding location', 'holding_location', 'text', true)
          + field('Animal condition', 'animal_condition', 'textarea', true)
          + field('Photo evidence (JPG or PNG)', 'secured_photo', 'file', true),
        alertDialog: true
      },
      unable_to_secure: { title: 'Escalate to DCVS', description: 'Explain why the barangay cannot safely secure the animal. DCVS will be notified.', submit: 'Escalate', fields: field('Reason', 'reason', 'textarea', true), alertDialog: true },
      unable_to_locate: { title: 'Unable to Locate Animal', description: 'Record what was checked so DCVS can decide the next action.', submit: 'Record', fields: field('Field findings', 'reason', 'textarea', true), alertDialog: true },
      request_pickup: { title: 'Request DCVS Pickup', description: 'Notify DCVS that the animal is secured and awaiting turnover.', submit: 'Request Pickup', fields: '' },
      switch_to_dispatch: { title: 'Assign DCVS Pickup', description: 'Keep the animal in barangay custody while requesting DCVS pickup. Explain the dispatch requirement.', submit: 'Request DCVS Pickup', fields: field('Reason', 'reason', 'textarea', true) },
      revoke_approval: { title: 'Switch to DCVS Dispatch', description: 'Revoke the barangay response approval and explain the reason.', submit: 'Switch to Dispatch', fields: field('Reason', 'reason', 'textarea', true) },
      confirm_turnover: { title: 'Confirm Turnover Received', description: 'Confirm that DCVS has physically received the animal from the barangay.', submit: 'Confirm Receipt', fields: '' },
      begin_processing: { title: 'Begin DCVS Processing', description: 'Record that DCVS has started processing this case.', submit: 'Begin Processing', fields: '' },
      return_to_barangay: { title: 'Return to Barangay', description: 'Explain why the case is being returned and confirm the barangay accepts custody.', submit: 'Return Case', fields: field('Reason', 'reason', 'textarea', true) + '<label class="response-workflow-check"><input type="checkbox" name="custody_confirmed" value="1" required> The barangay accepts custody.</label>' },
      resume_barangay_response: { title: 'Resume Barangay Response', description: 'Confirm the barangay has accepted custody of the case again.', submit: 'Resume Response', fields: '<label class="response-workflow-check"><input type="checkbox" name="custody_confirmed" value="1" required> The barangay has accepted custody again.</label>' },
      close_not_actionable: { title: 'Close Case', description: 'Close this case as not actionable, document why, and enter the resolution date and time.', submit: 'Close Case', fields: field('Reason', 'reason', 'textarea', true) + field('Date closed', 'resolved_date', 'date', true) + field('Time closed', 'resolved_time', 'time', true) },
      reopen_unable_to_locate: { title: 'Dispatch to Unable-to-Locate Case', description: 'Explain why DCVS is reopening response on this case.', submit: 'Dispatch', fields: field('Reason', 'reason', 'textarea', true) },
      dispatch_escalation: { title: 'Dispatch Escalated Case', description: 'Confirm DCVS will take over the escalated response.', submit: 'Dispatch DCVS Team', fields: field('Reason', 'reason', 'textarea', true) }
    }[action];
    if (!config) return;

    var form = modal.querySelector('form');
    var dialog = modal.querySelector('.response-workflow-dialog');
    var header = modal.querySelector('.response-workflow-header');
    var title = modal.querySelector('#responseWorkflowTitle');
    var description = modal.querySelector('#responseWorkflowDescription');
    var footer = modal.querySelector('#responseWorkflowFooter');
    dialog.classList.toggle('is-alert', !!config.alertDialog);
    header.classList.toggle('sd-alert-dialog-header', !!config.alertDialog);
    title.classList.toggle('sd-alert-dialog-title', !!config.alertDialog);
    title.classList.toggle('sd-dialog-title', !config.alertDialog);
    description.classList.toggle('sd-alert-dialog-description', !!config.alertDialog);
    description.classList.toggle('sd-dialog-description', !config.alertDialog);
    footer.classList.toggle('sd-alert-dialog-footer', !!config.alertDialog);
    footer.classList.toggle('sd-dialog-footer', !config.alertDialog);
    form.reset();
    title.textContent = config.title;
    description.textContent = config.description;
    modal.querySelector('#responseWorkflowFields').innerHTML = config.fields;
    modal.querySelector('[name="report_id"]').value = String(reportId);
    modal.querySelector('[name="action"]').value = action;
    modal.querySelector('[name="csrf_token"]').value = window.DCVS_RESPONSE_CSRF || '';
    var mode = modal.querySelector('[name="response_mode"]');
    if (mode) mode.remove();
    if (action === 'staff_decide') {
      mode = document.createElement('input');
      mode.type = 'hidden';
      mode.name = 'response_mode';
      mode.value = options.response_mode;
      modal.querySelector('form').appendChild(mode);
    }
    if (action === 'mark_secured') {
      form.querySelector('[name="secured_photo"]').accept = 'image/jpeg,image/png';
    }
    if (action === 'resolve_barangay') {
      var reports = window.RPT_ITEMS || window.PENDING_ITEMS || window.REPORTS_DATA || [];
      var report = reports.find(function (item) { return Number(item.id || item.report_id) === Number(reportId); });
      if (report && String(report.type || report.typeKey || '').toLowerCase() === 'dead') {
        var turnoverOption = form.querySelector('[data-turnover-option]');
        var deadNote = form.querySelector('[data-dead-disposition-note]');
        if (turnoverOption) turnoverOption.hidden = true;
        if (deadNote) deadNote.hidden = false;
      }
    }
    modal.querySelector('#responseWorkflowSubmit').textContent = config.submit;
    form.onsubmit = function (event) {
      event.preventDefault();
      var button = modal.querySelector('#responseWorkflowSubmit');
      button.disabled = true;
      button.textContent = 'Saving...';
      var endpoint = window.DCVS_RESPONSE_ROLE === 'barangay'
        ? 'response-workflow.php?sess=DCVS_BARANGAY_SESSION'
        : 'response-workflow.php';
      fetch(endpoint, { method: 'POST', body: new FormData(form) })
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok || !data.success) throw new Error(data.message || 'The case action failed.');
            return data;
          });
        })
        .then(function () { window.location.reload(); })
        .catch(function (error) {
          console.error('Response workflow action failed:', error);
          if (window.portalToast) window.portalToast('Could not update case', error.message);
          else if (window.showToast) window.showToast(error.message);
          else alert(error.message);
        })
        .then(function () {
          button.disabled = false;
          button.textContent = config.submit;
        });
    };
    modal.classList.add('active');
  }

  window.responseWorkflowAction = showAction;
})();
