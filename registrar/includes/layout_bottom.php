            </main>
        </div>
    </div>

    <!-- Bootstrap JS for legacy pages (modals, dropdowns) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const btn = document.getElementById('sidebarBtn');

            function openSidebar() {
                if (!sidebar || !overlay) return;
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            }

            function closeSidebar() {
                if (!sidebar || !overlay) return;
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }

            // Expose for any existing onclick handlers
            window.openSidebar = openSidebar;
            window.closeSidebar = closeSidebar;

            btn?.addEventListener('click', function () {
                if (!sidebar) return;
                if (sidebar.classList.contains('-translate-x-full')) {
                    openSidebar();
                } else {
                    closeSidebar();
                }
            });
            overlay?.addEventListener('click', closeSidebar);
        })();

        (function () {
            const btn = document.getElementById('notifBtn');
            const menu = document.getElementById('notifMenu');
            const dot = document.getElementById('notifDot');
            const markBtn = document.getElementById('notifMarkRead');
            const delBtn = document.getElementById('notifDelete');
            if (!btn || !menu) return;

            function isOpen() {
                return !menu.classList.contains('hidden');
            }

            async function postAction(action) {
                try {
                    const res = await fetch('notifications_seen.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=' + encodeURIComponent(action)
                    });
                    return res.ok;
                } catch (_) {
                    return false;
                }
            }

            function openMenu() {
                menu.classList.remove('hidden');
                btn.setAttribute('aria-expanded', 'true');
            }

            function closeMenu() {
                menu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            }

            function toggleMenu() {
                if (isOpen()) closeMenu();
                else openMenu();
            }

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggleMenu();
            });

            markBtn?.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const ok = await postAction('seen');
                if (ok) {
                    dot?.classList.add('hidden');
                    closeMenu();
                    location.reload();
                }
            });

            delBtn?.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const ok = await postAction('delete');
                if (ok) {
                    dot?.classList.add('hidden');
                    closeMenu();
                    location.reload();
                }
            });

            document.addEventListener('click', function (e) {
                if (!isOpen()) return;
                const target = e.target;
                if (!(target instanceof Element)) return;
                if (menu.contains(target) || btn.contains(target)) return;
                closeMenu();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeMenu();
            });
        })();

        function openSidebar() {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
        }

        function closeSidebar() {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            if (!sidebar || !overlay) return;
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        }

        window.addEventListener('resize', function () {
            if (window.innerWidth >= 1024) {
                var overlay = document.getElementById('sidebarOverlay');
                var sidebar = document.getElementById('sidebar');
                if (overlay) overlay.classList.add('hidden');
                if (sidebar) sidebar.classList.remove('-translate-x-full');
            } else {
                closeSidebar();
            }
        });
    </script>
</body>
</html>
