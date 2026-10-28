<?php
$evidenceRole = ($_SESSION['role'] ?? '') === 'barangay' ? 'barangay' : 'staff';
$evidenceSession = $evidenceRole === 'barangay' ? 'DCVS_BARANGAY_SESSION' : 'DCVS_STAFF_SESSION';
?>
<style>
  #reportEvidenceModal{position:fixed;inset:0;z-index:1400;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(24,24,27,.55);}
  #reportEvidenceModal.open{display:flex;}
  #reportEvidenceModal .evidence-dialog{width:min(760px,100%);max-height:calc(100vh - 32px);overflow:auto;background:#fff;border:1px solid #e4e4e7;border-radius:10px;box-shadow:0 20px 48px rgba(0,0,0,.2);font-family:Inter,'Segoe UI',sans-serif;}
  #reportEvidenceModal .evidence-dialog-head{display:flex;justify-content:space-between;align-items:flex-start;padding:20px 22px;border-bottom:1px solid #e4e4e7;}
  #reportEvidenceModal .evidence-dialog-head h2{margin:0;color:#18181b;font-size:18px;font-weight:600;}
  #reportEvidenceModal .evidence-dialog-head p{margin:6px 0 0;color:#71717a;font-size:13px;line-height:1.5;}
  #reportEvidenceModal .evidence-dialog-body{display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:20px;padding:20px 22px;}
  #reportEvidenceModal .evidence-field{display:block;margin:0 0 14px;color:#27272a;font-size:13px;font-weight:600;}
  #reportEvidenceModal .evidence-field input[type=file]{display:block;width:100%;margin-top:7px;font:inherit;font-weight:400;}
  #reportEvidenceModal .evidence-caption-list{display:grid;gap:8px;margin-top:10px;}
  #reportEvidenceModal .evidence-caption-row{padding:10px;border:1px solid #e4e4e7;border-radius:7px;background:#fafafa;}
  #reportEvidenceModal .evidence-caption-row span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#71717a;font-size:12px;margin-bottom:6px;}
  #reportEvidenceModal .evidence-caption-row input{width:100%;height:36px;padding:0 10px;border:1px solid #d4d4d8;border-radius:6px;font:400 13px Inter,'Segoe UI',sans-serif;}
  #reportEvidenceModal .evidence-preview{padding:12px;border:1px solid #e4e4e7;border-radius:8px;background:#fafafa;}
  #reportEvidenceModal .evidence-preview-title{margin:0 0 10px;color:#18181b;font-size:13px;font-weight:600;}
  #reportEvidenceModal .evidence-preview-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;}
  #reportEvidenceModal .evidence-preview-list img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:5px;background:#e4e4e7;}
  #reportEvidenceModal .evidence-preview-empty{grid-column:1/-1;margin:0;color:#71717a;font-size:12px;line-height:1.5;}
  #reportEvidenceModal .evidence-dialog-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 22px;border-top:1px solid #e4e4e7;}
  @media(max-width:600px){#reportEvidenceModal .evidence-dialog-body{grid-template-columns:1fr;}#reportEvidenceModal .evidence-preview{order:-1;}}
</style>
<div id="reportEvidenceModal" aria-hidden="true" onclick="if(event.target===this)closeReportEvidenceModal()">
  <form class="evidence-dialog" id="reportEvidenceForm" enctype="multipart/form-data" role="dialog" aria-modal="true" aria-labelledby="reportEvidenceTitle">
    <div class="evidence-dialog-head">
      <div><h2 id="reportEvidenceTitle">Add photo evidence</h2><p id="reportEvidenceReportLabel">Choose up to three JPG or PNG photos and add a caption to each.</p></div>
      <button type="button" class="sd-btn sd-btn-outline" onclick="closeReportEvidenceModal()" aria-label="Close dialog">Close</button>
    </div>
    <div class="evidence-dialog-body">
      <div>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="report_id" id="reportEvidenceId" value="">
        <label class="evidence-field" for="reportEvidenceFiles">Photo files
          <input type="file" id="reportEvidenceFiles" name="evidence_photos[]" accept="image/jpeg,image/png" multiple required>
          <span style="display:block;margin-top:5px;color:#71717a;font-size:12px;font-weight:400;">Up to 3 photos, 25 MB each.</span>
        </label>
        <div class="evidence-caption-list" id="reportEvidenceCaptions"></div>
        <p style="margin:14px 0 0;color:#71717a;font-size:12px;line-height:1.5;">Photos added here will be visible to the resident in report updates and attachments.</p>
      </div>
      <aside class="evidence-preview" aria-label="Selected photo preview">
        <p class="evidence-preview-title">Preview</p>
        <div class="evidence-preview-list" id="reportEvidencePreview"><p class="evidence-preview-empty">Selected photos will appear here.</p></div>
      </aside>
    </div>
    <div class="evidence-dialog-foot">
      <button type="button" class="sd-btn sd-btn-outline" onclick="closeReportEvidenceModal()">Cancel</button>
      <button type="submit" class="sd-btn sd-btn-default" id="reportEvidenceSubmit">Add evidence</button>
    </div>
  </form>
</div>
<script>
(function() {
  var form = document.getElementById('reportEvidenceForm');
  var fileInput = document.getElementById('reportEvidenceFiles');
  var captions = document.getElementById('reportEvidenceCaptions');
  var preview = document.getElementById('reportEvidencePreview');
  var submit = document.getElementById('reportEvidenceSubmit');
  var session = <?php echo json_encode($evidenceSession); ?>;
  function clearPreviewUrls() {
    preview.querySelectorAll('img').forEach(function(image) {
      if (image.src.indexOf('blob:') === 0) URL.revokeObjectURL(image.src);
    });
  }
  fileInput.addEventListener('change', function() {
    clearPreviewUrls();
    var files = Array.prototype.slice.call(fileInput.files || []);
    if (files.length > 3) {
      fileInput.value = '';
      captions.innerHTML = '';
      preview.innerHTML = '<p class="evidence-preview-empty">Choose no more than 3 photos.</p>';
      return;
    }
    captions.innerHTML = files.map(function(file, index) {
      var label = file.name.replace(/[&<>"']/g, function(character) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]);
      });
      return '<label class="evidence-caption-row"><span>' + label + '</span><input name="captions[]" type="text" maxlength="180" required placeholder="Photo caption (e.g., Animal secured)"></label>';
    }).join('');
    preview.innerHTML = files.length ? '' : '<p class="evidence-preview-empty">Selected photos will appear here.</p>';
    files.forEach(function(file) {
      var image = document.createElement('img');
      image.alt = file.name;
      image.src = URL.createObjectURL(file);
      preview.appendChild(image);
    });
  });
  window.openReportEvidenceModal = function(reportId) {
    form.reset();
    clearPreviewUrls();
    document.getElementById('reportEvidenceId').value = String(reportId);
    document.getElementById('reportEvidenceReportLabel').textContent = 'Report #' + reportId + ' · choose up to three JPG or PNG photos and add a caption to each.';
    captions.innerHTML = '';
    preview.innerHTML = '<p class="evidence-preview-empty">Selected photos will appear here.</p>';
    document.getElementById('reportEvidenceModal').classList.add('open');
    document.getElementById('reportEvidenceModal').setAttribute('aria-hidden', 'false');
  };
  window.closeReportEvidenceModal = function() {
    clearPreviewUrls();
    document.getElementById('reportEvidenceModal').classList.remove('open');
    document.getElementById('reportEvidenceModal').setAttribute('aria-hidden', 'true');
  };
  form.addEventListener('submit', function(event) {
    event.preventDefault();
    if (!form.report_id.value || !fileInput.files.length) return;
    submit.disabled = true;
    submit.textContent = 'Saving...';
    var data = new FormData(form);
    fetch('api/shared/report-evidence.php?sess=' + encodeURIComponent(session), {method:'POST',body:data})
      .then(function(response) {
        return response.json().then(function(payload) {
          if (!response.ok || !payload.success) throw new Error(payload.message || 'Evidence could not be saved.');
          return payload;
        });
      })
      .then(function() {
        window.closeReportEvidenceModal();
        if (window.showToast) window.showToast('Photo evidence added.');
        else if (window.portalToast) window.portalToast('Photo evidence added', 'The case update is saved.');
        else alert('Photo evidence added.');
        window.location.reload();
      })
      .catch(function(error) {
        if (window.showToast) window.showToast(error.message);
        else if (window.portalToast) window.portalToast('Could not add evidence', error.message);
        else alert(error.message);
      })
      .finally(function() {
        submit.disabled = false;
        submit.textContent = 'Add evidence';
      });
  });
})();
</script>
