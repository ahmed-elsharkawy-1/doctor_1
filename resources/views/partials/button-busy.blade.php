{{--
    A spinner on a button that has been pressed, until the next screen arrives.

    Two different waits need covering and they are not the same thing:

    · A Livewire action — the page stays put while a request is in flight.
      Those use wire:loading, which Livewire handles itself; this file only
      supplies the spinner's looks.

    · A link or a form submit — the browser is fetching a whole new page and
      nothing on this one will ever update. Livewire cannot help, so the
      script below marks the pressed control busy and leaves it that way until
      the new page replaces it.

    The reason for either is the same: without it a slow connection looks like
    a button that did not work, and the patient presses it again. On «تأكيد
    الحجز» that is a second booking attempt; on «إرسال رمز التأكيد» it is a
    second SMS we pay for.
--}}
<style>
    .btn-spin {
        flex: 0 0 auto;
        width: 15px;
        height: 15px;
        border: 2px solid currentColor;
        /* One transparent side is what makes the rotation legible. Drawn in
           currentColor so it works on a filled button and an outlined one
           without either knowing about the other. */
        border-right-color: transparent;
        border-radius: 50%;
        animation: btn-spin .6s linear infinite;
    }

    @keyframes btn-spin { to { transform: rotate(360deg); } }

    /* A link or submit that has been pressed. Pointer events go last so a
       second press cannot land while the page is on its way. */
    .btn.is-busy {
        opacity: .75;
        cursor: progress;
        pointer-events: none;
    }

    @media (prefers-reduced-motion: reduce) {
        .btn-spin { animation-duration: 2.4s; }
    }
</style>

<script>
    (function () {
        var SPIN = 'btn-spin';

        function busy(el) {
            if (el.classList.contains('is-busy')) {
                return;
            }

            el.classList.add('is-busy');

            if (! el.querySelector('.' + SPIN)) {
                var spinner = document.createElement('span');
                spinner.className = SPIN;
                spinner.setAttribute('aria-hidden', 'true');
                el.appendChild(spinner);
            }
        }

        // Links that leave the page. Anything opening a new tab, or a download,
        // leaves this one visible and must not be left looking stuck.
        document.addEventListener('click', function (event) {
            var link = event.target.closest('a.btn');

            if (! link || link.target === '_blank' || link.hasAttribute('download')) {
                return;
            }

            if (link.getAttribute('href') && link.getAttribute('href').charAt(0) !== '#') {
                busy(link);
            }
        });

        document.addEventListener('submit', function (event) {
            var button = event.target.querySelector('button[type="submit"].btn, button.btn:not([type])');

            if (button) {
                busy(button);
            }
        });

        // Coming back with the back button serves this page from cache, still
        // wearing the busy state it had when it was left.
        window.addEventListener('pageshow', function () {
            document.querySelectorAll('.btn.is-busy').forEach(function (el) {
                el.classList.remove('is-busy');
                var spinner = el.querySelector('.' + SPIN);
                if (spinner) {
                    spinner.remove();
                }
            });
        });
    })();
</script>
