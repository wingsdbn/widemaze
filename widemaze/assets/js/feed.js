/**
 * WideMaze - Feed Module
 * Version 5.0 - Gestion du fil d'actualités, posts, likes, commentaires
 */

const Feed = {
    // État
    state: {
        posts: [],
        currentFilter: 'all',
        currentOffset: 0,
        hasMore: true,
        isLoading: false,
        selectedPostId: null,
        editingPostId: null
    },
    
    // Éléments DOM
    elements: {},
    
    // ==================== INITIALISATION ====================
    init: function() {
        console.log('📰 Feed module initialized');
        
        this.cacheElements();
        this.initEventListeners();
        this.loadFeed();
        
        return this;
    },
    
    cacheElements: function() {
        this.elements = {
            container: document.getElementById('postsContainer'),
            loadMoreBtn: document.getElementById('loadMoreBtn'),
            loadMoreSpinner: document.getElementById('loadMoreSpinner'),
            filters: document.querySelectorAll('.feed-filter'),
            createPostModal: document.getElementById('createPostModal'),
            createPostContent: document.getElementById('postContent'),
            createPostImage: document.getElementById('postImage'),
            createPostPrivacy: document.getElementById('postPrivacy'),
            submitPostBtn: document.getElementById('submitPostBtn'),
            imagePreview: document.getElementById('imagePreview')
        };
    },
    
    initEventListeners: function() {
        // Filtres
        this.elements.filters.forEach(btn => {
            btn.addEventListener('click', () => {
                const filter = btn.dataset.filter;
                this.filterFeed(filter);
            });
        });
        
        // Création de post
        if (this.elements.submitPostBtn) {
            this.elements.submitPostBtn.addEventListener('click', () => this.submitPost());
        }
        
        // Upload d'image
        if (this.elements.createPostImage) {
            this.elements.createPostImage.addEventListener('change', (e) => this.previewImage(e.target.files[0]));
        }
        
        // Auto-resize textarea
        if (this.elements.createPostContent) {
            this.elements.createPostContent.addEventListener('input', () => this.autoResizeTextarea());
        }
    },
    
    // ==================== CHARGEMENT DU FEED ====================
    loadFeed: async function(append = false) {
        if (this.state.isLoading) return;
        
        this.state.isLoading = true;
        
        if (!append) {
            this.state.currentOffset = 0;
            this.state.posts = [];
            this.showSkeleton();
        }
        
        try {
            const url = `${App.config.apiBase}posts.php?action=feed&filter=${this.state.currentFilter}&limit=10&offset=${this.state.currentOffset}`;
            const data = await App.ajax(url);
            
            if (data.success) {
                if (append) {
                    this.state.posts = [...this.state.posts, ...data.posts];
                    data.posts.forEach(post => this.appendPost(post));
                } else {
                    this.state.posts = data.posts;
                    this.renderPosts(data.posts);
                }
                
                this.state.hasMore = data.has_more;
                this.toggleLoadMoreButton(data.has_more);
            }
        } catch (error) {
            console.error('Error loading feed:', error);
            App.showToast('Erreur de chargement du fil', 'error');
        } finally {
            this.state.isLoading = false;
            this.hideSkeleton();
        }
    },
    
    loadMore: function() {
        if (!this.state.hasMore || this.state.isLoading) return;
        
        this.state.currentOffset += 10;
        this.loadFeed(true);
        
        if (this.elements.loadMoreSpinner) {
            this.elements.loadMoreSpinner.classList.remove('hidden');
        }
    },
    
    filterFeed: function(filter) {
        this.state.currentFilter = filter;
        this.state.currentOffset = 0;
        this.loadFeed();
        
        // Mettre à jour les filtres visuellement
        this.elements.filters.forEach(btn => {
            if (btn.dataset.filter === filter) {
                btn.classList.add('bg-primary', 'text-white', 'shadow-md');
                btn.classList.remove('bg-white', 'text-gray-600', 'border', 'border-gray-200');
            } else {
                btn.classList.remove('bg-primary', 'text-white', 'shadow-md');
                btn.classList.add('bg-white', 'text-gray-600', 'border', 'border-gray-200');
            }
        });
    },
    
    // ==================== RENDU DES POSTS ====================
    renderPosts: function(posts) {
        if (!this.elements.container) return;
        
        this.elements.container.innerHTML = '';
        
        if (posts.length === 0) {
            this.elements.container.innerHTML = this.getEmptyStateHTML();
            return;
        }
        
        posts.forEach(post => {
            this.appendPost(post);
        });
    },
    
    appendPost: function(post) {
        const postHTML = this.createPostHTML(post);
        this.elements.container.insertAdjacentHTML('beforeend', postHTML);
        
        // Attacher les événements
        const postElement = this.elements.container.lastElementChild;
        this.attachPostEvents(postElement, post);
    },
    
    createPostHTML: function(post) {
        const timeAgo = App.formatDate(post.date_publication);
        const likeClass = post.user_liked ? 'fas fa-heart text-red-500' : 'far fa-heart';
        const privacyIcon = {
            public: '<i class="fas fa-globe text-xs"></i>',
            friends: '<i class="fas fa-user-friends text-xs"></i>',
            private: '<i class="fas fa-lock text-xs"></i>'
        }[post.privacy] || '<i class="fas fa-globe text-xs"></i>';
        
        return `
            <article class="post-card bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition-all duration-300 animate-fade-in-up" data-post-id="${post.idpost}">
                <div class="p-5 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <a href="/pages/profil.php?id=${post.id_utilisateur}" class="relative">
                            <img src="${post.avatar ? App.config.siteUrl + 'uploads/avatars/' + post.avatar : App.config.siteUrl + 'uploads/avatars/default-avatar.png'}" class="w-12 h-12 rounded-full object-cover border-2 border-gray-100 hover:border-primary transition-colors">
                        </a>
                        <div>
                            <a href="/pages/profil.php?id=${post.id_utilisateur}" class="font-bold text-gray-800 hover:text-primary transition-colors block">${App.escapeHtml(post.surnom)}</a>
                            <div class="flex items-center gap-2 text-xs text-gray-500">
                                <span>${timeAgo}</span>
                                <span>•</span>
                                <span>${privacyIcon}</span>
                                ${post.edited_at ? '<span class="text-gray-400">(modifié)</span>' : ''}
                            </div>
                        </div>
                    </div>
                    ${post.id_utilisateur == App.state.currentUserId ? `
                        <div class="relative">
                            <button class="post-menu-btn p-2 hover:bg-gray-100 rounded-full transition-colors">
                                <i class="fas fa-ellipsis-h text-gray-400"></i>
                            </button>
                            <div class="post-menu hidden absolute right-0 top-full mt-1 w-48 bg-white rounded-xl shadow-xl border border-gray-100 z-10">
                                <button class="edit-post-btn w-full text-left px-4 py-2.5 hover:bg-gray-50 text-gray-700 flex items-center gap-2 rounded-t-xl">
                                    <i class="fas fa-pen text-gray-400"></i>Modifier
                                </button>
                                <button class="delete-post-btn w-full text-left px-4 py-2.5 hover:bg-red-50 text-red-600 flex items-center gap-2">
                                    <i class="fas fa-trash text-red-400"></i>Supprimer
                                </button>
                            </div>
                        </div>
                    ` : ''}
                </div>
                <div class="px-5 pb-3">
                    <p class="text-gray-800 whitespace-pre-wrap">${App.escapeHtml(post.contenu).replace(/\n/g, '<br>')}</p>
                </div>
                ${post.image_post ? `
                    <div class="relative cursor-pointer" onclick="window.open('${App.config.siteUrl}uploads/posts/${post.image_post}', '_blank')">
                        <img src="${App.config.siteUrl}uploads/posts/${post.image_post}" class="w-full max-h-[500px] object-cover" loading="lazy">
                    </div>
                ` : ''}
                <div class="px-5 py-3 flex items-center justify-between text-sm text-gray-500 border-t border-gray-100">
                    <div class="flex items-center gap-1">
                        <span class="w-5 h-5 bg-red-500 rounded-full flex items-center justify-center text-white text-xs">
                            <i class="fas fa-heart"></i>
                        </span>
                        <span class="likes-count">${this.formatNumber(post.likes_count)}</span>
                    </div>
                    <div class="flex gap-4">
                        <span class="comments-count cursor-pointer hover:text-primary transition-colors">${this.formatNumber(post.comments_count)} commentaires</span>
                    </div>
                </div>
                <div class="px-5 py-2 flex justify-between border-t border-gray-100">
                    <button class="like-btn flex-1 flex items-center justify-center gap-2 py-2.5 hover:bg-gray-50 rounded-xl transition-colors ${post.user_liked ? 'text-red-500' : 'text-gray-600'}">
                        <i class="${likeClass} text-lg"></i>
                        <span class="font-semibold text-sm">J'aime</span>
                    </button>
                    <button class="comment-btn flex-1 flex items-center justify-center gap-2 py-2.5 hover:bg-gray-50 rounded-xl text-gray-600 transition-colors">
                        <i class="far fa-comment-alt text-lg"></i>
                        <span class="font-semibold text-sm">Commenter</span>
                    </button>
                    <button class="share-btn flex-1 flex items-center justify-center gap-2 py-2.5 hover:bg-gray-50 rounded-xl text-gray-600 transition-colors">
                        <i class="fas fa-share text-lg"></i>
                        <span class="font-semibold text-sm">Partager</span>
                    </button>
                </div>
                <div class="comments-section hidden px-5 py-3 bg-gray-50 border-t border-gray-100">
                    <div class="flex gap-3">
                        <img src="${App.config.siteUrl}uploads/avatars/${App.state.currentUser.avatar || 'default-avatar.png'}" class="w-8 h-8 rounded-full">
                        <div class="flex-1 relative">
                            <input type="text" placeholder="Écrire un commentaire..." class="comment-input w-full px-4 py-2 pr-12 rounded-full bg-white border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none text-sm">
                            <button class="submit-comment-btn absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center hover:bg-orange-600 transition-colors">
                                <i class="fas fa-paper-plane text-xs"></i>
                            </button>
                        </div>
                    </div>
                    <div class="comments-list mt-3 space-y-2"></div>
                </div>
            </article>
        `;
    },
    
    attachPostEvents: function(element, post) {
        // Like
        const likeBtn = element.querySelector('.like-btn');
        if (likeBtn) {
            likeBtn.addEventListener('click', () => this.toggleLike(post.idpost, likeBtn));
        }
        
        // Commentaire
        const commentBtn = element.querySelector('.comment-btn');
        const commentsSection = element.querySelector('.comments-section');
        if (commentBtn && commentsSection) {
            commentBtn.addEventListener('click', () => {
                commentsSection.classList.toggle('hidden');
                if (!commentsSection.classList.contains('hidden')) {
                    this.loadComments(post.idpost, element);
                }
            });
        }
        
        // Submit commentaire
        const submitBtn = element.querySelector('.submit-comment-btn');
        const commentInput = element.querySelector('.comment-input');
        if (submitBtn && commentInput) {
            submitBtn.addEventListener('click', () => {
                const content = commentInput.value.trim();
                if (content) {
                    this.submitComment(post.idpost, content, element);
                    commentInput.value = '';
                }
            });
            commentInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    const content = commentInput.value.trim();
                    if (content) {
                        this.submitComment(post.idpost, content, element);
                        commentInput.value = '';
                    }
                }
            });
        }
        
        // Partager
        const shareBtn = element.querySelector('.share-btn');
        if (shareBtn) {
            shareBtn.addEventListener('click', () => {
                App.copyToClipboard(`${window.location.origin}/index.php?post=${post.idpost}`);
            });
        }
        
        // Menu (edit/delete)
        const menuBtn = element.querySelector('.post-menu-btn');
        const menu = element.querySelector('.post-menu');
        if (menuBtn && menu) {
            menuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                menu.classList.toggle('hidden');
            });
            
            const editBtn = menu.querySelector('.edit-post-btn');
            if (editBtn) {
                editBtn.addEventListener('click', () => this.editPost(post.idpost, post.contenu));
            }
            
            const deleteBtn = menu.querySelector('.delete-post-btn');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', () => this.deletePost(post.idpost, element));
            }
            
            document.addEventListener('click', (e) => {
                if (!menuBtn.contains(e.target) && !menu.contains(e.target)) {
                    menu.classList.add('hidden');
                }
            });
        }
    },
    
    // ==================== ACTIONS SUR LES POSTS ====================
    toggleLike: async function(postId, btn) {
        try {
            const data = await App.ajax(`${App.config.apiBase}posts.php`, 'POST', {
                action: 'like',
                post_id: postId
            });
            
            if (data.success) {
                const icon = btn.querySelector('i');
                const countSpan = btn.closest('.post-card').querySelector('.likes-count');
                
                if (data.liked) {
                    btn.classList.add('text-red-500');
                    btn.classList.remove('text-gray-600');
                    icon.classList.remove('far');
                    icon.classList.add('fas');
                } else {
                    btn.classList.remove('text-red-500');
                    btn.classList.add('text-gray-600');
                    icon.classList.remove('fas');
                    icon.classList.add('far');
                }
                
                countSpan.textContent = this.formatNumber(data.likes_count);
            }
        } catch (error) {
            console.error('Error toggling like:', error);
        }
    },
    
    submitComment: async function(postId, content, postElement) {
        try {
            const data = await App.ajax(`${App.config.apiBase}posts.php`, 'POST', {
                action: 'comment',
                post_id: postId,
                content: content
            });
            
            if (data.success) {
                this.loadComments(postId, postElement);
                const commentsCount = postElement.querySelector('.comments-count');
                if (commentsCount) {
                    const currentCount = parseInt(commentsCount.textContent) || 0;
                    commentsCount.textContent = this.formatNumber(currentCount + 1);
                }
                App.showToast('Commentaire ajouté', 'success');
            }
        } catch (error) {
            console.error('Error submitting comment:', error);
        }
    },
    
    loadComments: async function(postId, postElement) {
        const commentsList = postElement.querySelector('.comments-list');
        if (!commentsList) return;
        
        commentsList.innerHTML = '<div class="text-center py-2"><div class="loading-spinner-sm w-6 h-6 border-2 border-orange-500 border-t-transparent rounded-full animate-spin mx-auto"></div></div>';
        
        try {
            const data = await App.ajax(`${App.config.apiBase}posts.php?action=single&id=${postId}`);
            
            if (data.success && data.comments) {
                if (data.comments.length === 0) {
                    commentsList.innerHTML = '<p class="text-center text-gray-400 text-sm py-2">Aucun commentaire</p>';
                    return;
                }
                
                commentsList.innerHTML = data.comments.map(comment => `
                    <div class="flex gap-2">
                        <img src="${comment.avatar ? App.config.siteUrl + 'uploads/avatars/' + comment.avatar : App.config.siteUrl + 'uploads/avatars/default-avatar.png'}" class="w-6 h-6 rounded-full">
                        <div class="flex-1">
                            <div class="bg-gray-100 rounded-lg px-3 py-2">
                                <a href="/pages/profil.php?id=${comment.id}" class="font-semibold text-sm hover:text-primary">${App.escapeHtml(comment.surnom)}</a>
                                <p class="text-sm text-gray-700">${App.escapeHtml(comment.textecommentaire)}</p>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">${App.formatDate(comment.datecommentaire)}</p>
                        </div>
                    </div>
                `).join('');
            }
        } catch (error) {
            console.error('Error loading comments:', error);
            commentsList.innerHTML = '<p class="text-center text-red-500 text-sm">Erreur de chargement</p>';
        }
    },
    
    createPost: async function(content, privacy, imageFile = null) {
        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('content', content);
        formData.append('privacy', privacy);
        if (imageFile) formData.append('image', imageFile);
        
        try {
            const data = await App.ajax(`${App.config.apiBase}posts.php`, 'POST', formData);
            
            if (data.success) {
                App.showToast('Publication créée !', 'success');
                this.closeCreateModal();
                this.loadFeed();
            }
        } catch (error) {
            console.error('Error creating post:', error);
        }
    },
    
    editPost: async function(postId, oldContent) {
        const newContent = prompt('Modifier votre publication:', oldContent);
        if (!newContent || newContent === oldContent) return;
        
        try {
            const data = await App.ajax(`${App.config.apiBase}posts.php`, 'PUT', {
                post_id: postId,
                content: newContent
            });
            
            if (data.success) {
                App.showToast('Publication modifiée', 'success');
                this.loadFeed();
            }
        } catch (error) {
            console.error('Error editing post:', error);
        }
    },
    
    deletePost: async function(postId, postElement) {
        if (!confirm('Voulez-vous vraiment supprimer cette publication ?')) return;
        
        try {
            const data = await App.ajax(`${App.config.apiBase}posts.php?post_id=${postId}`, 'DELETE');
            
            if (data.success) {
                postElement.style.animation = 'fadeOut 0.2s ease-out';
                setTimeout(() => postElement.remove(), 200);
                App.showToast('Publication supprimée', 'success');
            }
        } catch (error) {
            console.error('Error deleting post:', error);
        }
    },
    
    // ==================== MODAL DE CRÉATION ====================
    openCreateModal: function() {
        if (this.elements.createPostModal) {
            this.elements.createPostModal.classList.remove('hidden');
            this.elements.createPostModal.classList.add('flex');
            this.elements.createPostContent?.focus();
        }
    },
    
    closeCreateModal: function() {
        if (this.elements.createPostModal) {
            this.elements.createPostModal.classList.add('hidden');
            this.elements.createPostModal.classList.remove('flex');
            this.elements.createPostContent.value = '';
            this.removeImagePreview();
        }
    },
    
    previewImage: function(file) {
        if (!file) return;
        
        if (!App.config.allowedImageTypes.includes(file.type)) {
            App.showToast('Type de fichier non supporté', 'error');
            return;
        }
        
        if (file.size > App.config.maxFileSize) {
            App.showToast('Fichier trop volumineux (max 10MB)', 'error');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = (e) => {
            this.elements.imagePreview.innerHTML = `
                <div class="relative">
                    <img src="${e.target.result}" class="w-full h-48 object-cover rounded-xl">
                    <button class="remove-image-btn absolute top-2 right-2 w-8 h-8 bg-black/50 hover:bg-black/70 text-white rounded-full flex items-center justify-center">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            this.elements.imagePreview.classList.remove('hidden');
            
            const removeBtn = this.elements.imagePreview.querySelector('.remove-image-btn');
            removeBtn.addEventListener('click', () => this.removeImagePreview());
        };
        reader.readAsDataURL(file);
    },
    
    removeImagePreview: function() {
        this.elements.imagePreview.innerHTML = '';
        this.elements.imagePreview.classList.add('hidden');
        if (this.elements.createPostImage) {
            this.elements.createPostImage.value = '';
        }
    },
    
    autoResizeTextarea: function() {
        if (this.elements.createPostContent) {
            this.elements.createPostContent.style.height = 'auto';
            this.elements.createPostContent.style.height = Math.min(this.elements.createPostContent.scrollHeight, 200) + 'px';
        }
    },
    
    submitPost: function() {
        const content = this.elements.createPostContent?.value.trim();
        const privacy = this.elements.createPostPrivacy?.value || 'public';
        const imageFile = this.elements.createPostImage?.files[0];
        
        if (!content && !imageFile) {
            App.showToast('Veuillez ajouter du contenu', 'warning');
            return;
        }
        
        this.createPost(content, privacy, imageFile);
    },
    
    // ==================== UTILITAIRES ====================
    showSkeleton: function() {
        if (this.elements.container && this.elements.container.children.length === 0) {
            this.elements.container.innerHTML = this.getSkeletonHTML();
        }
    },
    
    hideSkeleton: function() {
        const skeleton = this.elements.container?.querySelector('.skeleton-card');
        if (skeleton) skeleton.remove();
    },
    
    getSkeletonHTML: function() {
        return `
            <div class="skeleton-card bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full skeleton"></div>
                    <div class="space-y-2 flex-1">
                        <div class="h-4 w-32 rounded skeleton"></div>
                        <div class="h-3 w-24 rounded skeleton"></div>
                    </div>
                </div>
                <div class="space-y-2">
                    <div class="h-4 w-full rounded skeleton"></div>
                    <div class="h-4 w-3/4 rounded skeleton"></div>
                </div>
                <div class="h-64 rounded-xl skeleton"></div>
            </div>
        `;
    },
    
    getEmptyStateHTML: function() {
        return `
            <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-newspaper text-4xl text-gray-300"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-700 mb-2">Aucune publication</h3>
                <p class="text-gray-500">Soyez le premier à partager quelque chose !</p>
                <button onclick="window.feed.openCreateModal()" class="mt-4 bg-orange-500 text-white px-4 py-2 rounded-lg hover:bg-orange-600 transition-colors">
                    Créer une publication
                </button>
            </div>
        `;
    },
    
    formatNumber: function(num) {
        if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1) + 'k';
        return num.toString();
    },
    
    toggleLoadMoreButton: function(hasMore) {
        if (this.elements.loadMoreBtn) {
            this.elements.loadMoreBtn.style.display = hasMore ? 'block' : 'none';
        }
        if (this.elements.loadMoreSpinner) {
            this.elements.loadMoreSpinner.classList.add('hidden');
        }
    }
};

// Initialisation
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('postsContainer')) {
        window.feed = Feed.init();
    }
});