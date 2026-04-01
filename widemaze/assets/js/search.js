/**
 * WideMaze - Search Module
 * Version 5.0 - Recherche en temps réel, suggestions, filtres
 */

const Search = {
    // État
    state: {
        query: '',
        type: 'all',
        results: {
            users: [],
            posts: [],
            communities: []
        },
        totalCount: 0,
        currentPage: 1,
        hasMore: false,
        isLoading: false,
        filters: {
            date: 'all',
            university: '',
            country: '',
            category: '',
            hasImage: false
        },
        sort: 'relevance'
    },
    
    // Éléments DOM
    elements: {},
    
    // Timers
    searchTimeout: null,
    
    // ==================== INITIALISATION ====================
    init: function() {
        console.log('🔍 Search module initialized');
        
        this.cacheElements();
        this.initEventListeners();
        this.initFilters();
        
        // Si une recherche est déjà présente dans l'URL
        const urlParams = new URLSearchParams(window.location.search);
        const query = urlParams.get('q');
        if (query) {
            this.state.query = query;
            this.state.type = urlParams.get('type') || 'all';
            this.state.filters.date = urlParams.get('date') || 'all';
            this.state.filters.university = urlParams.get('university') || '';
            this.state.filters.country = urlParams.get('country') || '';
            this.state.filters.category = urlParams.get('category') || '';
            this.state.filters.hasImage = urlParams.get('has_image') === '1';
            this.state.sort = urlParams.get('sort') || 'relevance';
            
            this.performSearch();
        }
        
        return this;
    },
    
    cacheElements: function() {
        this.elements = {
            searchInput: document.getElementById('searchInput'),
            searchForm: document.getElementById('searchForm'),
            resultsContainer: document.getElementById('searchResults'),
            suggestionsDropdown: document.getElementById('suggestionsDropdown'),
            suggestionsList: document.getElementById('suggestionsList'),
            filterDate: document.getElementById('filterDate'),
            filterUniversity: document.getElementById('filterUniversity'),
            filterCountry: document.getElementById('filterCountry'),
            filterCategory: document.getElementById('filterCategory'),
            filterImage: document.getElementById('filterImage'),
            sortSelect: document.getElementById('sortSelect'),
            loadMoreBtn: document.getElementById('loadMoreBtn'),
            loadingSpinner: document.getElementById('loadingSpinner'),
            resultsCount: document.getElementById('resultsCount'),
            searchTime: document.getElementById('searchTime')
        };
    },
    
    initEventListeners: function() {
        // Recherche en temps réel
        if (this.elements.searchInput) {
            this.elements.searchInput.addEventListener('input', (e) => {
                clearTimeout(this.searchTimeout);
                const query = e.target.value.trim();
                
                if (query.length >= 2) {
                    this.searchTimeout = setTimeout(() => {
                        this.updateURL({ q: query });
                        this.performSearch();
                    }, 500);
                } else if (query.length === 0) {
                    this.clearResults();
                }
                
                if (query.length >= 2) {
                    this.showSuggestions(query);
                } else {
                    this.hideSuggestions();
                }
            });
        }
        
        // Soumission du formulaire
        if (this.elements.searchForm) {
            this.elements.searchForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const query = this.elements.searchInput?.value.trim();
                if (query) {
                    this.updateURL({ q: query });
                    this.performSearch();
                }
            });
        }
        
        // Filtres
        if (this.elements.filterDate) {
            this.elements.filterDate.addEventListener('change', () => this.applyFilters());
        }
        if (this.elements.filterUniversity) {
            this.elements.filterUniversity.addEventListener('change', () => this.applyFilters());
        }
        if (this.elements.filterCountry) {
            this.elements.filterCountry.addEventListener('change', () => this.applyFilters());
        }
        if (this.elements.filterCategory) {
            this.elements.filterCategory.addEventListener('change', () => this.applyFilters());
        }
        if (this.elements.filterImage) {
            this.elements.filterImage.addEventListener('change', () => this.applyFilters());
        }
        if (this.elements.sortSelect) {
            this.elements.sortSelect.addEventListener('change', () => this.applyFilters());
        }
        
        // Chargement infini
        window.addEventListener('scroll', () => this.handleScroll());
    },
    
    initFilters: function() {
        // Remplir les filtres avec les valeurs actuelles
        if (this.elements.filterDate && this.state.filters.date) {
            this.elements.filterDate.value = this.state.filters.date;
        }
        if (this.elements.filterUniversity && this.state.filters.university) {
            this.elements.filterUniversity.value = this.state.filters.university;
        }
        if (this.elements.filterCountry && this.state.filters.country) {
            this.elements.filterCountry.value = this.state.filters.country;
        }
        if (this.elements.filterCategory && this.state.filters.category) {
            this.elements.filterCategory.value = this.state.filters.category;
        }
        if (this.elements.filterImage && this.state.filters.hasImage) {
            this.elements.filterImage.checked = true;
        }
        if (this.elements.sortSelect && this.state.sort) {
            this.elements.sortSelect.value = this.state.sort;
        }
    },
    
    // ==================== RECHERCHE ====================
    performSearch: async function() {
        if (!this.state.query) return;
        
        this.showLoading();
        
        const params = new URLSearchParams({
            q: this.state.query,
            type: this.state.type,
            page: this.state.currentPage,
            limit: 20,
            sort: this.state.sort,
            date: this.state.filters.date,
            university: this.state.filters.university,
            country: this.state.filters.country,
            category: this.state.filters.category,
            has_image: this.state.filters.hasImage ? '1' : ''
        });
        
        try {
            const startTime = performance.now();
            const data = await App.ajax(`${App.config.apiBase}search.php?${params.toString()}`);
            const endTime = performance.now();
            
            if (data.success) {
                this.state.results = data.results;
                this.state.totalCount = data.total_count;
                this.state.hasMore = data.has_more;
                
                this.renderResults();
                this.updateStats(data.total_count, Math.round(endTime - startTime));
            }
        } catch (error) {
            console.error('Search error:', error);
            this.showError();
        } finally {
            this.hideLoading();
        }
    },
    
    renderResults: function() {
        if (!this.elements.resultsContainer) return;
        
        let html = '';
        
        // Résultats utilisateurs
        if ((this.state.type === 'all' || this.state.type === 'users') && this.state.results.users?.length) {
            html += this.renderUserResults(this.state.results.users);
        }
        
        // Résultats publications
        if ((this.state.type === 'all' || this.state.type === 'posts') && this.state.results.posts?.length) {
            html += this.renderPostResults(this.state.results.posts);
        }
        
        // Résultats communautés
        if ((this.state.type === 'all' || this.state.type === 'communities') && this.state.results.communities?.length) {
            html += this.renderCommunityResults(this.state.results.communities);
        }
        
        // Aucun résultat
        if (!html) {
            html = this.renderEmptyResults();
        }
        
        this.elements.resultsContainer.innerHTML = html;
        
        // Attacher les événements après rendu
        this.attachResultEvents();
    },
    
    renderUserResults: function(users) {
        return `
            <div class="mb-6">
                <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
                    <i class="fas fa-users text-orange-500"></i>Personnes
                    <span class="text-sm text-gray-400 font-normal">(${users.length} résultats)</span>
                </h2>
                <div class="space-y-3">
                    ${users.map(user => `
                        <div class="bg-white rounded-2xl shadow-sm p-4 hover:shadow-md transition-all">
                            <div class="flex items-start gap-4">
                                <img src="${App.config.siteUrl}uploads/avatars/${user.avatar || 'default-avatar.png'}" class="w-14 h-14 rounded-full object-cover">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <a href="/pages/profil.php?id=${user.id}" class="font-bold text-gray-800 hover:text-orange-500">
                                            ${this.highlightText(user.prenom + ' ' + user.nom)}
                                        </a>
                                        <span class="text-sm text-gray-500">@${user.surnom}</span>
                                    </div>
                                    <div class="flex flex-wrap gap-3 text-sm text-gray-500 mt-1">
                                        ${user.universite ? `<span><i class="fas fa-university"></i> ${this.highlightText(user.universite)}</span>` : ''}
                                        ${user.profession ? `<span><i class="fas fa-briefcase"></i> ${user.profession}</span>` : ''}
                                    </div>
                                    <div class="flex gap-3 mt-3">
                                        ${this.getFriendActionButton(user)}
                                        <a href="/pages/messagerie.php?user=${user.id}" class="bg-gray-100 text-gray-700 px-4 py-1.5 rounded-lg text-sm hover:bg-gray-200">
                                            <i class="fas fa-comment"></i> Message
                                        </a>
                                    </div>
                                </div>
                                ${user.status === 'Online' ? '<span class="w-2.5 h-2.5 bg-green-500 rounded-full animate-pulse"></span>' : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    },
    
    renderPostResults: function(posts) {
        return `
            <div class="mb-6">
                <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
                    <i class="fas fa-newspaper text-orange-500"></i>Publications
                    <span class="text-sm text-gray-400 font-normal">(${posts.length} résultats)</span>
                </h2>
                <div class="space-y-4">
                    ${posts.map(post => `
                        <div class="bg-white rounded-2xl shadow-sm p-5 hover:shadow-md transition-all">
                            <div class="flex items-center gap-3 mb-3">
                                <img src="${App.config.siteUrl}uploads/avatars/${post.avatar || 'default-avatar.png'}" class="w-10 h-10 rounded-full">
                                <div>
                                    <a href="/pages/profil.php?id=${post.user_id}" class="font-semibold text-gray-800 hover:text-orange-500">${this.highlightText(post.surnom)}</a>
                                    <p class="text-xs text-gray-400">${App.formatDate(post.date_publication)}</p>
                                </div>
                            </div>
                            <p class="text-gray-700 mb-3 line-clamp-3">${this.highlightText(post.contenu)}</p>
                            ${post.image_post ? `<img src="${App.config.siteUrl}uploads/posts/${post.image_post}" class="rounded-xl max-h-64 object-cover mb-3">` : ''}
                            <div class="flex items-center gap-4 text-sm text-gray-500">
                                <span><i class="fas fa-heart text-red-500"></i> ${post.likes_count}</span>
                                <span><i class="fas fa-comment text-blue-500"></i> ${post.comments_count}</span>
                                <a href="/index.php?post=${post.idpost}" class="hover:text-orange-500 ml-auto">Voir la publication →</a>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    },
    
    renderCommunityResults: function(communities) {
        const catIcons = { Academic: '🎓', Club: '👥', Social: '❤️', Sports: '⚽', Arts: '🎨', Tech: '💻', Career: '💼' };
        
        return `
            <div class="mb-6">
                <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
                    <i class="fas fa-university text-orange-500"></i>Communautés
                    <span class="text-sm text-gray-400 font-normal">(${communities.length} résultats)</span>
                </h2>
                <div class="grid md:grid-cols-2 gap-4">
                    ${communities.map(community => `
                        <div class="bg-white rounded-2xl shadow-sm p-4 hover:shadow-md transition-all">
                            <div class="flex items-start gap-3">
                                <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-red-500 rounded-xl flex items-center justify-center text-white font-bold text-lg">
                                    ${community.nom.charAt(0).toUpperCase()}
                                </div>
                                <div class="flex-1">
                                    <a href="/pages/communaute.php?id=${community.id_communaute}" class="font-bold text-gray-800 hover:text-orange-500">
                                        ${this.highlightText(community.nom)}
                                    </a>
                                    <p class="text-xs text-gray-500 mt-1">${catIcons[community.categorie] || '📁'} ${community.categorie || 'Academic'}</p>
                                    <p class="text-sm text-gray-600 mt-2 line-clamp-2">${this.highlightText(community.description || '')}</p>
                                    <div class="flex items-center justify-between mt-3">
                                        <span class="text-xs text-gray-500"><i class="fas fa-users"></i> ${community.members_count} membres</span>
                                        ${community.is_member ? 
                                            '<span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full"><i class="fas fa-check"></i> Membre</span>' :
                                            `<button onclick="window.search.joinCommunity(${community.id_communaute})" class="text-xs bg-orange-500 text-white px-3 py-1 rounded-full hover:bg-orange-600">Rejoindre</button>`
                                        }
                                    </div>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    },
    
    renderEmptyResults: function() {
        return `
            <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-search text-4xl text-gray-300"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-700 mb-2">Aucun résultat trouvé</h3>
                <p class="text-gray-500">Aucun résultat ne correspond à "${this.state.query}"</p>
                <div class="mt-6">
                    <p class="text-sm text-gray-500 mb-2">Suggestions :</p>
                    <ul class="text-sm text-gray-500 space-y-1">
                        <li>• Vérifiez l'orthographe</li>
                        <li>• Utilisez des termes plus généraux</li>
                        <li>• Essayez d'autres mots-clés</li>
                    </ul>
                </div>
            </div>
        `;
    },
    
    attachResultEvents: function() {
        // Les événements sont gérés via les attributs onclick
    },
    
    // ==================== SUGGESTIONS ====================
    showSuggestions: async function(query) {
        try {
            const data = await App.ajax(`${App.config.apiBase}search.php?suggestions=1&q=${encodeURIComponent(query)}&limit=8`);
            
            if (data.success && data.suggestions?.length) {
                this.elements.suggestionsList.innerHTML = data.suggestions.map(s => `
                    <a href="?q=${encodeURIComponent(s.query)}" class="flex items-center gap-3 p-3 hover:bg-orange-50 transition-colors border-b border-gray-100">
                        <i class="fas fa-search text-gray-400 w-5"></i>
                        <span class="flex-1 text-gray-700">${App.escapeHtml(s.query)}</span>
                        <span class="text-xs text-gray-400">${s.count} recherches</span>
                        <i class="fas fa-arrow-right text-gray-300"></i>
                    </a>
                `).join('');
                this.elements.suggestionsDropdown.classList.remove('hidden');
            } else {
                this.hideSuggestions();
            }
        } catch (error) {
            console.error('Error fetching suggestions:', error);
            this.hideSuggestions();
        }
    },
    
    hideSuggestions: function() {
        if (this.elements.suggestionsDropdown) {
            this.elements.suggestionsDropdown.classList.add('hidden');
        }
    },
    
    // ==================== FILTRES ====================
    applyFilters: function() {
        this.state.filters.date = this.elements.filterDate?.value || 'all';
        this.state.filters.university = this.elements.filterUniversity?.value || '';
        this.state.filters.country = this.elements.filterCountry?.value || '';
        this.state.filters.category = this.elements.filterCategory?.value || '';
        this.state.filters.hasImage = this.elements.filterImage?.checked || false;
        this.state.sort = this.elements.sortSelect?.value || 'relevance';
        this.state.currentPage = 1;
        
        this.updateURL();
        this.performSearch();
    },
    
    // ==================== ACTIONS ====================
    sendFriendRequest: async function(userId, btn) {
        try {
            const data = await App.ajax(`${App.config.apiBase}friends.php`, 'POST', {
                action: 'send_request',
                user_id: userId
            });
            
            if (data.success) {
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-clock"></i> En attente';
                    btn.disabled = true;
                    btn.classList.remove('bg-orange-500', 'hover:bg-orange-600');
                    btn.classList.add('bg-gray-300', 'text-gray-600');
                }
                App.showToast('Demande d\'ami envoyée', 'success');
            }
        } catch (error) {
            console.error('Error sending friend request:', error);
        }
    },
    
    joinCommunity: async function(communityId) {
        try {
            const data = await App.ajax(`${App.config.apiBase}communities.php`, 'POST', {
                action: 'join',
                community_id: communityId
            });
            
            if (data.success) {
                App.showToast('Vous avez rejoint la communauté', 'success');
                setTimeout(() => location.reload(), 1000);
            }
        } catch (error) {
            console.error('Error joining community:', error);
            App.showToast('Erreur lors de l\'inscription', 'error');
        }
    },
    
    // ==================== UTILITAIRES ====================
    highlightText: function(text) {
        if (!text || !this.state.query) return App.escapeHtml(text);
        
        const query = this.state.query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${query})`, 'gi');
        return App.escapeHtml(text).replace(regex, '<mark class="bg-yellow-200 text-gray-900 px-0.5 rounded">$1</mark>');
    },
    
    getFriendActionButton: function(user) {
        if (user.friendship_status === 'none') {
            return `<button onclick="window.search.sendFriendRequest(${user.id}, this)" class="bg-orange-500 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-orange-600">
                        <i class="fas fa-user-plus"></i> Ajouter
                    </button>`;
        } else if (user.friendship_status === 'pending_sent') {
            return `<span class="bg-gray-200 text-gray-600 px-4 py-1.5 rounded-lg text-sm">
                        <i class="fas fa-clock"></i> En attente
                    </span>`;
        } else if (user.friendship_status === 'pending_received') {
            return `
                <button onclick="window.search.acceptFriendRequest(${user.id})" class="bg-green-500 text-white px-3 py-1.5 rounded-lg text-sm">
                    <i class="fas fa-check"></i> Accepter
                </button>
                <button onclick="window.search.rejectFriendRequest(${user.id})" class="bg-red-500 text-white px-3 py-1.5 rounded-lg text-sm">
                    <i class="fas fa-times"></i> Refuser
                </button>
            `;
        } else if (user.friendship_status === 'friends') {
            return `<span class="bg-green-100 text-green-700 px-4 py-1.5 rounded-lg text-sm">
                        <i class="fas fa-check-circle"></i> Amis
                    </span>`;
        }
        return '';
    },
    
    acceptFriendRequest: async function(userId) {
        try {
            const data = await App.ajax(`${App.config.apiBase}friends.php`, 'POST', {
                action: 'accept_request',
                user_id: userId
            });
            if (data.success) location.reload();
        } catch (error) {
            console.error('Error accepting friend request:', error);
        }
    },
    
    rejectFriendRequest: async function(userId) {
        try {
            const data = await App.ajax(`${App.config.apiBase}friends.php`, 'POST', {
                action: 'reject_request',
                user_id: userId
            });
            if (data.success) location.reload();
        } catch (error) {
            console.error('Error rejecting friend request:', error);
        }
    },
    
    updateURL: function(additionalParams = {}) {
        const params = new URLSearchParams();
        
        if (this.state.query) params.set('q', this.state.query);
        if (this.state.type !== 'all') params.set('type', this.state.type);
        if (this.state.sort !== 'relevance') params.set('sort', this.state.sort);
        if (this.state.filters.date !== 'all') params.set('date', this.state.filters.date);
        if (this.state.filters.university) params.set('university', this.state.filters.university);
        if (this.state.filters.country) params.set('country', this.state.filters.country);
        if (this.state.filters.category) params.set('category', this.state.filters.category);
        if (this.state.filters.hasImage) params.set('has_image', '1');
        
        Object.entries(additionalParams).forEach(([key, value]) => {
            if (value) params.set(key, value);
        });
        
        const newUrl = `${window.location.pathname}?${params.toString()}`;
        window.history.pushState({}, '', newUrl);
    },
    
    clearResults: function() {
        if (this.elements.resultsContainer) {
            this.elements.resultsContainer.innerHTML = '';
        }
    },
    
    updateStats: function(count, time) {
        if (this.elements.resultsCount) {
            this.elements.resultsCount.textContent = `${count} résultat${count > 1 ? 's' : ''}`;
        }
        if (this.elements.searchTime) {
            this.elements.searchTime.textContent = `${time} ms`;
        }
    },
    
    showLoading: function() {
        if (this.elements.loadingSpinner) {
            this.elements.loadingSpinner.classList.remove('hidden');
        }
        if (this.elements.resultsContainer) {
            this.elements.resultsContainer.innerHTML = '<div class="text-center py-8"><div class="loading-spinner w-12 h-12 border-4 border-orange-500 border-t-transparent rounded-full animate-spin mx-auto"></div><p class="text-gray-500 mt-3">Recherche en cours...</p></div>';
        }
    },
    
    hideLoading: function() {
        if (this.elements.loadingSpinner) {
            this.elements.loadingSpinner.classList.add('hidden');
        }
    },
    
    showError: function() {
        if (this.elements.resultsContainer) {
            this.elements.resultsContainer.innerHTML = `
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <i class="fas fa-exclamation-triangle text-5xl text-red-400 mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">Erreur de recherche</h3>
                    <p class="text-gray-500">Une erreur est survenue. Veuillez réessayer.</p>
                </div>
            `;
        }
    },
    
    handleScroll: function() {
        if (!this.state.hasMore || this.state.isLoading) return;
        
        const scrollPosition = window.innerHeight + window.scrollY;
        const pageHeight = document.documentElement.scrollHeight;
        
        if (scrollPosition >= pageHeight - 500) {
            this.loadMore();
        }
    },
    
    loadMore: async function() {
        if (this.state.isLoading || !this.state.hasMore) return;
        
        this.state.currentPage++;
        this.state.isLoading = true;
        
        if (this.elements.loadMoreBtn) {
            this.elements.loadMoreBtn.disabled = true;
            this.elements.loadMoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Chargement...';
        }
        
        await this.performSearch();
        
        this.state.isLoading = false;
        if (this.elements.loadMoreBtn) {
            this.elements.loadMoreBtn.disabled = false;
            this.elements.loadMoreBtn.innerHTML = '<i class="fas fa-arrow-down"></i> Charger plus';
            if (!this.state.hasMore) {
                this.elements.loadMoreBtn.style.display = 'none';
            }
        }
    }
};

// Initialisation
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('searchInput')) {
        window.search = Search.init();
    }
});