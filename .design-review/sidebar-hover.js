(function () {
 'use strict';
 const body = document.body;
 const sidebar = document.getElementById('sidebar');
 const toggle = document.querySelector('.dashboard-sidebar-toggle');
 const overlay = document.querySelector('.sidebar-overlay');
 const desktop = window.matchMedia('(min-width:861px)');
 if (!sidebar || !toggle || !overlay) return;
 function setOpen(open) {
  if (desktop.matches) {
   body.classList.toggle('sidebar-collapsed', !open);
   sidebar.classList.remove('open');
   overlay.classList.remove('show');
   body.style.overflow = '';
  } else {
   body.classList.remove('sidebar-collapsed');
   sidebar.classList.toggle('open', open);
   overlay.classList.toggle('show', open);
   body.style.overflow = open ? 'hidden' : '';
  }
  toggle.setAttribute('aria-expanded', String(open));
  toggle.setAttribute('aria-label', open ? 'طي القائمة الجانبية' : 'فتح القائمة الجانبية');
  toggle.title = toggle.getAttribute('aria-label');
  window.dispatchEvent(new Event('resize'));
 }
 function isOpen() { return desktop.matches ? !body.classList.contains('sidebar-collapsed') : sidebar.classList.contains('open'); }
 toggle.addEventListener('click', function () { setOpen(!isOpen()); });
 sidebar.addEventListener('mouseenter', function () { if (desktop.matches) setOpen(true); });
 sidebar.addEventListener('mouseleave', function (event) {
  if (desktop.matches && !toggle.contains(event.relatedTarget)) setOpen(false);
 });
 toggle.addEventListener('mouseenter', function () { if (desktop.matches) setOpen(true); });
 toggle.addEventListener('mouseleave', function (event) {
  if (desktop.matches && !sidebar.contains(event.relatedTarget)) setOpen(false);
 });
 document.addEventListener('click', function (event) {
  if (event.target.closest('.menu-toggle')) {
   event.preventDefault(); event.stopImmediatePropagation(); setOpen(!isOpen());
  } else if (event.target.closest('.sidebar-close,.sidebar-overlay')) {
   event.preventDefault(); event.stopImmediatePropagation(); setOpen(false);
  }
 }, true);
 document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape' && isOpen()) { setOpen(false); toggle.focus(); }
 });
 sidebar.querySelectorAll('.nav-item').forEach(function (item) {
  const label = item.querySelector('span');
  if (label) { item.title = label.textContent.trim(); item.setAttribute('aria-label', label.textContent.trim()); }
 });
 desktop.addEventListener('change', function () { setOpen(false); });
 setOpen(false);
})();
