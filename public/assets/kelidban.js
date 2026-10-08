(function () {
  'use strict';

  document.querySelectorAll('[data-toggle-pass]').forEach(function (btn) {
    var input = document.getElementById(btn.getAttribute('data-toggle-pass'));
    if (!input) return;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.classList.toggle('on', show);
      btn.setAttribute('aria-label', show ? 'پنهان کردن رمز' : 'نمایش رمز');
      input.focus();
    });
  });

  var caps = document.querySelector('[data-caps]');
  if (caps) {
    document.addEventListener('keyup', function (e) {
      if (e.getModifierState) caps.hidden = !e.getModifierState('CapsLock');
    });
  }

  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var msg = form.getAttribute('data-confirm');
      if (msg && !window.confirm(msg)) {
        e.preventDefault();
        return;
      }
      var btn = form.querySelector('button[type="submit"]');
      if (btn) setTimeout(function () { btn.disabled = true; }, 0);
    });
  });

  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-copy'));
      if (!input) return;
      input.select();
      var done = function () { btn.textContent = 'کپی شد'; };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
      } else {
        document.execCommand('copy');
        done();
      }
    });
  });

  var idle = parseInt(document.body.getAttribute('data-idle') || '0', 10);
  if (idle > 0) {
    var timer;
    var arm = function () {
      clearTimeout(timer);
      timer = setTimeout(function () { window.location.reload(); }, (idle + 5) * 1000);
    };
    ['click', 'keydown'].forEach(function (ev) { document.addEventListener(ev, arm, { passive: true }); });
    arm();
  }
})();
