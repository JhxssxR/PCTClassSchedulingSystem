    </div> <!-- End of content div -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add active class to current page in sidebar
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = window.location.pathname;
            const navLinks = document.querySelectorAll('.nav-link');
            
            navLinks.forEach(link => {
                if (currentPage === new URL(link.href).pathname) {
                    link.classList.add('active');
                }
            });
        });
    </script>
</body>
</html> 