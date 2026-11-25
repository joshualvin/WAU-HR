// Printing a report: shrink the page so the widest table fits one landscape sheet (the print dialog then shows one page wide).
(function () {
  var t;
  window.addEventListener('beforeprint', function () {
    t = document.querySelector('table.rep');
    if (!t) return;
    var w = t.scrollWidth, room = 1060;   // A4 landscape at 96 dpi minus 8 mm margins
    document.body.style.zoom = w > room ? (room / w).toFixed(3) : '';
  });
  window.addEventListener('afterprint', function () { document.body.style.zoom = ''; });
})();
