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

  function findReport(reportId) {
    var lists = [window.REPORTS_DATA, window.RPT_ITEMS, window.PENDING_ITEMS, window.ACTIVE_ITEMS, window.RESOLVED_ITEMS];
    for (var i = 0; i < lists.length; i++) {
      if (!Array.isArray(lists[i])) continue;
      var report = lists[i].find(function (item) {
        return Number(item.report_id || item.id) === Number(reportId);
      });
      if (report) return report;
    }
    return null;
  }

  window.responseWorkflowNeedsHandover = function (report) {
    if (!report) return false;
    var status = String(report._db_status || report.statusRaw || '').toLowerCase();
    var disposition = String(report.animalDisposition || '').toLowerCase();
    var reportType = String(report.type || report.typeKey || '').toLowerCase();
    var liveAnimal = reportType.indexOf('dead') === -1
      && ['reunited_with_owner', 'transferred_to_shelter', 'disposed_per_local_protocol'].indexOf(disposition) === -1;
    return report.turnoverStatus === 'pending' || report.turnoverStatus === 'receipt_confirmed'
      || (status === 'resolved' && liveAnimal);
  };

  window.responseWorkflowHandoverHtml = function (report, options) {
    if (!window.responseWorkflowNeedsHandover(report)) return '';
    options = options || {};
    var pending = !report.turnoverStatus || report.turnoverStatus === 'pending';
    var turnoverStatus = report.turnoverStatus || 'pending';
    var planned = !!report.turnoverMethod && !!report.turnoverPlannedAt;
    var method = report.turnoverMethod === 'dcvs_pickup' ? 'DCVS pickup requested' : 'Barangay delivers to DCVS office';
    var status = turnoverStatus === 'receipt_confirmed' ? 'Turned over' : (planned ? 'Handover scheduled' : 'Plan needed');
    var statusClass = turnoverStatus === 'receipt_confirmed' ? ' is-received' : (planned ? ' is-planned' : ' is-needed');
    var rows = '<div class="handover-plan-row"><span>Due by</span><strong>' + esc(report.turnoverDueAtDisplay || report.turnoverDueAt || '—') + '</strong></div>';
    if (planned) {
      rows += '<div class="handover-plan-row"><span>Method</span><strong>' + esc(method) + '</strong></div>' +
        '<div class="handover-plan-row"><span>' + (report.turnoverMethod === 'dcvs_pickup' ? 'Preferred pickup' : 'Planned handover') +
        '</span><strong>' + esc(report.turnoverPlannedAt) + '</strong></div>' +
        '<div class="handover-plan-row"><span>' + (report.turnoverMethod === 'dcvs_pickup' ? 'Pickup location' : 'Handled by') +
        '</span><strong>' + esc(report.turnoverMethod === 'dcvs_pickup' ? (report.turnoverPickupLocation || report.holdingLocation || '—') : (report.turnoverHandlerName || '—')) + '</strong></div>' +
        (report.turnoverMethod === 'dcvs_pickup' ? '<div class="handover-plan-row"><span>Contact person</span><strong>' + esc(report.turnoverHandlerName || '—') + '</strong></div>' : '') +
        '<div class="handover-plan-row"><span>Contact</span><strong>' + esc(report.turnoverContactNumber || '—') + '</strong></div>' +
        (report.turnoverSafeTransport ? '<div class="handover-plan-row"><span>Transport</span><strong>Animal will be safely secured</strong></div>' : '');
    }
    if (pending || turnoverStatus === 'receipt_confirmed') {
      rows += '<div class="handover-plan-row"><span>Current custodian</span><strong>' +
        esc(turnoverStatus === 'receipt_confirmed' ? 'DCVS' : (report.brgy ? 'Brgy. ' + report.brgy : 'Barangay team')) + '</strong></div>';
    }
    if (report.holdingLocation) {
      rows += '<div class="handover-plan-row"><span>' + (pending ? 'Current holding location' : 'Barangay holding location') + '</span><strong>' + esc(report.holdingLocation) + '</strong></div>';
    }
    if (turnoverStatus === 'receipt_confirmed' && !options.excludeReceipt) {
      rows += '<div class="handover-plan-row"><span>Receipt confirmed</span><strong>' + esc(report.turnoverReceivedAtDisplay || report.turnoverReceivedAt || '') +
        (report.turnoverReceiver ? ' by ' + esc(report.turnoverReceiver) : '') + '</strong></div>';
    }
    var note = pending
      ? (planned ? 'Keep the animal safe until DCVS confirms receipt. The barangay does not mark it delivered.' : 'Set a handover plan and keep the animal safe until DCVS confirms receipt.')
      : 'DCVS confirmed receipt. The incident remains resolved.';
    return '<section class="handover-plan-card' + (planned ? ' is-planned' : '') + (turnoverStatus === 'receipt_confirmed' ? ' is-received' : '') + (pending && report.turnoverDueAt && new Date(String(report.turnoverDueAt).replace(' ', 'T')).getTime() < Date.now() ? ' is-overdue' : '') + '">' +
      '<div class="handover-plan-heading"><div><strong>Animal turnover to DCVS</strong><span>' + (planned ? esc(method) : 'Set your handover plan so DCVS can prepare') + '</span></div>' +
      '<span class="handover-plan-status' + statusClass + '">' + esc(status) + '</span></div>' +
      '<div class="handover-plan-body">' + rows + '</div><p class="handover-plan-note">' + esc(note) + '</p></section>';
  };

  window.responseWorkflowBarangayValidationHtml = function (report) {
    var labels = {
      site_visit_pending: 'Site visit pending',
      confirmed_on_site: 'Confirmed on site',
      urgent_response: 'Needs urgent response',
      animal_already_gone: 'Animal already gone',
      wrong_or_unclear_location: 'Wrong / unclear location'
    };
    var evidence = Array.isArray(report.brgyValidationEvidence) ? report.brgyValidationEvidence : [];
    var hasFieldStatus = !!report.brgyValidationStatus;
    var validationTime = report.validated || 'Awaiting barangay validation';
    var workflowLabels = {
      awaiting_dcvs_decision: 'Forwarded to DCVS',
      dcvs_dispatch: 'DCVS dispatch',
      barangay_response_approved: 'Response approved',
      barangay_responding: 'Barangay responding',
      animal_secured: 'Animal secured',
      awaiting_turnover: 'Awaiting turnover',
      turned_over: 'Turned over',
      dcvs_processing: 'DCVS processing',
      unable_to_locate: 'Unable to locate',
      escalated_to_dcvs: 'Escalated to DCVS',
      returned_to_barangay: 'Returned to barangay',
      closed_not_actionable: 'Closed — not actionable',
      resolved: 'Resolved'
    };
    var currentActionStatus = workflowLabels[report.responseState] ||
      ({ confirmed: 'In progress', scheduled: 'In progress', resolved: 'Resolved', rejected: 'Closed' }[report.statusRaw] || '');
    var statusLabel = currentActionStatus || labels[report.brgyValidationStatus] ||
      (report.statusRaw === 'validated' ? 'Validation details unavailable' : 'Awaiting barangay validation');
    var responseHistory = Array.isArray(report.responseHistory) ? report.responseHistory : [];
    var latestWorkflowEntry = null;
    for (var historyIndex = responseHistory.length - 1; historyIndex >= 0; historyIndex--) {
      if (report.responseState && responseHistory[historyIndex].toState === report.responseState) {
        latestWorkflowEntry = responseHistory[historyIndex];
        break;
      }
    }
    var statusStyle = 'color:#045e1f;background:#eef6f0;border:1px solid #b8d1c0;';
    if (currentActionStatus === 'Resolved') {
      statusStyle = 'color:#ffffff;background:#0E8131;border:1px solid #0E8131;';
    } else if (currentActionStatus === 'Closed' || currentActionStatus === 'Unable to locate' || currentActionStatus === 'Closed — not actionable') {
      statusStyle = 'color:#991b1b;background:#fef2f2;border:1px solid #fecaca;';
    } else if (currentActionStatus) {
      statusStyle = 'color:#175CD3;background:#eff6ff;border:1px solid #bfdbfe;';
    } else if (report.brgyValidationStatus === 'site_visit_pending' || report.brgyValidationStatus === 'animal_already_gone') {
      statusStyle = 'color:#52525b;background:#f4f4f5;border:1px solid #e4e4e7;';
    } else if (report.brgyValidationStatus === 'urgent_response') {
      statusStyle = 'color:#991b1b;background:#fef2f2;border:1px solid #fecaca;';
    } else if (report.brgyValidationStatus === 'wrong_or_unclear_location') {
      statusStyle = 'color:#92400e;background:#fffbeb;border:1px solid #fde68a;';
    }
    var updateRows = [];
    if (currentActionStatus) updateRows.push({ label: 'Current status', value: currentActionStatus });
    if (report.responseState === 'resolved' || report.statusRaw === 'resolved') {
      if (report.resolutionDetails || report.caseNotes) {
        updateRows.push({ label: 'Resolution', value: report.resolutionDetails || report.caseNotes });
      }
      if (report.animalDisposition) {
        updateRows.push({ label: 'Animal disposition', value: report.animalDisposition });
      }
    }
    if (latestWorkflowEntry && latestWorkflowEntry.reason) {
      updateRows.push({
        label: report.responseState === 'resolved' || report.statusRaw === 'resolved' ? 'Additional update' : 'Action details',
        value: latestWorkflowEntry.reason
      });
    }
    var updateMeta = latestWorkflowEntry
      ? [latestWorkflowEntry.actor, latestWorkflowEntry.createdAt].filter(Boolean).join(' · ')
      : ([report.resolutionActor, report.responseResolvedAt].filter(Boolean).join(' · '));
    var updateHtml = updateRows.map(function (item) {
      return '<div><span class="pen-validation-message-field-label">' + esc(item.label) + ':</span> <span class="pen-validation-message-field-value">' + esc(item.value) + '</span></div>';
    }).join('');
    var evidenceHtml = evidence.length
      ? '<div><div class="pen-validation-label">Barangay evidence (' + evidence.length + ')</div>' +
        '<div class="pen-validation-evidence-grid">' + evidence.map(function (item) {
          var url = esc(item.url || '');
          var caption = esc(item.caption || (item.type === 'video' ? 'Barangay field video evidence' : 'Barangay field photo evidence'));
          var media = item.type === 'video'
            ? '<video src="' + url + '" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video>'
            : '<img src="' + url + '" alt="' + caption + '" loading="lazy">';
          return '<a class="pen-validation-evidence" href="' + url + '" target="_blank" rel="noopener noreferrer" aria-label="Open ' + caption + '">' + media + '</a>';
        }).join('') + '</div></div>'
      : '';
    var proposalBanner = window.DCVS_BARANGAY_RESPONSE_ENABLED
      && report.barangayProposed
      && report.responseState === 'awaiting_dcvs_decision'
      ? '<div class="pen-response-proposal" role="status"><span aria-hidden="true">&#10003;</span><div><strong>Barangay is requesting to respond.</strong><span>Brgy. ' +
        esc(report.brgy || 'Barangay') +
        ' checked "I can respond to this case". This report was forwarded to DCVS for review and assignment.</span></div></div>'
      : '';
    return '<section class="pen-validation-card" aria-label="Barangay Validation">' +
      '<div class="pen-validation-heading">' +
      '<svg class="pen-validation-heading-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m9 12 2 2 4-4"/></svg>' +
      '<div class="pen-validation-heading-copy"><div class="pen-validation-title">Barangay Validation</div>' +
      '<div class="pen-validation-meta">' + esc(report.brgy ? 'Brgy. ' + report.brgy + ' · ' : '') + esc(validationTime) + '</div></div>' +
      '<span class="pen-validation-status" style="' + statusStyle + '">' + esc(statusLabel) + '</span>' +
      '</div><div class="pen-validation-content">' +
      (updateHtml
        ? '<div><div class="pen-validation-label">Latest case update' + (updateMeta ? ' · ' + esc(updateMeta) : '') + '</div><div class="pen-validation-message pen-validation-message-fields">' + updateHtml + '</div></div>'
        : '') +
      ((report.brgyValidationNotes || report.brgyNotes)
        ? '<div><div class="pen-validation-label">' + (updateHtml ? 'Barangay validation message' : 'Message from barangay') + '</div><div class="pen-validation-message">' + esc(report.brgyValidationNotes || report.brgyNotes) + '</div></div>'
        : (!hasFieldStatus
          ? '<div class="pen-validation-message">' + (report.statusRaw === 'validated' ? 'Field validation details are not available for this report.' : 'This report is awaiting barangay field validation.') + '</div>'
          : '')) +
      proposalBanner + evidenceHtml +
      '</div></section>';
  };

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
      var closeModal = function () {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        modal.style.display = 'none';
      };
      document.getElementById('responseWorkflowCancel').addEventListener('click', closeModal);
      modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('active')) closeModal();
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
      record_site_visit: {
        title: 'Record Site Visit',
        description: 'Record what the team found. Only confirming the incident at the reported location moves it to Active Cases and the geo map.',
        submit: 'Save Site Visit',
        fields: '<label class="response-workflow-field" for="responseFieldfield_status">Site finding <span class="response-workflow-required" aria-hidden="true">*</span>' +
          '<select id="responseFieldfield_status" name="field_status" required><option value="">Choose a site finding</option>' +
          '<option value="confirmed_on_site">Incident confirmed at reported location</option>' +
          '<option value="urgent_response">Needs urgent response</option>' +
          '<option value="animal_already_gone">Animal already gone</option>' +
          '<option value="wrong_or_unclear_location">Wrong or unclear location</option></select></label>' +
          field('Site visit notes', 'reason', 'textarea', true),
        alertDialog: true
      },
      resolve_barangay: {
        title: 'Resolve Incident',
        description: 'Document the resolution and animal disposition. After resolution, set the handover plan for the live animal.',
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
          '<p class="response-workflow-help" data-dead-disposition-note hidden>Dead-animal cases require disposition details and do not create live-animal turnover tasks. Resolved live-animal cases will show a separate handover plan in the report.</p>',
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
      set_handover_plan: {
        title: 'Set Handover Plan',
        description: 'Tell DCVS how and when this animal will be handed over. DCVS confirms receipt when it arrives.',
        submit: 'Set Plan',
        fields: '<fieldset class="handover-method-options"><legend>Handover method <span class="response-workflow-required" aria-hidden="true">*</span></legend>' +
          '<label><input type="radio" name="handover_method" value="barangay_delivery" required checked><span><strong>We will deliver it to the DCVS office</strong><small>Your barangay brings the animal. Best when nearby.</small></span></label>' +
          '<label><input type="radio" name="handover_method" value="dcvs_pickup" required><span><strong>Request DCVS pickup</strong><small>For far barangays or when you have no transport. DCVS will confirm the schedule.</small></span></label></fieldset>' +
          '<div class="handover-plan-preview" id="handoverPlanPreview"></div>' +
          '<div class="handover-date-time">' + field('Date', 'handover_date', 'date', true) + field('Time', 'handover_time', 'time', true) + '</div>' +
          '<div class="handover-contact-fields"><label class="response-workflow-field"><span data-handler-label>Who will deliver</span> <span class="response-workflow-required" aria-hidden="true">*</span><input name="handler_name" type="text" maxlength="150" required></label>' +
          '<label class="response-workflow-field" for="responseFieldcontact_number">Contact number <span class="response-workflow-required" aria-hidden="true">*</span><input id="responseFieldcontact_number" name="contact_number" type="tel" maxlength="40" placeholder="09XX XXX XXXX" required></label></div>' +
          '<label class="response-workflow-field handover-pickup-location" hidden>Pickup location <span class="response-workflow-required" aria-hidden="true">*</span><input name="pickup_location" type="text" maxlength="500"></label>' +
          '<label class="response-workflow-check"><input type="checkbox" name="safe_transport" value="1"> The animal will be transported in a carrier or safely secured.</label>' +
          '<p class="response-workflow-help"><span aria-hidden="true">ⓘ</span> This handover plan will be recorded in the report updates. DCVS confirms receipt; the barangay does not mark it delivered.</p>',
        handoverDialog: true
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
      dispatch_escalation: { title: 'Dispatch Escalated Case', description: 'Confirm DCVS will take over the escalated response.', submit: 'Dispatch DCVS Team', fields: '' }
    }[action];
    if (!config) return;

    var form = modal.querySelector('form');
    var dialog = modal.querySelector('.response-workflow-dialog');
    var header = modal.querySelector('.response-workflow-header');
    var title = modal.querySelector('#responseWorkflowTitle');
    var description = modal.querySelector('#responseWorkflowDescription');
    var footer = modal.querySelector('#responseWorkflowFooter');
    var submitLabel = config.submit;
    modal.classList.toggle('is-handover', !!config.handoverDialog);
    form.oninput = null;
    form.onchange = null;
    dialog.classList.toggle('is-alert', !!config.alertDialog);
    dialog.classList.toggle('is-handover', !!config.handoverDialog);
    dialog.setAttribute('role', config.alertDialog ? 'alertdialog' : 'dialog');
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
        var deadNote = form.querySelector('[data-dead-disposition-note]');
        if (deadNote) deadNote.hidden = false;
      }
    }
    if (action === 'set_handover_plan') {
      var handoverReport = findReport(reportId) || {};
      var method = handoverReport.turnoverMethod || 'barangay_delivery';
      var fields = modal.querySelector('#responseWorkflowFields');
      var dateInput = fields.querySelector('[name="handover_date"]');
      var timeInput = fields.querySelector('[name="handover_time"]');
      var plannedAt = String(handoverReport.turnoverPlannedAtRaw || '');
      var defaultPlanAt = plannedAt || String(handoverReport.turnoverDueAt || '');
      if (dateInput && defaultPlanAt) dateInput.value = defaultPlanAt.slice(0, 10);
      if (timeInput) timeInput.value = defaultPlanAt ? defaultPlanAt.slice(11, 16) : '10:00';
      fields.querySelectorAll('[name="handover_method"]').forEach(function (input) {
        input.checked = input.value === method;
      });
      var handlerInput = fields.querySelector('[name="handler_name"]');
      var contactInput = fields.querySelector('[name="contact_number"]');
      var locationInput = fields.querySelector('[name="pickup_location"]');
      var safeInput = fields.querySelector('[name="safe_transport"]');
      var preview = fields.querySelector('#handoverPlanPreview');
      if (preview) {
        var animalCount = handoverReport.animalCount || handoverReport.animal_count || '—';
        var animalType = handoverReport.animalType || handoverReport.animal_type || '';
        var holdingLocation = handoverReport.holdingLocation || '—';
        var dueAt = handoverReport.turnoverDueAtDisplay || handoverReport.turnoverDueAt || '—';
        preview.innerHTML = '<div class="handover-preview-item"><span>Animals</span><strong>' + esc(animalCount + (animalType ? ' ' + animalType : '')) + '</strong></div>' +
          '<div class="handover-preview-item"><span>Holding</span><strong>' + esc(holdingLocation) + '</strong></div>' +
          '<div class="handover-preview-item handover-preview-due"><span>Due by</span><strong>' + esc(dueAt) + '</strong></div>';
      }
      if (dateInput) {
        var today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
        dateInput.min = today;
        if (!dateInput.value) dateInput.value = today;
      }
      if (handlerInput) handlerInput.value = handoverReport.turnoverHandlerName || handoverReport.brgy || '';
      if (contactInput) contactInput.value = handoverReport.turnoverContactNumber || '';
      if (locationInput) locationInput.value = method === 'dcvs_pickup'
        ? (handoverReport.turnoverPickupLocation || handoverReport.holdingLocation || '')
        : '';
      if (safeInput) safeInput.checked = !!handoverReport.turnoverSafeTransport;
      var updateMethodFields = function () {
        var selectedMethod = fields.querySelector('[name="handover_method"]:checked');
        var pickup = !!selectedMethod && selectedMethod.value === 'dcvs_pickup';
        var locationField = fields.querySelector('.handover-pickup-location');
        var handlerLabel = fields.querySelector('[data-handler-label]');
        if (locationField) {
          locationField.hidden = !pickup;
          locationField.querySelector('input').required = pickup;
        }
        if (locationInput) {
          if (pickup && !locationInput.value) locationInput.value = handoverReport.holdingLocation || '';
          if (!pickup) locationInput.value = '';
        }
        if (handlerLabel) handlerLabel.textContent = pickup ? 'Barangay contact person' : 'Who will deliver';
      };
      fields.querySelectorAll('[name="handover_method"]').forEach(function (input) {
        input.addEventListener('change', updateMethodFields);
      });
      updateMethodFields();
      var updateSubmitState = function () {
        var submitButton = modal.querySelector('#responseWorkflowSubmit');
        if (submitButton) submitButton.disabled = !form.checkValidity();
      };
      form.oninput = updateSubmitState;
      form.onchange = updateSubmitState;
      updateSubmitState();
      if (handoverReport.turnoverMethod) {
        submitLabel = 'Save Changes';
        modal.querySelector('#responseWorkflowTitle').textContent = 'Edit Handover Plan';
      }
    }
    modal.querySelector('#responseWorkflowSubmit').textContent = submitLabel;
    form.onsubmit = function (event) {
      event.preventDefault();
      var button = modal.querySelector('#responseWorkflowSubmit');
      button.disabled = true;
      button.textContent = 'Saving...';
      var appBaseUrl = String(window.DCVS_APP_BASE_URL || '').replace(/\/+$/, '');
      var endpoint = window.DCVS_RESPONSE_ROLE === 'barangay'
        ? appBaseUrl + '/response-workflow.php?sess=DCVS_BARANGAY_SESSION'
        : appBaseUrl + '/response-workflow.php';
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
          button.textContent = submitLabel;
        });
    };
    modal.setAttribute('aria-hidden', 'false');
    modal.style.display = 'flex';
    modal.classList.add('active');
    var firstField = modal.querySelector('#responseWorkflowFields input:not([type="hidden"]):not([type="radio"]), #responseWorkflowFields select');
    if (firstField) firstField.focus({ preventScroll: true });
  }

  window.responseWorkflowAction = showAction;
})();
