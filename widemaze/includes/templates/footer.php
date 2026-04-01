<?php
/**
 * WideMaze - Footer Template
 * Includes scripts and closing tags
 */
?>
    <!-- jQuery (if needed) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script src="<?= SITE_URL ?>/assets/js/app.js"></script>
    
    <?php if (isset($additional_js)): ?>
        <?= $additional_js ?>
    <?php endif; ?>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 space-y-2"></div>
    
    <script>
        // Toast notification function
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const colors = {
                success: 'bg-green-500',
                error: 'bg-red-500',
                info: 'bg-blue-500',
                warning: 'bg-yellow-500'
            };
            
            const toast = document.createElement('div');
            toast.className = `${colors[type]} text-white px-6 py-3 rounded-xl shadow-lg flex items-center gap-3 animate-fade-in`;
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                <span class="font-medium">${message}</span>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(20px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // CSRF Token for AJAX requests
        const csrfToken = '<?= generate_csrf_token() ?>';
        const siteUrl = '<?= SITE_URL ?>';
        
        // Helper function for AJAX requests
        async function ajaxRequest(url, method = 'GET', data = null) {
            const options = {
                method: method,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            };
            
            if (data instanceof FormData) {
                options.body = data;
            } else if (data) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(data);
            }
            
            try {
                const response = await fetch(url, options);
                return await response.json();
            } catch (error) {
                console.error('AJAX Error:', error);
                showToast('Erreur de connexion', 'error');
                return null;
            }
        }
        
        // Close modal on outside click helper
        function setupModalClose(modalId, closeFn) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal && closeFn) {
                        closeFn();
                    }
                });
            }
        }
        
        // Escape HTML helper
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>