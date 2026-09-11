// Faheem Chat - frontend logic (vanilla JS, AJAX polling, no websockets)

(() => {
    const appEl = document.getElementById('app');
    const AUTH_ID = parseInt(appEl.dataset.authId, 10);
    const AUTH_NAME = appEl.dataset.authName;
    const IS_ADMIN = appEl.dataset.isAdmin === '1';
    const INITIAL_CONVERSATION_ID = appEl.dataset.initialConversation || null;
    const MAX_UPLOAD_BYTES = 500 * 1024 * 1024;

    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
    let deferredInstallPrompt = null;

    const state = {
        conversations: [],
        currentConversationId: null,
        lastMessageId: 0,
        renderedMessageIds: new Set(),
        isSending: false,
        selectedFile: null,
        newChatMode: 'private',
        selectedUserIds: new Set(),
        selectedUsersMap: new Map(),
        inviteFromNewChat: false,
        notificationCount: 0,
        audioContext: null,
        timers: { conversations: null, messages: null, typing: null, heartbeat: null },
        lastTypingSentAt: 0,
    };

    // ---------- tiny helpers ----------

    const $ = (id) => document.getElementById(id);

    const escapeHtml = (str) => {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const initials = (name) => (name || '?').trim().charAt(0).toUpperCase();

    const timeAgo = (iso) => {
        if (!iso) return '';
        const diff = (Date.now() - new Date(iso).getTime()) / 1000;
        if (diff < 60) return 'now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        return Math.floor(diff / 86400) + 'd';
    };

    async function api(url, options = {}) {
        const isForm = options.body instanceof FormData;
        const headers = Object.assign(
            {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest',
            },
            isForm ? {} : { 'Content-Type': 'application/json' },
            options.headers || {}
        );

        const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, options, { headers }));

        if (res.status === 401) {
            window.location.href = '/login';
            return null;
        }

        if (!res.ok) {
            const body = await res.json().catch(() => ({}));
            throw new Error(body.message || `Request failed (${res.status})`);
        }

        return res.status === 204 ? null : res.json();
    }

    const apiGet = (url) => api(url);
    const apiPost = (url, data) => api(url, {
        method: 'POST',
        body: data instanceof FormData ? data : JSON.stringify(data || {}),
    });
    const apiDelete = (url) => api(url, { method: 'DELETE' });

    function isIosDevice() {
        return /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    }

    function showInstallButton() {
        const button = $('install-app-btn');
        if (button) button.classList.remove('hidden');
    }

    async function installApp() {
        if (deferredInstallPrompt) {
            deferredInstallPrompt.prompt();
            await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;
            $('install-app-btn').classList.add('hidden');
            return;
        }

        if (isIosDevice()) {
            alert('Safari mein Share button dabayen, phir Add to Home Screen select karein.');
            return;
        }

        alert('Browser menu kholen aur “Install app” ya “Add to Home screen” select karein.');
    }

    function updateNotificationTitle() {
        document.title = state.notificationCount > 0
            ? `(${state.notificationCount}) Faheem Innovations`
            : 'Faheem Innovations';
    }

    function playNotificationSound() {
        if (!state.audioContext) return;
        const oscillator = state.audioContext.createOscillator();
        const gain = state.audioContext.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(740, state.audioContext.currentTime);
        oscillator.frequency.exponentialRampToValueAtTime(520, state.audioContext.currentTime + 0.12);
        gain.gain.setValueAtTime(0.0001, state.audioContext.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.08, state.audioContext.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, state.audioContext.currentTime + 0.18);
        oscillator.connect(gain);
        gain.connect(state.audioContext.destination);
        oscillator.start();
        oscillator.stop(state.audioContext.currentTime + 0.2);
    }

    function notifyNewMessage(message) {
        if (message.sender_id === AUTH_ID || (message.sender && message.sender.id === AUTH_ID)) return;

        state.notificationCount += 1;
        updateNotificationTitle();
        playNotificationSound();

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            const sender = message.sender?.name || 'New message';
            const content = message.body || (message.type === 'image' ? '📷 Photo' : '📎 File');
            const notification = new Notification(sender, {
                body: content,
                icon: '/images/faheem-innovations-logo.svg',
                tag: `faheem-message-${message.id}`,
            });
            notification.onclick = () => {
                window.focus();
                notification.close();
            };
        }
    }

    async function enableNotifications() {
        if (typeof Notification === 'undefined') {
            alert('Browser notifications are not supported here.');
            return;
        }

        if (Notification.permission === 'denied') {
            alert('Notifications are blocked in your browser settings.');
            return;
        }

        const permission = await Notification.requestPermission();
        if (permission === 'granted') {
            $('notifications-btn').classList.add('notifications-enabled');
            $('notifications-btn').title = 'Message notifications enabled';
            state.audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
    }

    function uploadMessage(url, formData) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const progress = $('upload-progress');
            const progressBar = $('upload-progress-bar');
            const progressPercent = $('upload-progress-percent');

            progress.classList.remove('hidden');
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';

            xhr.open('POST', url, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', CSRF_TOKEN);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.addEventListener('progress', (event) => {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                progressBar.style.width = `${percent}%`;
                progressPercent.textContent = `${percent}%`;
            });

            xhr.addEventListener('load', () => {
                let body = {};
                try { body = xhr.responseText ? JSON.parse(xhr.responseText) : {}; } catch (e) { /* invalid response */ }

                if (xhr.status === 401) {
                    window.location.href = '/login';
                    return reject(new Error('Your session has expired.'));
                }
                if (xhr.status < 200 || xhr.status >= 300) {
                    return reject(new Error(body.message || `Upload failed (${xhr.status})`));
                }
                resolve(body);
            });

            xhr.addEventListener('error', () => reject(new Error('Upload failed. Please try again.')));
            xhr.addEventListener('abort', () => reject(new Error('Upload cancelled.')));
            xhr.send(formData);
        });
    }

    // ---------- conversations sidebar ----------

    async function loadConversations() {
        try {
            const list = await apiGet('/app/conversations');
            state.conversations = list;
            renderConversationList();
        } catch (e) {
            console.error(e);
        }
    }

    function renderConversationList() {
        const container = $('conversation-list');
        const savedMessages = container.querySelector('#saved-messages-btn');
        const savedMessagesHtml = savedMessages ? savedMessages.outerHTML : '';

        if (!state.conversations.length) {
            container.innerHTML = savedMessagesHtml + `<div class="p-6 text-center text-sm text-gray-400">No chats yet. Tap "+" to start one.</div>`;
            bindSavedMessagesButton();
            return;
        }

        container.innerHTML = savedMessagesHtml + state.conversations.map((c) => {
            const active = c.id === state.currentConversationId ? 'active' : '';
            const isOnline = c.type === 'private' && c.other_user && c.other_user.is_online;
            const preview = c.last_message
                ? (c.last_message.sender_id === AUTH_ID ? 'You: ' : '') + escapeHtml(truncate(c.last_message.body || attachmentLabel(c.last_message), 40))
                : 'No messages yet';

            return `
                <div class="conversation-item ${active}" data-id="${c.id}">
                    <div class="relative">
                        <div class="avatar-circle w-11 h-11 text-sm">${escapeHtml(initials(c.title))}</div>
                        ${c.type === 'private' ? `<span class="absolute -bottom-0.5 -right-0.5 ${isOnline ? 'online-dot' : 'offline-dot'}"></span>` : ''}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-gray-800 truncate">${escapeHtml(c.title)}</p>
                            <span class="text-xs text-gray-400 shrink-0 ml-2">${timeAgo(c.last_activity_at)}</span>
                        </div>
                        <div class="flex items-center justify-between mt-0.5">
                            <p class="text-xs text-gray-500 truncate">${preview}</p>
                            ${c.unread_count > 0 ? `<span class="unread-badge shrink-0 ml-2">${c.unread_count}</span>` : ''}
                        </div>
                    </div>
                </div>`;
        }).join('');

        container.querySelectorAll('.conversation-item').forEach((el) => {
            el.addEventListener('click', () => openConversation(parseInt(el.dataset.id, 10)));
        });
        bindSavedMessagesButton();
    }

    function bindSavedMessagesButton() {
        const button = $('saved-messages-btn');
        if (button) button.addEventListener('click', openSavedMessages);
    }

    async function openSavedMessages() {
        try {
            const conv = await apiPost('/app/conversations', { type: 'private', user_id: AUTH_ID });
            await loadConversations();
            openConversation(conv.id);
        } catch (e) {
            alert(e.message);
        }
    }

    function truncate(str, len) {
        str = str || '';
        return str.length > len ? str.slice(0, len) + '…' : str;
    }

    function attachmentLabel(msg) {
        if (msg.type === 'image') return '📷 Photo';
        if (msg.type === 'file') return '📎 File';
        return '';
    }

    function findConversation(id) {
        return state.conversations.find((c) => c.id === id);
    }

    // ---------- opening a conversation ----------

    async function openConversation(id) {
        id = Number(id);
        if (!Number.isInteger(id) || id < 1) return;

        state.currentConversationId = id;
        state.lastMessageId = 0;
        state.renderedMessageIds = new Set();
        appEl.classList.add('chat-open');

        $('empty-state').classList.add('hidden');
        $('chat-panel').classList.remove('hidden');
        $('messages-container').innerHTML = `<div class="text-center text-sm text-gray-400 py-6">Loading messages…</div>`;

        renderConversationList();
        renderChatHeader(findConversation(id));

        clearInterval(state.timers.messages);
        clearInterval(state.timers.typing);

        await loadInitialMessages(id);
        await markRead(id);

        state.timers.messages = setInterval(() => pollNewMessages(id), 2500);
        state.timers.typing = setInterval(() => pollTyping(id), 2000);

        // update the URL without a full page reload
        window.history.replaceState({}, '', `/chat/${id}`);
    }

    function renderChatHeader(conv) {
        const header = $('chat-header');
        if (!conv) {
            header.innerHTML = '';
            return;
        }
        const isOnline = conv.type === 'private' && conv.other_user && conv.other_user.is_online;
        const subtitle = conv.is_self
            ? 'Only you'
            : conv.type === 'group'
            ? `${conv.participants.length} members`
            : (isOnline ? 'Online' : 'Offline');

        header.innerHTML = `
            <button id="chat-back-btn" type="button" class="chat-back-btn" title="Back to chats" aria-label="Back to chats">&#8592;</button>
            <div class="avatar-circle w-9 h-9 text-sm">${escapeHtml(initials(conv.title))}</div>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-800 truncate">${escapeHtml(conv.title)}</p>
                <p class="text-xs ${isOnline ? 'text-green-600' : 'text-gray-400'}">${escapeHtml(subtitle)}</p>
            </div>`;
        $('chat-back-btn').addEventListener('click', () => {
            appEl.classList.remove('chat-open');
        });
    }

    // ---------- messages ----------

    async function loadInitialMessages(id) {
        try {
            const data = await apiGet(`/app/conversations/${id}/messages`);
            const container = $('messages-container');
            container.innerHTML = '';
            data.messages.forEach((m) => appendMessage(m, false));
            if (data.messages.length) {
                state.lastMessageId = data.messages[data.messages.length - 1].id;
            }
            scrollToBottom();
        } catch (e) {
            console.error(e);
        }
    }

    async function pollNewMessages(id) {
        if (id !== state.currentConversationId) return;
        try {
            const data = await apiGet(`/app/conversations/${id}/messages?after_id=${state.lastMessageId}`);
            if (data.messages.length) {
                data.messages.forEach((m) => {
                    const alreadyRendered = m.id && state.renderedMessageIds.has(m.id);
                    appendMessage(m, true);
                    if (!alreadyRendered && (document.hidden || !document.hasFocus())) notifyNewMessage(m);
                });
                state.lastMessageId = data.messages[data.messages.length - 1].id;
                markRead(id);
                loadConversations(); // refresh sidebar preview/unread counts
            }
        } catch (e) {
            console.error(e);
        }
    }

    function appendMessage(msg, scroll) {
        const container = $('messages-container');
        if (msg.id && state.renderedMessageIds.has(msg.id)) return;
        if (msg.id) state.renderedMessageIds.add(msg.id);

        const mine = msg.sender ? msg.sender.id === AUTH_ID : msg.user_id === AUTH_ID;
        const senderName = msg.sender ? msg.sender.name : '';
        const previous = container.lastElementChild;
        const previousMine = previous && previous.dataset.senderId === String(msg.sender ? msg.sender.id : msg.user_id);

        let attachmentHtml = '';
        (msg.attachments || []).forEach((att) => {
            if (att.file_type && att.file_type.startsWith('image/')) {
                attachmentHtml += `<a href="${att.url}" download="${escapeHtml(att.file_name)}" class="attachment-image" title="Download ${escapeHtml(att.file_name)}"><img src="${att.url}" alt="${escapeHtml(att.file_name)}" loading="lazy"></a>`;
            } else {
                attachmentHtml += `<a href="${att.url}" download="${escapeHtml(att.file_name)}" class="attachment-file"><span class="attachment-file-icon">${att.file_name.toLowerCase().endsWith('.zip') ? 'ZIP' : 'FILE'}</span><span class="attachment-file-name">${escapeHtml(att.file_name)}</span><span class="attachment-download">Download</span></a>`;
            }
        });

        const wrap = document.createElement('div');
        wrap.dataset.senderId = String(msg.sender ? msg.sender.id : msg.user_id);
        wrap.className = `message-row flex flex-col ${mine ? 'items-end' : 'items-start'} ${previousMine ? 'message-row-grouped' : ''}`;
        wrap.innerHTML = `
            ${!mine && !previousMine ? `<span class="message-sender">${escapeHtml(senderName)}</span>` : ''}
            <div class="msg-bubble ${mine ? 'mine' : 'theirs'}">
                ${msg.body ? escapeHtml(msg.body) : ''}
                ${attachmentHtml}
            </div>
            <span class="text-[10px] text-gray-400 mt-0.5 ${mine ? 'mr-1' : 'ml-1'}">${timeAgo(msg.created_at)}</span>`;

        container.appendChild(wrap);
        if (scroll) scrollToBottom();
    }

    function scrollToBottom() {
        const container = $('messages-container');
        container.scrollTop = container.scrollHeight;
    }

    async function markRead(id) {
        try {
            await apiPost(`/app/conversations/${id}/messages/read`);
        } catch (e) { /* non-critical */ }
    }

    // ---------- sending messages ----------

    $('message-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = state.currentConversationId;
        if (!id || state.isSending) return;

        const input = $('message-input');
        const body = input.value.trim();
        if (!body && !state.selectedFile) return;

        const formData = new FormData();
        if (body) formData.append('body', body);
        if (state.selectedFile) formData.append('file', state.selectedFile);

        state.isSending = true;
        $('message-form').classList.add('is-sending');
        $('send-btn').disabled = true;
        $('attach-btn').disabled = Boolean(state.selectedFile);
        $('upload-progress-label').textContent = state.selectedFile ? `Uploading ${state.selectedFile.name}` : 'Sending message';
        try {
            const msg = state.selectedFile
                ? await uploadMessage(`/app/conversations/${id}/messages`, formData)
                : await apiPost(`/app/conversations/${id}/messages`, formData);
            appendMessage(msg, true);
            state.lastMessageId = Math.max(state.lastMessageId, msg.id);
            input.value = '';
            clearFilePreview();
            loadConversations();
        } catch (err) {
            alert(err.message);
        } finally {
            $('upload-progress').classList.add('hidden');
            state.isSending = false;
            $('message-form').classList.remove('is-sending');
            $('send-btn').disabled = false;
            $('attach-btn').disabled = false;
        }
    });

    $('message-input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $('message-form').requestSubmit();
        }
    });

    $('message-input').addEventListener('input', () => {
        const now = Date.now();
        if (state.currentConversationId && now - state.lastTypingSentAt > 1500) {
            state.lastTypingSentAt = now;
            apiPost(`/app/conversations/${state.currentConversationId}/typing`).catch(() => {});
        }
    });

    $('notifications-btn').addEventListener('click', enableNotifications);
    $('install-app-btn').addEventListener('click', installApp);
    window.addEventListener('focus', () => {
        state.notificationCount = 0;
        updateNotificationTitle();
    });

    // ---------- attachments ----------

    $('attach-btn').addEventListener('click', () => $('file-input').click());

    $('file-input').addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;
        if (file.size > MAX_UPLOAD_BYTES) {
            alert('You can upload a file up to 500 MB.');
            clearFilePreview();
            return;
        }
        state.selectedFile = file;
        const preview = $('file-preview');
        preview.textContent = `📎 ${file.name} ✕`;
        $('upload-progress-label').textContent = `Uploading ${file.name}`;
        preview.classList.remove('hidden');
        preview.onclick = clearFilePreview;
    });

    function clearFilePreview() {
        state.selectedFile = null;
        $('file-input').value = '';
        const preview = $('file-preview');
        preview.classList.add('hidden');
        preview.textContent = '';
    }

    // ---------- typing indicator ----------

    async function pollTyping(id) {
        if (id !== state.currentConversationId) return;
        try {
            const typers = await apiGet(`/app/conversations/${id}/typing`);
            const el = $('typing-indicator');
            el.textContent = typers.length
                ? `${typers.map((t) => t.name).join(', ')} ${typers.length > 1 ? 'are' : 'is'} typing…`
                : '';
        } catch (e) { /* ignore */ }
    }

    // ---------- presence heartbeat ----------

    function startHeartbeat() {
        apiPost('/app/heartbeat', { is_online: true }).catch(() => {});
        state.timers.heartbeat = setInterval(() => {
            apiPost('/app/heartbeat', { is_online: true }).catch(() => {});
        }, 30000);
    }

    // ---------- new chat modal ----------

    const modal = $('new-chat-modal');

    $('new-chat-btn').addEventListener('click', () => {
        resetModal();
        modal.classList.remove('hidden');
        $('user-search-input').focus();
    });

    $('close-modal-btn').addEventListener('click', () => modal.classList.add('hidden'));
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });

    function resetModal() {
        state.newChatMode = 'private';
        state.selectedUserIds = new Set();
        state.selectedUsersMap = new Map();
        $('group-name-input').value = '';
        $('group-name-wrap').classList.add('hidden');
        $('user-search-input').value = '';
        $('user-search-results').innerHTML = '';
        $('selected-users').innerHTML = '';
        $('create-chat-btn').disabled = true;
        const groupButton = document.querySelector('[data-mode="group"]');
        if (groupButton) groupButton.classList.toggle('hidden', !IS_ADMIN);
        document.querySelectorAll('.chat-mode-btn').forEach((btn) => setModeBtnStyle(btn, btn.dataset.mode === 'private'));
    }

    document.querySelectorAll('.chat-mode-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            state.newChatMode = btn.dataset.mode;
            document.querySelectorAll('.chat-mode-btn').forEach((b) => setModeBtnStyle(b, b === btn));
            $('group-name-wrap').classList.toggle('hidden', state.newChatMode !== 'group');
            updateCreateButtonState();
        });
    });

    function setModeBtnStyle(btn, active) {
        btn.className = `chat-mode-btn flex-1 text-sm py-1.5 rounded-lg border ${active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 text-gray-600'}`;
    }

    let searchDebounce = null;
    $('user-search-input').addEventListener('input', (e) => {
        clearTimeout(searchDebounce);
        const q = e.target.value.trim();
        searchDebounce = setTimeout(() => searchUsers(q), 300);
    });

    async function searchUsers(q) {
        try {
            const res = await apiGet(`/app/users?q=${encodeURIComponent(q)}`);
            renderUserResults(res.data || res);
        } catch (e) { console.error(e); }
    }

    function renderUserResults(users) {
        const container = $('user-search-results');
        if (!users.length) {
            container.innerHTML = `
                <div class="p-4 text-center">
                    <p class="text-xs text-gray-400">No users found</p>
                    ${IS_ADMIN ? '<button type="button" id="invite-from-search-btn" class="mt-2 text-xs font-semibold text-emerald-400 hover:text-emerald-300">+ Invite new user</button>' : ''}
                </div>`;
            const inviteFromSearchButton = $('invite-from-search-btn');
            if (inviteFromSearchButton) {
                inviteFromSearchButton.addEventListener('click', () => {
                    state.inviteFromNewChat = true;
                    modal.classList.add('hidden');
                    openInviteModal($('user-search-input').value.trim());
                });
            }
            return;
        }
        container.innerHTML = users.map((u) => `
            <div class="user-search-item ${state.selectedUserIds.has(u.id) ? 'selected' : ''}" data-id="${u.id}" data-name="${escapeHtml(u.name)}">
                <div class="avatar-circle w-8 h-8 text-xs">${escapeHtml(initials(u.name))}</div>
                <div class="min-w-0">
                    <p class="text-gray-800 truncate">${escapeHtml(u.name)}</p>
                    <p class="text-gray-400 text-[11px] truncate">${escapeHtml(u.email)}</p>
                </div>
            </div>`).join('');

        container.querySelectorAll('.user-search-item').forEach((el) => {
            el.addEventListener('click', () => handleUserPick(parseInt(el.dataset.id, 10), el.dataset.name));
        });
    }

    async function handleUserPick(userId, userName) {
        if (state.newChatMode === 'private') {
            modal.classList.add('hidden');
            try {
                const conv = await apiPost('/app/conversations', { type: 'private', user_id: userId });
                await loadConversations();
                openConversation(conv.id);
            } catch (e) { alert(e.message); }
            return;
        }

        // group mode: toggle selection
        if (state.selectedUserIds.has(userId)) {
            state.selectedUserIds.delete(userId);
            state.selectedUsersMap.delete(userId);
        } else {
            state.selectedUserIds.add(userId);
            state.selectedUsersMap.set(userId, userName);
        }
        renderSelectedUsers();
        document.querySelectorAll('.user-search-item').forEach((el) => {
            el.classList.toggle('selected', state.selectedUserIds.has(parseInt(el.dataset.id, 10)));
        });
        updateCreateButtonState();
    }

    function renderSelectedUsers() {
        const container = $('selected-users');
        container.innerHTML = Array.from(state.selectedUsersMap.entries()).map(([id, name]) => `
            <span class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-700 text-xs px-2 py-1 rounded-full">
                ${escapeHtml(name)}
                <button type="button" data-id="${id}" class="remove-selected-user font-bold">&times;</button>
            </span>`).join('');

        container.querySelectorAll('.remove-selected-user').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.dataset.id, 10);
                state.selectedUserIds.delete(id);
                state.selectedUsersMap.delete(id);
                renderSelectedUsers();
                updateCreateButtonState();
            });
        });
    }

    $('group-name-input').addEventListener('input', updateCreateButtonState);

    function updateCreateButtonState() {
        const btn = $('create-chat-btn');
        if (state.newChatMode !== 'group') {
            btn.disabled = true;
            return;
        }
        btn.disabled = !(state.selectedUserIds.size >= 1 && $('group-name-input').value.trim().length > 0);
    }

    $('create-chat-btn').addEventListener('click', async () => {
        try {
            const conv = await apiPost('/app/conversations', {
                type: 'group',
                name: $('group-name-input').value.trim(),
                user_ids: Array.from(state.selectedUserIds),
            });
            modal.classList.add('hidden');
            await loadConversations();
            openConversation(conv.id);
        } catch (e) { alert(e.message); }
    });

    const inviteModal = $('invite-user-modal');
    const inviteButton = $('invite-user-btn');
    const inviteForm = $('invite-user-form');

    function openInviteModal(email = '') {
        if (inviteForm) inviteForm.reset();
        const emailField = $('invite-email-input');
        if (emailField) emailField.value = email;
        inviteModal.classList.remove('hidden');
    }

    if (inviteModal && inviteButton) {
        inviteButton.addEventListener('click', () => {
            state.inviteFromNewChat = false;
            openInviteModal();
        });
        $('close-invite-modal-btn').addEventListener('click', () => {
            if (inviteForm) inviteForm.reset();
            inviteModal.classList.add('hidden');
        });
        inviteModal.addEventListener('click', (e) => {
            if (e.target === inviteModal) {
                if (inviteForm) inviteForm.reset();
                inviteModal.classList.add('hidden');
            }
        });
        $('invite-user-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitButton = e.currentTarget.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            try {
                const result = await apiPost('/app/users', {
                    name: $('invite-name-input').value.trim(),
                    email: $('invite-email-input').value.trim(),
                    password: $('invite-password-input').value,
                });
                alert(`${result.user.name} invited successfully.`);
                e.currentTarget.reset();
                inviteModal.classList.add('hidden');
                if (state.inviteFromNewChat && state.newChatMode === 'private') {
                    const conv = await apiPost('/app/conversations', { type: 'private', user_id: result.user.id });
                    await loadConversations();
                    openConversation(conv.id);
                }
                state.inviteFromNewChat = false;
            } catch (error) {
                alert(error.message);
            } finally {
                submitButton.disabled = false;
            }
        });
    }

    const sendInviteFromChatButton = $('send-invite-from-chat-btn');
    if (sendInviteFromChatButton && inviteModal) {
        sendInviteFromChatButton.addEventListener('click', () => {
            state.inviteFromNewChat = true;
            const email = $('user-search-input').value.trim();
            modal.classList.add('hidden');
            openInviteModal(email);
        });
    }

    // ---------- boot ----------

    async function init() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch((error) => console.error('Service worker registration failed', error));
        }
        await loadConversations();
        startHeartbeat();
        state.timers.conversations = setInterval(loadConversations, 4000);

        const initialConversationId = Number(INITIAL_CONVERSATION_ID);
        if (Number.isInteger(initialConversationId) && initialConversationId > 0) {
            openConversation(initialConversationId);
        }
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt = event;
        showInstallButton();
    });

    window.addEventListener('appinstalled', () => {
        deferredInstallPrompt = null;
        $('install-app-btn').classList.add('hidden');
    });

    if (isIosDevice() && !window.navigator.standalone) showInstallButton();

    init();
})();
