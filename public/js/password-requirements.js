/* ============================================================
   LFS — Lusaka Fitness Squad | password-requirements.js
   Real-time password strength checklist + confirm-match hint,
   plus the show/hide-password eye toggle (global — was previously
   duplicated inline per page and missing on account settings).
   No-ops on any page without these elements.
   ============================================================ */

'use strict';

(function () {
  const RULES = [
    { key: 'length', test: (v) => v.length >= 8, label: 'At least 8 characters' },
    { key: 'upper', test: (v) => /[A-Z]/.test(v), label: 'One uppercase letter' },
    { key: 'lower', test: (v) => /[a-z]/.test(v), label: 'One lowercase letter' },
    { key: 'number', test: (v) => /[0-9]/.test(v), label: 'One number' },
    { key: 'special', test: (v) => /[^A-Za-z0-9]/.test(v), label: 'One special character' },
  ];

  /**
   * Wires a password input to its requirements checklist — toggles
   * `.is-met` per rule as the user types, matching the server-side
   * Password::defaults() rule (min:8, mixed case, numbers, symbols).
   */
  function setupRequirements(passwordInput, list) {
    const items = RULES
      .map((rule) => ({ rule, el: list.querySelector('[data-rule="' + rule.key + '"]') }))
      .filter((item) => item.el);

    function update() {
      const value = passwordInput.value;
      items.forEach(({ rule, el }) => {
        const met = rule.test(value);
        el.classList.toggle('is-met', met);
      });
    }

    passwordInput.addEventListener('input', update);
    passwordInput.addEventListener('focus', () => { list.hidden = false; });
    passwordInput.addEventListener('blur', () => {
      if (passwordInput.value === '') list.hidden = true;
    });

    update();
  }

  /** Wires a confirm-password input to a live "passwords match" hint. */
  function setupMatch(passwordInput, confirmInput, hint) {
    function update() {
      const confirmValue = confirmInput.value;
      if (confirmValue === '') {
        hint.hidden = true;
        return;
      }
      const matches = confirmValue === passwordInput.value;
      hint.hidden = false;
      hint.textContent = matches ? 'Passwords match' : 'Passwords do not match';
      hint.classList.toggle('is-match', matches);
      hint.classList.toggle('is-mismatch', !matches);
    }

    confirmInput.addEventListener('input', update);
    passwordInput.addEventListener('input', update);
    update();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-password-requirements-for]').forEach(function (list) {
      const input = document.getElementById(list.getAttribute('data-password-requirements-for'));
      if (input) setupRequirements(input, list);
    });

    document.querySelectorAll('[data-password-match-for]').forEach(function (hint) {
      const confirmInput = document.getElementById(hint.getAttribute('data-password-match-for'));
      const form = hint.closest('form');
      const passwordInput = form ? form.querySelector('#password') : document.getElementById('password');
      if (confirmInput && passwordInput) setupMatch(passwordInput, confirmInput, hint);
    });

    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const input = document.getElementById(btn.getAttribute('data-toggle-password'));
        if (!input) return;
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        const icon = btn.querySelector('i');
        if (icon) {
          icon.classList.toggle('fa-eye', isPassword);
          icon.classList.toggle('fa-eye-slash', !isPassword);
        }
        btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
      });
    });
  });
})();
