/**
 * WideMaze - Messaging Module
 * Version 5.0 - Chat temps réel, fichiers, notes vocales
 */

const Messaging = {
    // État
    state: {
        conversations: [],
        currentConversation: null,
        messages: [],
        currentUserId: null,
        selectedUserId: null,
        isSelfChat: false,
        lastMessageId: 0,
        hasMoreMessages: true,
        isLoading: false,
        isTyping: false,
        typingTimeout: null,
        mediaRecorder: null,
        audioChunks: [],
        isRecording: false,
        recordingStartTime: null,
        selectedFile: null
    },
    
    // Éléments DOM
    elements: {},
    
    // Timers
    timers: {},
    
    // ==================== INITIALISATION ====================
    init: function() {
        console.log('💬 Messaging module initialized');
        
        this.state.currentUserId = window.currentUserId || null;
        this.state.selectedUserId = window.selectedUserId || null;
        this.state.isSelfChat = window.isSelfChat || false;
        
        this.cacheElements();
        this.initEventListeners();
        
        if (this.state.selectedUserId) {
            this.loadMessages();
            if (!this.state.isSelfChat) {
                this.startPolling();
                this.startTypingPolling();
            }
        }
        
        this.setupConversationClick();
        this.setupSearch();
        this.setupMobileNav();
        
        return this;
    },
    
    cacheElements: function() {
        this.elements = {
            conversationList: document.getElementById('conversationList'),
            chatArea: document.getElementById('chatArea'),
            messagesContainer: document.getElementById('messagesContainer'),
            messageInput: document.getElementById('messageInput'),
            sendBtn: document.getElementById('sendBtn'),
            typingIndicator: document.getElementById('typingIndicator'),
            fileInput: document.getElementById('fileInput'),
            fileMenu: document.getElementById('fileMenu'),
            filePreview: document.getElementById('filePreview'),
            fileName: document.getElementById('fileName'),
            fileSize: document.getElementById('fileSize'),
            voiceRecordBtn: document.getElementById('voiceRecordBtn'),
            voicePreview: document.getElementById('voicePreview'),
            recordingTime: document.getElementById('recordingTime'),
            recordingIndicator: document.getElementById('recordingIndicator'),
            emojiPicker: document.getElementById('emojiPicker'),
            searchConversations: document.getElementById('searchConversations'),
            mobileMenuBtn: document.getElementById('mobileMenuBtn'),
            mobileBackBtn: document.getElementById('mobileBackBtn')
        };
    },
    
    initEventListeners: function() {
        // Envoi de message
        if (this.elements.sendBtn) {
            this.elements.sendBtn.addEventListener('click', () => this.sendMessage());
        }
        
        if (this.elements.messageInput) {
            this.elements.messageInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                } else {
                    this.onTyping();
                }
            });
            this.elements.messageInput.addEventListener('input', () => this.autoResizeTextarea());
        }
        
        // Upload de fichiers
        if (this.elements.fileInput) {
            this.elements.fileInput.addEventListener('change', (e) => this.handleFileSelect(e));
        }
        
        // Enregistrement vocal
        if (this.elements.voiceRecordBtn) {
            this.elements.voiceRecordBtn.addEventListener('click', () => this.toggleVoiceRecording());
        }
        
        // Émojis
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#emojiPicker') && !e.target.closest('.fa-smile')) {
                this.elements.emojiPicker?.classList.add('hidden');
            }
            if (!e.target.closest('#fileMenu') && !e.target.closest('#fileMenuBtn')) {
                this.elements.fileMenu?.classList.add('hidden');
            }
        });
    },
    
    // ==================== CHARGEMENT DES MESSAGES ====================
    loadMessages: async function(before = 0) {
        if (this.state.isLoading) return;
        this.state.isLoading = true;
        
        try {
            const url = `${App.config.apiBase}messages.php?action=messages&with=${this.state.selectedUserId}&before=${before}&limit=20`;
            const data = await App.ajax(url);
            
            if (data.success) {
                if (before === 0) {
                    this.state.messages = [];
                    this.state.lastMessageId = 0;
                    this.elements.messagesContainer.innerHTML = '';
                }
                
                data.messages.forEach(msg => {
                    this.appendMessage(msg, before === 0);
                    if (msg.id > this.state.lastMessageId) {
                        this.state.lastMessageId = msg.id;
                    }
                });
                
                this.state.hasMoreMessages = data.has_more;
                
                if (before === 0) {
                    this.scrollToBottom();
                }
                
                if (this.state.hasMoreMessages && before === 0) {
                    this.addLoadMoreTrigger();
                }
            }
        } catch (error) {
            console.error('Error loading messages:', error);
            this.showError('Erreur de chargement des messages');
        } finally {
            this.state.isLoading = false;
        }
    },
    
    appendMessage: function(msg, isNew = true) {
        const isMine = msg.id_expediteur == this.state.currentUserId;
        const messageHtml = this.createMessageHTML(msg, isMine);
        
        if (isNew) {
            this.elements.messagesContainer.insertAdjacentHTML('beforeend', messageHtml);
        } else {
            this.elements.messagesContainer.insertAdjacentHTML('afterbegin', messageHtml);
        }
    },
    
    createMessageHTML: function(msg, isMine) {
        const time = new Date(msg.datemessage).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        const bubbleClass = isMine ? (this.state.isSelfChat ? 'message-self' : 'message-sent') : 'message-received';
        
        let fileHtml = '';
        if (msg.file_url) {
            if (msg.type === 'voice') {
                fileHtml = `
                    <div class="audio-player mt-2 flex items-center gap-2 p-2 bg-white/20 rounded-lg">
                        <i class="fas fa-microphone-alt"></i>
                        <audio controls class="h-8" preload="none">
                            <source src="${msg.file_url}" type="audio/webm">
                        </audio>
                        <span class="text-xs font-mono">${this.formatDuration(msg.file_duration)}</span>
                    </div>
                `;
            } else if (msg.type === 'image') {
                fileHtml = `
                    <div class="mt-2 cursor-pointer" onclick="window.open('${msg.file_url}', '_blank')">
                        <img src="${msg.file_url}" class="max-w-full max-h-64 rounded-lg shadow-md">
                    </div>
                `;
            } else {
                const fileIcon = msg.file_url?.match(/\.(pdf)$/i) ? 'fa-file-pdf' : 'fa-file';
                fileHtml = `
                    <a href="${msg.file_url}" download class="file-card block mt-2 p-3 ${isMine ? 'bg-white/20' : 'bg-gray-100'} rounded-lg hover:opacity-90">
                        <div class="flex items-center gap-3">
                            <i class="fas ${fileIcon} text-2xl"></i>
                            <div class="flex-1">
                                <p class="text-sm font-medium truncate">${App.escapeHtml(msg.file_name)}</p>
                                <p class="text-xs opacity-75">${App.formatFileSize(msg.file_size)}</p>
                            </div>
                            <i class="fas fa-download text-sm"></i>
                        </div>
                    </a>
                `;
            }
        }
        
        return `
            <div class="message-item flex ${isMine ? 'justify-end' : 'justify-start'} animate-fade-in-up" data-message-id="${msg.idmessage}" oncontextmenu="window.messaging.showContextMenu(event, ${msg.idmessage}, ${isMine})">
                ${!isMine && !this.state.isSelfChat ? `
                    <img src="${App.config.siteUrl}uploads/avatars/${msg.avatar || 'default-avatar.png'}" class="w-8 h-8 rounded-full mr-2 self-end">
                ` : ''}
                <div class="message-bubble max-w-[75%] ${bubbleClass} p-3 shadow-sm">
                    ${msg.textemessage ? `<p class="text-sm break-words">${App.escapeHtml(msg.textemessage)}</p>` : ''}
                    ${fileHtml}
                    <p class="text-xs opacity-75 mt-1 flex items-center gap-1">
                        <i class="far fa-clock"></i>${time}
                        ${isMine ? '<i class="fas fa-check-double text-[10px] ml-1"></i>' : ''}
                    </p>
                </div>
            </div>
        `;
    },
    
    addLoadMoreTrigger: function() {
        const trigger = document.createElement('div');
        trigger.id = 'loadMoreTrigger';
        trigger.className = 'text-center py-3 cursor-pointer hover:bg-gray-100 rounded-lg transition-colors';
        trigger.innerHTML = `
            <i class="fas fa-chevron-up text-gray-400"></i>
            <span class="text-xs text-gray-500 ml-2">Charger plus de messages</span>
        `;
        trigger.onclick = () => {
            const firstMsg = document.querySelector('.message-item:first-child');
            const firstId = firstMsg?.dataset?.messageId;
            if (firstId) this.loadMessages(parseInt(firstId));
            trigger.remove();
        };
        this.elements.messagesContainer.insertBefore(trigger, this.elements.messagesContainer.firstChild);
    },
    
    // ==================== ENVOI DE MESSAGES ====================
    sendMessage: async function() {
        const message = this.elements.messageInput?.value.trim();
        if (!message && !this.state.selectedFile && !this.state.isRecording) return;
        
        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('to', this.state.selectedUserId);
        formData.append('message', message || '');
        if (this.state.selectedFile) formData.append('file', this.state.selectedFile);
        
        this.setSendButtonLoading(true);
        
        try {
            const data = await App.ajax(`${App.config.apiBase}messages.php`, 'POST', formData);
            
            if (data.success) {
                this.elements.messageInput.value = '';
                this.autoResizeTextarea();
                this.appendMessage(data.message, true);
                this.scrollToBottom();
                this.state.lastMessageId = data.message_id;
                this.cancelFile();
            } else {
                this.showError(data.error || 'Erreur d\'envoi');
            }
        } catch (error) {
            console.error('Error sending message:', error);
            this.showError('Erreur de connexion');
        } finally {
            this.setSendButtonLoading(false);
        }
    },
    
    setSendButtonLoading: function(loading) {
        if (this.elements.sendBtn) {
            this.elements.sendBtn.disabled = loading;
            if (loading) {
                this.elements.sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            } else {
                this.elements.sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
            }
        }
    },
    
    // ==================== INDICATEUR DE FRAPPE ====================
    onTyping: function() {
        if (this.state.isSelfChat || this.state.isTyping) return;
        
        this.state.isTyping = true;
        
        App.ajax(`${App.config.apiBase}messages.php`, 'POST', {
            action: 'typing',
            to: this.state.selectedUserId
        }).catch(console.error);
        
        if (this.state.typingTimeout) clearTimeout(this.state.typingTimeout);
        this.state.typingTimeout = setTimeout(() => {
            this.state.isTyping = false;
        }, 2000);
    },
    
    startTypingPolling: function() {
        this.timers.typing = setInterval(async () => {
            if (!this.state.selectedUserId || this.state.isSelfChat) return;
            
            try {
                const data = await App.ajax(`${App.config.apiBase}messages.php?action=check_typing&from=${this.state.selectedUserId}`);
                if (this.elements.typingIndicator) {
                    if (data.typing) {
                        this.elements.typingIndicator.classList.remove('hidden');
                    } else {
                        this.elements.typingIndicator.classList.add('hidden');
                    }
                }
            } catch (error) {
                console.error('Error checking typing:', error);
            }
        }, 2000);
    },
    
    // ==================== POLLING MESSAGES ====================
    startPolling: function() {
        this.timers.messages = setInterval(() => {
            if (this.state.selectedUserId && !this.state.isSelfChat) {
                this.checkNewMessages();
            }
        }, 3000);
    },
    
    checkNewMessages: async function() {
        try {
            const data = await App.ajax(`${App.config.apiBase}messages.php?action=messages&with=${this.state.selectedUserId}&limit=1`);
            if (data.success && data.messages.length > 0) {
                const latestMsg = data.messages[data.messages.length - 1];
                if (latestMsg.id > this.state.lastMessageId && latestMsg.id_expediteur != this.state.currentUserId) {
                    this.loadMessages();
                }
            }
        } catch (error) {
            console.error('Error checking new messages:', error);
        }
    },
    
    // ==================== GESTION DES FICHIERS ====================
    handleFileSelect: function(event) {
        const file = event.target.files[0];
        if (!file) return;
        
        if (file.size > 50 * 1024 * 1024) {
            this.showError('Fichier trop volumineux (max 50MB)');
            return;
        }
        
        this.state.selectedFile = file;
        
        if (this.elements.fileName) this.elements.fileName.textContent = file.name;
        if (this.elements.fileSize) this.elements.fileSize.textContent = App.formatFileSize(file.size);
        if (this.elements.filePreview) this.elements.filePreview.classList.remove('hidden');
    },
    
    cancelFile: function() {
        this.state.selectedFile = null;
        if (this.elements.fileInput) this.elements.fileInput.value = '';
        if (this.elements.filePreview) this.elements.filePreview.classList.add('hidden');
    },
    
    // ==================== ENREGISTREMENT VOCAL ====================
    toggleVoiceRecording: async function() {
        if (this.state.isRecording) {
            this.stopRecording();
        } else {
            await this.startRecording();
        }
    },
    
    startRecording: async function() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.state.mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
            this.state.audioChunks = [];
            
            this.state.mediaRecorder.ondataavailable = (event) => {
                this.state.audioChunks.push(event.data);
            };
            
            this.state.mediaRecorder.onstop = async () => {
                const audioBlob = new Blob(this.state.audioChunks, { type: 'audio/webm' });
                const reader = new FileReader();
                reader.onloadend = async () => {
                    const base64Audio = reader.result;
                    const duration = Math.floor((Date.now() - this.state.recordingStartTime) / 1000);
                    
                    const formData = new FormData();
                    formData.append('action', 'send');
                    formData.append('to', this.state.selectedUserId);
                    formData.append('message', '');
                    formData.append('voice_data', base64Audio);
                    formData.append('voice_duration', duration);
                    
                    this.setSendButtonLoading(true);
                    
                    try {
                        const data = await App.ajax(`${App.config.apiBase}messages.php`, 'POST', formData);
                        if (data.success) {
                            this.appendMessage(data.message, true);
                            this.scrollToBottom();
                            this.state.lastMessageId = data.message_id;
                        }
                    } catch (error) {
                        this.showError('Erreur d\'envoi de la note vocale');
                    } finally {
                        this.setSendButtonLoading(false);
                    }
                };
                reader.readAsDataURL(audioBlob);
                stream.getTracks().forEach(track => track.stop());
            };
            
            this.state.mediaRecorder.start();
            this.state.isRecording = true;
            this.state.recordingStartTime = Date.now();
            
            if (this.elements.voiceRecordBtn) {
                this.elements.voiceRecordBtn.innerHTML = '<i class="fas fa-stop text-red-500"></i><span class="text-xs ml-1">Arrêter</span>';
            }
            if (this.elements.recordingIndicator) this.elements.recordingIndicator.classList.remove('hidden');
            if (this.elements.voicePreview) this.elements.voicePreview.classList.remove('hidden');
            
            this.state.recordingTimer = setInterval(() => this.updateRecordingTime(), 1000);
            
        } catch (error) {
            console.error('Error accessing microphone:', error);
            this.showError('Impossible d\'accéder au microphone');
        }
    },
    
    stopRecording: function() {
        if (this.state.mediaRecorder && this.state.isRecording) {
            this.state.mediaRecorder.stop();
            this.state.isRecording = false;
            
            if (this.elements.voiceRecordBtn) {
                this.elements.voiceRecordBtn.innerHTML = '<i class="fas fa-microphone"></i><span class="text-xs ml-1">Note vocale</span>';
            }
            if (this.elements.recordingIndicator) this.elements.recordingIndicator.classList.add('hidden');
            if (this.elements.voicePreview) this.elements.voicePreview.classList.add('hidden');
            
            if (this.state.recordingTimer) clearInterval(this.state.recordingTimer);
        }
    },
    
    updateRecordingTime: function() {
        const elapsed = Math.floor((Date.now() - this.state.recordingStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        if (this.elements.recordingTime) {
            this.elements.recordingTime.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }
    },
    
    // ==================== ÉMOJIS ====================
    addEmoji: function(emoji) {
        if (this.elements.messageInput) {
            this.elements.messageInput.value += emoji;
            this.elements.messageInput.focus();
            this.autoResizeTextarea();
        }
        if (this.elements.emojiPicker) this.elements.emojiPicker.classList.add('hidden');
    },
    
    toggleEmojiPicker: function() {
        if (this.elements.emojiPicker) {
            this.elements.emojiPicker.classList.toggle('hidden');
        }
    },
    
    // ==================== CONTEXT MENU ====================
    showContextMenu: function(event, messageId, isMine) {
        event.preventDefault();
        
        const existingMenu = document.getElementById('dynamicContextMenu');
        if (existingMenu) existingMenu.remove();
        
        const menu = document.createElement('div');
        menu.id = 'dynamicContextMenu';
        menu.className = 'context-menu fixed bg-white rounded-xl shadow-xl border border-gray-200 z-50 min-w-[200px] overflow-hidden';
        menu.style.left = event.pageX + 'px';
        menu.style.top = event.pageY + 'px';
        
        menu.innerHTML = `
            <div class="context-menu-item px-4 py-2.5 hover:bg-gray-50 cursor-pointer flex items-center gap-3" onclick="window.messaging.deleteMessage(${messageId}, 'me')">
                <i class="fas fa-trash-alt text-gray-400 w-4"></i>
                <span class="text-sm">Supprimer pour moi</span>
            </div>
            ${isMine ? `
                <div class="context-menu-item px-4 py-2.5 hover:bg-red-50 cursor-pointer flex items-center gap-3 border-t border-gray-100" onclick="window.messaging.deleteMessage(${messageId}, 'everyone')">
                    <i class="fas fa-trash-alt text-red-500 w-4"></i>
                    <span class="text-sm text-red-600">Supprimer pour tout le monde</span>
                </div>
            ` : ''}
        `;
        
        document.body.appendChild(menu);
        
        setTimeout(() => {
            document.addEventListener('click', function closeMenu(e) {
                if (!menu.contains(e.target)) {
                    menu.remove();
                    document.removeEventListener('click', closeMenu);
                }
            });
        }, 0);
    },
    
    deleteMessage: async function(messageId, deleteFor) {
        try {
            const data = await App.ajax(`${App.config.apiBase}messages.php`, 'DELETE', {
                message_id: messageId,
                delete_for: deleteFor
            });
            
            if (data.success) {
                const msgDiv = document.querySelector(`[data-message-id="${messageId}"]`);
                if (msgDiv) {
                    msgDiv.style.animation = 'fadeOut 0.2s ease-out';
                    setTimeout(() => msgDiv.remove(), 200);
                }
                App.showToast('Message supprimé', 'success');
            }
        } catch (error) {
            console.error('Error deleting message:', error);
            this.showError('Erreur lors de la suppression');
        }
    },
    
    // ==================== CONVERSATIONS ====================
    setupConversationClick: function() {
        document.querySelectorAll('.conversation-item').forEach(item => {
            item.addEventListener('click', () => {
                const userId = item.dataset.userId;
                window.location.href = `?user=${userId}`;
            });
        });
    },
    
    setupSearch: function() {
        if (this.elements.searchConversations) {
            this.elements.searchConversations.addEventListener('input', (e) => {
                const query = e.target.value.toLowerCase();
                document.querySelectorAll('.conversation-item').forEach(item => {
                    const name = item.querySelector('.font-semibold')?.textContent.toLowerCase() || '';
                    item.style.display = name.includes(query) ? 'flex' : 'none';
                });
            });
        }
    },
    
    setupMobileNav: function() {
        if (this.elements.mobileMenuBtn) {
            this.elements.mobileMenuBtn.addEventListener('click', () => {
                this.elements.conversationList?.classList.remove('hidden-mobile');
                this.elements.chatArea?.classList.add('hidden-mobile');
            });
        }
        
        if (this.elements.mobileBackBtn) {
            this.elements.mobileBackBtn.addEventListener('click', () => {
                this.elements.conversationList?.classList.remove('hidden-mobile');
                this.elements.chatArea?.classList.add('hidden-mobile');
            });
        }
        
        if (this.state.selectedUserId) {
            this.elements.conversationList?.classList.add('hidden-mobile');
            this.elements.chatArea?.classList.remove('hidden-mobile');
        }
    },
    
    // ==================== UTILITAIRES ====================
    autoResizeTextarea: function() {
        if (this.elements.messageInput) {
            this.elements.messageInput.style.height = 'auto';
            this.elements.messageInput.style.height = Math.min(this.elements.messageInput.scrollHeight, 120) + 'px';
        }
    },
    
    scrollToBottom: function() {
        if (this.elements.messagesContainer) {
            this.elements.messagesContainer.scrollTop = this.elements.messagesContainer.scrollHeight;
        }
    },
    
    formatDuration: function(seconds) {
        if (!seconds) return '0:00';
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    },
    
    showError: function(message) {
        App.showToast(message, 'error');
    }
};

// Initialisation
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('messagesContainer')) {
        window.messaging = Messaging.init();
    }
});