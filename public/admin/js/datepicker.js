/**
 * datepicker.js — LFS Admin Panel
 * Auto-initializes Flatpickr on all admin date and datetime-local inputs.
 * Submitted values stay Y-m-d / Y-m-dTH:i for Laravel compatibility.
 */
'use strict';

(function () {
  if (typeof flatpickr === 'undefined') {
    return;
  }

  /**
   * Build Flatpickr options for a single input.
   * @param {HTMLInputElement} el
   * @param {boolean} isDateTime
   * @returns {Object}
   */
  function optionsFor(el, isDateTime) {
    var opts = {
      allowInput: true,
      altInput: true,
      altInputClass: 'admin-input flatpickr-alt-input',
      disableMobile: true,
      animate: true,
      static: false,
      onReady: function (_selectedDates, _dateStr, instance) {
        if (instance.altInput) {
          instance.altInput.classList.add('admin-input', 'flatpickr-alt-input');
          if (el.required) {
            instance.altInput.setAttribute('required', '');
          }
          if (el.disabled) {
            instance.altInput.disabled = true;
          }
          if (el.id) {
            instance.altInput.setAttribute('id', el.id + '_display');
            // Keep label[for] usable: point at visible control
            var label = document.querySelector('label[for="' + el.id + '"]');
            if (label) {
              label.setAttribute('for', el.id + '_display');
            }
          }
        }
      },
    };

    if (isDateTime) {
      opts.enableTime = true;
      opts.time_24hr = true;
      opts.dateFormat = 'Y-m-d\\TH:i';
      opts.altFormat = 'M j, Y H:i';
    } else {
      opts.dateFormat = 'Y-m-d';
      opts.altFormat = 'M j, Y';
    }

    if (el.min) {
      opts.minDate = el.min;
    }
    if (el.max) {
      opts.maxDate = el.max;
    }

    return opts;
  }

  /**
   * Initialize Flatpickr on matching inputs that are not yet initialized.
   * @param {ParentNode} [root]
   */
  function initDatepickers(root) {
    var scope = root || document;
    var inputs = scope.querySelectorAll(
      'input[type="date"], input[type="datetime-local"], input[data-lfs-datepicker]:not(.flatpickr-input)'
    );

    inputs.forEach(function (el) {
      if (el._flatpickr || el.classList.contains('flatpickr-input')) {
        return;
      }

      var isDateTime =
        el.type === 'datetime-local' ||
        el.getAttribute('data-lfs-datepicker') === 'datetime';

      el.setAttribute('data-lfs-datepicker', isDateTime ? 'datetime' : 'date');
      // Avoid native browser picker UI flashing before Flatpickr takes over
      el.type = 'text';

      flatpickr(el, optionsFor(el, isDateTime));
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initDatepickers();
    });
  } else {
    initDatepickers();
  }

  // Expose for pages that inject fields dynamically
  window.lfsInitDatepickers = initDatepickers;
})();
