<?php
/**
 * Shared shadcn-style toast notification for the resident portal pages.
 * Usage (success flows):
 *   <?php $portalToasts = [...]; include 'includes/portal-toast.php'; ?>
 * The included file renders the styles/markup and replays $portalToasts
 * as toasts once the DOM is ready. On pages without $portalToasts, call
 *   portalToast('Message')   or   portalToast('Title', 'Description')
 * from JS directly.
 */
$portalToastQueue = isset($portalToasts) ? array_values(array_filter((array)$portalToasts)) : [];
?>
<style>
  .portal-toast-wrap{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;align-items:flex-end;pointer-events:none}
  .portal-toast{pointer-events:auto;min-width:320px;max-width:400px;background:#FFFFFF;border:1px solid #E4E4E7;border-radius:8px;box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);padding:14px 16px;display:flex;gap:12px;align-items:flex-start;font-family:'Inter',sans-serif;opacity:0;transform:translateX(24px);transition:opacity .3s cubic-bezier(.16,1,.3,1),transform .3s cubic-bezier(.16,1,.3,1)}
  .portal-toast.show{opacity:1;transform:translateX(0)}
  .portal-toast.hide{opacity:0;transform:translateX(24px)}
  .portal-toast-ic{width:20px;height:20px;border-radius:50%;background:#0E8131;color:#FFFFFF;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:11px;font-weight:700;line-height:1}
  .portal-toast-body{flex:1;min-width:0}
  .portal-toast-title{font-size:14px;font-weight:600;color:#18181B;line-height:1.4;word-wrap:break-word}
  .portal-toast-desc{font-size:13px;font-weight:400;color:#71717A;line-height:1.45;margin-top:2px;word-wrap:break-word}
  .portal-toast-close{border:0;background:transparent;color:#71717A;cursor:pointer;font-size:15px;line-height:1;padding:2px 4px;border-radius:4px;flex-shrink:0}
  .portal-toast-close:hover{background:#F4F4F5;color:#18181B}
  @media(max-width:640px){.portal-toast-wrap{left:16px;right:16px;align-items:stretch}.portal-toast{min-width:0;max-width:none}}
</style>

<div class="portal-toast-wrap" id="portalToastWrap"></div>
<?php if ($portalToastQueue): ?>
<script>window.__portalSuccessToasts = <?php echo json_encode($portalToastQueue, JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
<?php endif; ?>
<script>
(function () {
  function makeEl(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  }

  function portalToast(title, desc) {
    var wrap = document.getElementById('portalToastWrap');
    if (!wrap) return null;

    var toast = makeEl('div', 'portal-toast');
    toast.appendChild(makeEl('span', 'portal-toast-ic', '\u2713'));

    var body = makeEl('div', 'portal-toast-body');
    body.appendChild(makeEl('div', 'portal-toast-title', title || 'Success!'));
    if (desc) body.appendChild(makeEl('div', 'portal-toast-desc', desc));
    toast.appendChild(body);

    var closeBtn = makeEl('button', 'portal-toast-close', '\u00d7');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Close');
    toast.appendChild(closeBtn);

    wrap.appendChild(toast);
    requestAnimationFrame(function () { toast.classList.add('show'); });

    var timer = null;
    function dismiss() {
      clearTimeout(timer);
      if (!toast.parentNode) return;
      toast.classList.remove('show');
      toast.classList.add('hide');
      setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 320);
    }
    closeBtn.addEventListener('click', dismiss);
    timer = setTimeout(dismiss, 4500);
    return toast;
  }

  window.portalToast = portalToast;

  document.addEventListener('DOMContentLoaded', function () {
    var queue = window.__portalSuccessToasts || [];
    window.__portalSuccessToasts = [];
    queue.forEach(function (msg, i) {
      setTimeout(function () { portalToast(msg); }, i * 450);
    });
  });
})();
</script>
