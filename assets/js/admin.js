/**
 * WPCalibrate Support Desk - Admin Scripts
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Confirm delete operations
        var deleteButtons = document.querySelectorAll('.button-link-delete, [data-confirm]');
        deleteButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var message = btn.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
                if (!window.confirm(message)) {
                    e.preventDefault();
                }
            });
        });
    });
})();
