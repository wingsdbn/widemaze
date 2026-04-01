/**
 * WideMaze - Application Core
 * Version 5.0 - Gestion globale, utilitaires, initialisation
 */

// ==================== CONFIGURATION GLOBALE ====================
const App = {
    // Configuration
    config: {
        debug: false,
        apiBase: '/api/',
        siteUrl: window.location.origin + '/widemaze/',
        pollingInterval: 30000,
        typingTimeout: 2000,
        maxFileSize: 10 * 1024 * 1024,
        allowedImageTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
    },
    
    // État global
    state: {
        isLoggedIn: false,
        currentUserId: null,
        currentUser: {},
        notifications: [],
        unreadCount: 0,
        onlineUsers: [],
        csrfToken: null,
        isMobile: window.innerWidth <= 768,
        darkMode: localStorage.getItem('darkMode') === 'true'
    },
    
    // Éléments DOM
    elements: {},
    
    // Timers
    timers: {},
    
    // ==================== INITIALISATION ====================
    init: function() {
        console.log('🚀 WideMaze App Initialized');
        
        // Récupérer les données de session
        this.state.csrfToken = window.csrfToken || null;
        this.state.currentUserId = window.currentUserId || null;
        
        // Initialiser les éléments DOM
        this.cacheElements();
        
        // Appliquer le thème
        if (this.state.darkMode) {
            document.body.classList.add('dark');
        }
        
        // Initialiser les composants
        this.initEventListeners();
        this.initToastContainer();
        this.initModals();
        this.initDropdowns();
        this.initInfiniteScroll();
        
        // Démarrer les services en arrière-plan
        if (this.state.isLoggedIn) {
            this.startPolling();
            this.initWebSocket();
        }
        
        // Observer le réseau
        this.initNetworkObserver();
        
        // Enregistrer le service worker (PWA)
        this.registerServiceWorker();
        
        console.log('✅ App initialized successfully');
    },
    
    // ==================== DOM ELEMENTS ====================
    cacheElements: function() {
        this.elements = {
            body: document.body,
            toastContainer: document.getElementById('toastContainer'),
            modalOverlay: document.querySelector('.modal-overlay'),
            loadingOverlay: document.querySelector('.loading-overlay'),
            searchInput: document.getElementById('globalSearch'),
            searchResults: document.getElementById('searchResults')
        };
        
        // Créer le conteneur de toast s'il n'existe pas
        if (!this.elements.toastContainer) {
            this.createToastContainer();
        }
    },
    
    createToastContainer: function() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'fixed bottom-4 right-4 z-50 space-y-2';
        document.body.appendChild(container);
        this.elements.toastContainer = container;
    },
    
    // ==================== EVENT LISTENERS ====================
    initEventListeners: function() {
        // Fermer les modals au clic extérieur
        document.addEventListener('click', (e) => {
            if (e.target.classList?.contains('modal-overlay')) {
                this.closeAllModals();
            }
        });
        
        // Fermer les dropdowns au clic extérieur
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown.active').forEach(dropdown => {
                    dropdown.classList.remove('active');
                });
            }
        });
        
        // Gestion du scroll
        window.addEventListener('scroll', this.handleScroll.bind(this));
        
        // Gestion de la connexion réseau
        window.addEventListener('online', () => this.showToast('Connexion rétablie', 'success'));
        window.addEventListener('offline', () => this.showToast('Connexion perdue', 'error'));
        
        // Gestion du redimensionnement
        window.addEventListener('resize', this.debounce(() => {
            this.state.isMobile = window.innerWidth <= 768;
        }, 250));
        
        // Prévenir la fermeture accidentelle
        window.addEventListener('beforeunload', (e) => {
            if (this.state.unsavedChanges) {
                e.preventDefault();
                e.returnValue = 'Vous avez des modifications non enregistrées.';
            }
        });
    },
    
    // ==================== MODALS ====================
    initModals: function() {
        // Ajouter la classe modal-overlay aux modals
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.add('modal-overlay');
        });
    },
    
    openModal: function(modalId, options = {}) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Animation d'entrée
        const content = modal.querySelector('.modal-content');
        if (content) {
            content.style.animation = 'scaleIn 0.3s ease-out';
        }
        
        // Callback d'ouverture
        if (options.onOpen) options.onOpen();
    },
    
    closeModal: function(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        modal.classList.remove('active');
        document.body.style.overflow = '';
        
        // Animation de sortie
        const content = modal.querySelector('.modal-content');
        if (content) {
            content.style.animation = 'fadeOut 0.2s ease-out';
        }
    },
    
    closeAllModals: function() {
        document.querySelectorAll('.modal.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    },
    
    // ==================== DROPDOWNS ====================
    initDropdowns: function() {
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const dropdown = toggle.closest('.dropdown');
                dropdown.classList.toggle('active');
            });
        });
    },
    
    // ==================== TOAST NOTIFICATIONS ====================
    showToast: function(message, type = 'info', duration = 4000) {
        const colors = {
            success: 'bg-gradient-to-r from-green-500 to-green-600',
            error: 'bg-gradient-to-r from-red-500 to-red-600',
            info: 'bg-gradient-to-r from-blue-500 to-blue-600',
            warning: 'bg-gradient-to-r from-yellow-500 to-yellow-600'
        };
        
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            info: 'fa-info-circle',
            warning: 'fa-exclamation-triangle'
        };
        
        const toast = document.createElement('div');
        toast.className = `${colors[type]} text-white px-5 py-3 rounded-xl shadow-lg flex items-center gap-3 animate-fade-in-right`;
        toast.innerHTML = `
            <i class="fas ${icons[type]}"></i>
            <span class="flex-1 font-medium">${this.escapeHtml(message)}</span>
            <button class="toast-close text-white/70 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        this.elements.toastContainer.appendChild(toast);
        
        // Bouton de fermeture
        const closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', () => {
            toast.style.animation = 'fadeOut 0.2s ease-out';
            setTimeout(() => toast.remove(), 200);
        });
        
        // Auto-fermeture
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.animation = 'fadeOut 0.2s ease-out';
                setTimeout(() => toast.remove(), 200);
            }
        }, duration);
    },
    
    // ==================== LOADING STATES ====================
    showLoading: function(message = 'Chargement...') {
        const overlay = document.createElement('div');
        overlay.id = 'loadingOverlay';
        overlay.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm z-[100] flex items-center justify-center';
        overlay.innerHTML = `
            <div class="bg-white rounded-2xl p-8 flex flex-col items-center gap-4 animate-scale-in">
                <div class="loading-spinner w-12 h-12 border-4 border-orange-500 border-t-transparent rounded-full animate-spin"></div>
                <p class="text-gray-600 font-medium">${this.escapeHtml(message)}</p>
            </div>
        `;
        document.body.appendChild(overlay);
    },
    
    hideLoading: function() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.animation = 'fadeOut 0.2s ease-out';
            setTimeout(() => overlay.remove(), 200);
        }
    },
    
    // ==================== AJAX REQUESTS ====================
    ajax: async function(url, method = 'GET', data = null, options = {}) {
        const config = {
            method: method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                ...options.headers
            },
            ...options
        };
        
        // Ajouter le token CSRF pour les requêtes POST/PUT/DELETE
        if (['POST', 'PUT', 'DELETE'].includes(method) && this.state.csrfToken) {
            if (data instanceof FormData) {
                data.append('csrf_token', this.state.csrfToken);
            } else if (data && typeof data === 'object') {
                data.csrf_token = this.state.csrfToken;
            } else {
                config.headers['X-CSRF-Token'] = this.state.csrfToken;
            }
        }
        
        if (data instanceof FormData) {
            config.body = data;
        } else if (data && typeof data === 'object') {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(data);
        }
        
        try {
            const response = await fetch(url, config);
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.error || 'Erreur serveur');
            }
            
            return result;
        } catch (error) {
            console.error('AJAX Error:', error);
            this.showToast(error.message || 'Erreur de connexion', 'error');
            throw error;
        }
    },
    
    // ==================== UTILITAIRES ====================
    escapeHtml: function(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    debounce: function(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },
    
    throttle: function(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },
    
    formatDate: function(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);
        
        if (diff < 60) return 'À l\'instant';
        if (diff < 3600) return `Il y a ${Math.floor(diff / 60)} min`;
        if (diff < 86400) return `Il y a ${Math.floor(diff / 3600)} h`;
        if (diff < 604800) return `Il y a ${Math.floor(diff / 86400)} j`;
        
        return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
    },
    
    formatNumber: function(number) {
        if (number >= 1000000) return (number / 1000000).toFixed(1) + 'M';
        if (number >= 1000) return (number / 1000).toFixed(1) + 'k';
        return number.toString();
    },
    
    formatFileSize: function(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    },
    
    copyToClipboard: async function(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.showToast('Copié dans le presse-papier !', 'success');
            return true;
        } catch (err) {
            console.error('Copy failed:', err);
            this.showToast('Impossible de copier', 'error');
            return false;
        }
    },
    
    // ==================== SCROLL HANDLING ====================
    handleScroll: function() {
        // Chargement infini
        if (this.shouldLoadMore()) {
            this.loadMoreContent();
        }
        
        // Affichage du bouton retour en haut
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const backToTop = document.getElementById('backToTop');
        
        if (backToTop) {
            if (scrollTop > 300) {
                backToTop.classList.remove('hidden');
            } else {
                backToTop.classList.add('hidden');
            }
        }
    },
    
    scrollToTop: function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    
    scrollToBottom: function(element) {
        if (element) {
            element.scrollTop = element.scrollHeight;
        }
    },
    
    // ==================== INFINITE SCROLL ====================
    initInfiniteScroll: function() {
        this.state.hasMore = true;
        this.state.isLoadingMore = false;
    },
    
    shouldLoadMore: function() {
        const scrollPosition = window.innerHeight + window.scrollY;
        const pageHeight = document.documentElement.scrollHeight;
        return scrollPosition >= pageHeight - 500 && this.state.hasMore && !this.state.isLoadingMore;
    },
    
    loadMoreContent: function() {
        // À implémenter dans les modules spécifiques
        if (window.feed && typeof window.feed.loadMore === 'function') {
            window.feed.loadMore();
        }
    },
    
    // ==================== NETWORK OBSERVER ====================
    initNetworkObserver: function() {
        if ('connection' in navigator) {
            const connection = navigator.connection;
            this.state.connectionType = connection.effectiveType;
            connection.addEventListener('change', () => {
                this.state.connectionType = connection.effectiveType;
                if (connection.effectiveType === 'slow-2g' || connection.effectiveType === '2g') {
                    this.showToast('Connexion lente détectée', 'warning');
                }
            });
        }
    },
    
    // ==================== POLLING ====================
    startPolling: function() {
        // Polling pour les notifications
        this.timers.notifications = setInterval(() => {
            this.checkNotifications();
        }, this.config.pollingInterval);
    },
    
    stopPolling: function() {
        if (this.timers.notifications) {
            clearInterval(this.timers.notifications);
        }
    },
    
    checkNotifications: async function() {
        try {
            const response = await this.ajax(`${this.config.apiBase}notifications.php?action=count`);
            if (response.unread_count !== this.state.unreadCount) {
                this.state.unreadCount = response.unread_count;
                this.updateNotificationBadge();
            }
        } catch (error) {
            console.error('Error checking notifications:', error);
        }
    },
    
    
    // ==================== WEBSOCKET (OPTIONNEL) ====================
    initWebSocket: function() {
        // Implémentation WebSocket pour le temps réel
        if ('WebSocket' in window && this.config.wsUrl) {
            this.ws = new WebSocket(this.config.wsUrl);
            
            this.ws.onopen = () => {
                console.log('WebSocket connected');
                this.ws.send(JSON.stringify({
                    type: 'auth',
                    user_id: this.state.currentUserId
                }));
            };
            
            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                this.handleWebSocketMessage(data);
            };
            
            this.ws.onclose = () => {
                console.log('WebSocket disconnected');
                setTimeout(() => this.initWebSocket(), 5000);
            };
        }
    },
    
    handleWebSocketMessage: function(data) {
        switch (data.type) {
            case 'new_notification':
                this.state.unreadCount++;
                this.updateNotificationBadge();
                this.showToast(data.message, 'info');
                break;
            case 'new_message':
                if (window.messaging && typeof window.messaging.handleNewMessage === 'function') {
                    window.messaging.handleNewMessage(data);
                }
                break;
            case 'user_status':
                if (window.feed && typeof window.feed.updateUserStatus === 'function') {
                    window.feed.updateUserStatus(data.user_id, data.status);
                }
                break;
        }
    },
    
    // ==================== PWA / SERVICE WORKER ====================
    registerServiceWorker: function() {
        if ('serviceWorker' in navigator && 'https:' === location.protocol) {
            navigator.serviceWorker.register('/sw.js')
                .then(registration => {
                    console.log('ServiceWorker registered:', registration.scope);
                })
                .catch(error => {
                    console.log('ServiceWorker registration failed:', error);
                });
        }
    },
    
    // ==================== THEME ====================
    toggleDarkMode: function() {
        this.state.darkMode = !this.state.darkMode;
        localStorage.setItem('darkMode', this.state.darkMode);
        
        if (this.state.darkMode) {
            document.body.classList.add('dark');
        } else {
            document.body.classList.remove('dark');
        }
        
        this.showToast(this.state.darkMode ? 'Mode sombre activé' : 'Mode clair activé', 'info');
    },
    
    // ==================== DEBUG ====================
    log: function(...args) {
        if (this.config.debug) {
            console.log('[WideMaze]', ...args);
        }
    }
};

// ==================== INITIALISATION AU CHARGEMENT ====================
document.addEventListener('DOMContentLoaded', () => {
    App.init();
});

// ==================== EXPOSER LES FONCTIONS GLOBALES ====================
window.App = App;
window.showToast = (msg, type) => App.showToast(msg, type);
window.escapeHtml = (text) => App.escapeHtml(text);
window.formatDate = (date) => App.formatDate(date);