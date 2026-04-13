(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    var currentScript = document.currentScript;
    var swUrl = (currentScript && currentScript.dataset && currentScript.dataset.swUrl) || '/sw.js';
    var swScope = (currentScript && currentScript.dataset && currentScript.dataset.swScope) || '/';

    window.addEventListener('load', function () {
        navigator.serviceWorker.register(swUrl, { scope: swScope }).catch(function (error) {
            console.warn('Service worker registration failed:', error);
        });
    });
})();
