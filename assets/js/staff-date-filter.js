(function () {
  var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var weekdays = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

  function localDateString(date) {
    return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
  }

  function parseLocalDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
    var parts = value.split('-').map(Number);
    var date = new Date(parts[0], parts[1] - 1, parts[2]);
    return date.getFullYear() === parts[0] && date.getMonth() === parts[1] - 1 && date.getDate() === parts[2] ? date : null;
  }

  function addCalendar(filter) {
    var prefix = filter.dataset.dateFilter;
    var button = filter.querySelector('.staff-date-filter-button');
    var from = document.getElementById(prefix + 'DateFrom');
    var to = document.getElementById(prefix + 'DateTo');
    if (!button || !from || !to) return;

    var popup = document.createElement('div');
    popup.className = 'staff-date-calendar';
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-label', 'Filter by date');
    popup.innerHTML =
      '<div class="staff-date-calendar-header">' +
        '<button type="button" class="staff-date-calendar-nav" data-calendar-prev aria-label="Previous month"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>' +
        '<div class="staff-date-calendar-month-year"><select data-calendar-month aria-label="Month"></select><select data-calendar-year aria-label="Year"></select></div>' +
        '<button type="button" class="staff-date-calendar-nav" data-calendar-next aria-label="Next month"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>' +
      '</div>' +
      '<div class="staff-date-calendar-weekdays">' + weekdays.map(function (day) { return '<span>' + day + '</span>'; }).join('') + '</div>' +
      '<div class="staff-date-calendar-days" data-calendar-days></div>' +
      '<button type="button" class="staff-date-calendar-clear" data-calendar-clear hidden>Clear date</button>';
    filter.appendChild(popup);

    var monthSelect = popup.querySelector('[data-calendar-month]');
    var yearSelect = popup.querySelector('[data-calendar-year]');
    var daysWrap = popup.querySelector('[data-calendar-days]');
    var clearButton = popup.querySelector('[data-calendar-clear]');
    var initialDate = parseLocalDate(from.value) || parseLocalDate(to.value);
    var cursor = initialDate ? new Date(initialDate.getFullYear(), initialDate.getMonth(), 1) : new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    months.forEach(function (month, index) {
      var option = document.createElement('option');
      option.value = String(index);
      option.textContent = month;
      monthSelect.appendChild(option);
    });
    var currentYear = new Date().getFullYear();
    for (var year = currentYear - 5; year <= currentYear + 5; year++) {
      var yearOption = document.createElement('option');
      yearOption.value = String(year);
      yearOption.textContent = String(year);
      yearSelect.appendChild(yearOption);
    }

    function render() {
      var yearValue = Number(yearSelect.value);
      var monthValue = Number(monthSelect.value);
      cursor = new Date(yearValue, monthValue, 1);
      var firstWeekday = cursor.getDay();
      var daysInMonth = new Date(yearValue, monthValue + 1, 0).getDate();
      var daysInPreviousMonth = new Date(yearValue, monthValue, 0).getDate();
      var today = localDateString(new Date());
      var selectedDate = from.value || to.value;
      var html = '';
      var cellCount = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;

      for (var cell = 0; cell < cellCount; cell++) {
        var dayNumber;
        var dayDate;
        var outside = false;
        if (cell < firstWeekday) {
          dayNumber = daysInPreviousMonth - firstWeekday + cell + 1;
          dayDate = new Date(yearValue, monthValue - 1, dayNumber);
          outside = true;
        } else if (cell >= firstWeekday + daysInMonth) {
          dayNumber = cell - firstWeekday - daysInMonth + 1;
          dayDate = new Date(yearValue, monthValue + 1, dayNumber);
          outside = true;
        } else {
          dayNumber = cell - firstWeekday + 1;
          dayDate = new Date(yearValue, monthValue, dayNumber);
        }
        var dateValue = localDateString(dayDate);
        var classes = 'staff-date-calendar-day';
        if (outside) classes += ' is-outside';
        if (dateValue === today) classes += ' is-today';
        if (dateValue === selectedDate) classes += ' is-selected';
        html += '<button type="button" class="' + classes + '" data-calendar-date="' + dateValue + '">' + dayNumber + '</button>';
      }
      daysWrap.innerHTML = html;
      clearButton.hidden = !selectedDate;
    }

    function setCursor(date) {
      cursor = new Date(date.getFullYear(), date.getMonth(), 1);
      monthSelect.value = String(cursor.getMonth());
      var cursorYear = String(cursor.getFullYear());
      var yearOption = Array.prototype.find.call(yearSelect.options, function (option) {
        return option.value === cursorYear;
      });
      if (!yearOption) {
        yearOption = document.createElement('option');
        yearOption.value = cursorYear;
        yearOption.textContent = cursorYear;
        var nextYear = Array.prototype.find.call(yearSelect.options, function (option) {
          return Number(option.value) > cursor.getFullYear();
        });
        yearSelect.insertBefore(yearOption, nextYear || null);
      }
      yearSelect.value = cursorYear;
      render();
    }

    function close() {
      popup.classList.remove('open');
      button.setAttribute('aria-expanded', 'false');
    }

    function setDate(value) {
      from.value = value;
      to.value = value;
      button.classList.toggle('has-date', !!value);
      render();
      var setter = window[prefix + 'SetDate'];
      if (typeof setter === 'function') setter();
      close();
    }

    setCursor(cursor);
    button.classList.toggle('has-date', !!(from.value || to.value));
    button.addEventListener('click', function (event) {
      event.stopPropagation();
      var opening = !popup.classList.contains('open');
      document.querySelectorAll('.staff-date-calendar.open').forEach(function (openPopup) {
        openPopup.classList.remove('open');
        var openButton = openPopup.parentElement.querySelector('.staff-date-filter-button');
        if (openButton) openButton.setAttribute('aria-expanded', 'false');
      });
      if (!opening) return;
      setCursor(parseLocalDate(from.value) || parseLocalDate(to.value) || new Date());
      popup.classList.add('open');
      button.setAttribute('aria-expanded', 'true');
    });
    monthSelect.addEventListener('change', render);
    yearSelect.addEventListener('change', render);
    popup.querySelector('[data-calendar-prev]').addEventListener('click', function () {
      setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1));
    });
    popup.querySelector('[data-calendar-next]').addEventListener('click', function () {
      setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1));
    });
    daysWrap.addEventListener('click', function (event) {
      var dayButton = event.target.closest('[data-calendar-date]');
      if (dayButton) setDate(dayButton.dataset.calendarDate);
    });
    clearButton.addEventListener('click', function () { setDate(''); });
    popup.addEventListener('click', function (event) { event.stopPropagation(); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-date-filter]').forEach(addCalendar);
    document.addEventListener('click', function () {
      document.querySelectorAll('.staff-date-calendar.open').forEach(function (popup) {
        popup.classList.remove('open');
        var button = popup.parentElement.querySelector('.staff-date-filter-button');
        if (button) button.setAttribute('aria-expanded', 'false');
      });
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        document.querySelectorAll('.staff-date-calendar.open').forEach(function (popup) {
          popup.classList.remove('open');
          var button = popup.parentElement.querySelector('.staff-date-filter-button');
          if (button) button.setAttribute('aria-expanded', 'false');
        });
      }
    });
  });
})();
