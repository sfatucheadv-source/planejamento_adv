document.querySelectorAll('[data-print]').forEach(function (a) {
  a.addEventListener('click', function (ev) { ev.preventDefault(); window.print(); });
});
