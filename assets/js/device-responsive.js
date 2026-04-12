(function () {
    'use strict';

    if (window.__pctDeviceResponsiveLoaded) {
        return;
    }
    window.__pctDeviceResponsiveLoaded = true;

    var root = document.documentElement;

    function setViewportHeightVar() {
        var vh = window.innerHeight * 0.01;
        root.style.setProperty('--app-vh', vh + 'px');
    }

    function syncVisibleCustomModalHeights() {
        var selectors = [
            '[id*="Modal"].fixed.inset-0:not(.hidden)',
            '[id*="modal"].fixed.inset-0:not(.hidden)'
        ];
        var modals = document.querySelectorAll(selectors.join(','));

        modals.forEach(function (modal) {
            var panel = modal.querySelector(
                '.absolute.inset-0.p-4 > div, .absolute.inset-0.p-3 > div, .absolute.inset-0.p-2 > div'
            );
            if (!panel) {
                return;
            }

            panel.style.maxHeight = 'calc(var(--app-vh) * 92)';

            var scrollAreas = panel.querySelectorAll('.overflow-auto, .overflow-y-auto, .modal-body');
            scrollAreas.forEach(function (el) {
                if (!el.style.maxHeight) {
                    el.style.maxHeight = 'calc(var(--app-vh) * 62)';
                }
            });
        });
    }

    setViewportHeightVar();
    syncVisibleCustomModalHeights();

    window.addEventListener('resize', function () {
        setViewportHeightVar();
        syncVisibleCustomModalHeights();
    }, { passive: true });

    window.addEventListener('orientationchange', function () {
        setViewportHeightVar();
        syncVisibleCustomModalHeights();
    }, { passive: true });

    document.addEventListener('DOMContentLoaded', function () {
        syncVisibleCustomModalHeights();
    });

    var observer = new MutationObserver(function () {
        syncVisibleCustomModalHeights();
    });

    observer.observe(document.documentElement, {
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style', 'aria-hidden']
    });
})();
