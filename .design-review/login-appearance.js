
(function () {
 'use strict';
 const body = document.body;
 const theme = document.querySelector('.login-theme-toggle');
 const icon = theme.querySelector('i');
 function applyTheme(dark) {
  body.classList.toggle('login-dark', dark);
  theme.setAttribute('aria-pressed', String(dark));
  theme.setAttribute('aria-label', dark ? '????? ?????? ??????' : '????? ?????? ??????');
  theme.title = theme.getAttribute('aria-label');
  icon.className = dark ? 'bi bi-moon' : 'bi bi-sun';
 }
 applyTheme(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
 theme.addEventListener('click', function () { applyTheme(!body.classList.contains('login-dark')); });
 const passwordToggle = document.querySelector('.login-password-toggle');
 const password = document.getElementById('operator_pass');
 passwordToggle.addEventListener('click', function () {
  const show = password.type === 'password';
  password.type = show ? 'text' : 'password';
  passwordToggle.setAttribute('aria-pressed', String(show));
  passwordToggle.setAttribute('aria-label', show ? '????? ???? ??????' : '????? ???? ??????');
  passwordToggle.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
 });
})();
