// On a computer (mouse / trackpad), time fields become plain 24-hour text fields: type 0815 or 08:15, no AM/PM picker.
// Phones and tablets keep the native time picker.
(function () {
  if (!window.matchMedia || !window.matchMedia('(pointer: fine)').matches) return;
  function fix(t) {
    if (t.dataset.t24) return;
    t.dataset.t24 = '1';
    t.type = 'text';
    t.inputMode = 'numeric';
    t.placeholder = 'JJ:MM';
    t.maxLength = 5;
    t.pattern = '([01][0-9]|2[0-3]):[0-5][0-9]';
    t.title = 'Jam 24 jam, mis. 08:15 atau 17:30';
    t.style.width = '7em';
    t.addEventListener('blur', function () {   // 815 / 0815 / 8.15 / 8:5 -> 08:15
      var d = t.value.replace(/[^0-9]/g, '');
      if (!d) return;
      if (d.length <= 2) d = d + '00';
      if (d.length === 3) d = '0' + d;
      var h = +d.slice(0, 2), m = +d.slice(2, 4);
      if (h < 24 && m < 60) t.value = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    });
  }
  document.querySelectorAll('input[type=time]').forEach(fix);
})();
