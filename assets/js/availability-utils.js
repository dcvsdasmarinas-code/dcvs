(function(window) {
  'use strict';

  function generateTimeOptions() {
    var options = [];
    for (var i = 0; i < 48; i++) {
      var hour = Math.floor(i / 2);
      var minute = (i % 2) * 30;
      var period = hour >= 12 ? 'PM' : 'AM';
      var hour12 = hour % 12;
      if (hour12 === 0) hour12 = 12;
      var label = String(hour12).padStart(2, '0') + ':' + String(minute).padStart(2, '0') + ' ' + period;
      var value = String(hour).padStart(2, '0') + ':' + String(minute).padStart(2, '0');
      options.push({ value: value, label: label });
    }
    return options;
  }

  function timeValToLabel(value, timeOptions) {
    for (var i = 0; i < timeOptions.length; i++) {
      if (timeOptions[i].value === value) return timeOptions[i].label;
    }
    return value;
  }

  function format12To24(time) {
    var parts = time.split(' ');
    var hm = parts[0].split(':');
    var hour = parseInt(hm[0], 10);
    var minute = hm[1];
    if (parts[1] === 'PM' && hour !== 12) hour += 12;
    if (parts[1] === 'AM' && hour === 12) hour = 0;
    return String(hour).padStart(2, '0') + ':' + minute;
  }

  function svgIcon(name) {
    var icons = {
      plus: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
      minus: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>',
      trash: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
      copy: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
      x: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
    };
    return icons[name] || '';
  }

  window.DCVSAvailabilityUtils = {
    generateTimeOptions: generateTimeOptions,
    timeValToLabel: timeValToLabel,
    format12To24: format12To24,
    svgIcon: svgIcon
  };
})(window);