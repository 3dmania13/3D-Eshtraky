
(function () {
  'use strict';
  const toggle = document.querySelector('.dashboard-sidebar-toggle');
  const sidebar = document.getElementById('sidebar');
  if (!toggle || !sidebar) return;
  toggle.addEventListener('click', function () {
    const collapsed = document.body.classList.toggle('sidebar-collapsed');
    toggle.setAttribute('aria-expanded', String(!collapsed));
    const label = collapsed ? '??? ??????? ????????' : '?? ??????? ????????';
    toggle.setAttribute('aria-label', label);
    toggle.title = label;
    // Existing chart listeners recalculate their width after the layout changes.
    window.dispatchEvent(new Event('resize'));
  });
})();
