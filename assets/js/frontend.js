/**
 * WPCalibrate Support Desk - Frontend Scripts
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var fileInputs = document.querySelectorAll('.wpcalibrate-file-input');

        fileInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                var maxBytes = 50 * 1024 * 1024; // 50MB hard limit check
                if (input.files) {
                    for (var i = 0; i < input.files.length; i++) {
                        if (input.files[i].size > maxBytes) {
                            alert('File "' + input.files[i].name + '" exceeds the maximum upload limit.');
                            input.value = '';
                            return;
                        }
                    }
                }
            });
        });
    });
})();
