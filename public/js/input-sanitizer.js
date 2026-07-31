/* LFS shared client-side input sanitizer */
(function () {
  'use strict';

  const MAX = {
    firstName: 60,
    lastName: 60,
    email: 254,
    phone: 30,
    satellite: 60,
    message: 5000,
  };

  const trimTo = (value, max) => (value || '').trim().slice(0, max);

  const sanitizer = {
    text(value, max = 255) {
      return trimTo(String(value || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' '), max);
    },
    email(value) {
      return trimTo(String(value || '').replace(/[^\w.!#$%&'*+/=?^`{|}~@-]/g, ''), MAX.email);
    },
    phone(value) {
      return trimTo(String(value || '').replace(/[^0-9+\-\s()]/g, '').replace(/\s+/g, ' '), MAX.phone);
    },
    textarea(value, max = MAX.message) {
      return trimTo(
        String(value || '')
          .replace(/<[^>]*>/g, '')
          .replace(/\r\n?/g, '\n')
          .replace(/[ \t]+$/gm, ''),
        max
      );
    },
    sanitizeContactForm(form) {
      if (!form) return;
      const byName = (name) => form.querySelector(`[name="${name}"]`);
      const firstName = byName('firstName');
      const lastName = byName('lastName');
      const email = byName('email');
      const phone = byName('phone');
      const satellite = byName('satellite');
      const message = byName('message');

      if (firstName) firstName.value = sanitizer.text(firstName.value, MAX.firstName);
      if (lastName) lastName.value = sanitizer.text(lastName.value, MAX.lastName);
      if (email) email.value = sanitizer.email(email.value);
      if (phone) phone.value = sanitizer.phone(phone.value);
      if (satellite) satellite.value = sanitizer.text(satellite.value, MAX.satellite);
      if (message) message.value = sanitizer.textarea(message.value, MAX.message);
    },
    bindContactForm() {
      const form = document.querySelector('section#contact form[action="/contact"]');
      if (!form) return;

      // Visible character counter for the contact message textarea.
      const message = form.querySelector('textarea[name="message"]');
      const counter = document.getElementById('contactMessageCharCount');
      const max = message ? (Number(message.getAttribute('maxlength')) || MAX.message) : MAX.message;

      const updateCounter = function () {
        if (!message || !counter) return;
        counter.textContent = `${message.value.length} / ${max}`;
      };

      if (message) {
        message.addEventListener('input', updateCounter);
        requestAnimationFrame(updateCounter);
      }

      form.addEventListener('submit', function () {
        sanitizer.sanitizeContactForm(form);
        updateCounter();
      });
    },
  };

  window.LFSInputSanitizer = sanitizer;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sanitizer.bindContactForm);
  } else {
    sanitizer.bindContactForm();
  }
})();

