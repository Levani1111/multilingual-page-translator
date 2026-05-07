(function () {
    function messageFor(type) {
        var messages = window.mptAdmin || {};

        if (type === 'options') {
            return messages.optionsMessage || 'Translating option-page fields. Please keep this tab open.';
        }

        if (type === 'settings') {
            return messages.settingsMessage || 'Saving settings...';
        }

        return messages.duplicateMessage || 'Duplicating and translating pages. Please keep this tab open.';
    }

    function showOverlay(message) {
        var overlay = document.querySelector('.mpt-progress-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'mpt-progress-overlay';
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-live', 'polite');
            overlay.innerHTML = '<div class="mpt-progress-box"><span class="mpt-progress-spinner" aria-hidden="true"></span><strong class="mpt-progress-title"></strong><p></p></div>';
            document.body.appendChild(overlay);
        }

        overlay.querySelector('.mpt-progress-title').textContent = (window.mptAdmin && window.mptAdmin.buttonText) || 'Working...';
        overlay.querySelector('p').textContent = message;
        overlay.classList.add('is-visible');
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-mpt-progress]');
        if (!form || form.dataset.mptSubmitted === '1') {
            return;
        }

        form.dataset.mptSubmitted = '1';
        var type = form.getAttribute('data-mpt-progress') || 'duplicate';
        showOverlay(messageFor(type));

        form.querySelectorAll('input[type="submit"], button[type="submit"]').forEach(function (button) {
            button.disabled = true;
            button.dataset.originalValue = button.value || button.textContent;
            if (button.value) {
                button.value = (window.mptAdmin && window.mptAdmin.buttonText) || 'Working...';
            } else {
                button.textContent = (window.mptAdmin && window.mptAdmin.buttonText) || 'Working...';
            }
        });
    }, true);
})();
