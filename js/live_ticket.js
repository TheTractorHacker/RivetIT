/* Live ticket updates: SSE-driven status/reply notices + chat panel */
(function () {
    'use strict';

    var root = document.querySelector('[data-ticket-id]');
    if (!root || typeof EventSource === 'undefined') return;

    var ticketId = root.dataset.ticketId;
    var csrfToken = root.dataset.csrf;
    var userName = root.dataset.userName || '';
    var userId = root.dataset.userId || '0';
    var userType = root.dataset.userType || '';

    var repliesNotice = document.getElementById('ticket-replies-notice');
    var statusBadge = document.getElementById('ticketStatusBadge');
    var statusText = document.getElementById('ticket-status-text');
    var statusDot = document.getElementById('quickStatusColorDot');
    var statusSelect = document.getElementById('quickStatusSelect');

    var chatList = document.getElementById('ticket-chat-messages');
    var chatForm = document.getElementById('ticket-chat-form');
    var chatInput = document.getElementById('ticket-chat-input');

    function showReplyNotice(by) {
        if (!repliesNotice || repliesNotice.childElementCount) return;

        var alertBox = document.createElement('div');
        alertBox.className = 'alert alert-info d-flex justify-content-between align-items-center';

        var label = document.createElement('span');
        label.innerHTML = '<i class="fas fa-fw fa-sync-alt mr-2"></i>This ticket has new activity' + (by ? ' from ' + escapeHtml(by) : '') + '.';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-primary ml-3';
        btn.textContent = 'Refresh';
        btn.addEventListener('click', function () {
            location.reload();
        });

        alertBox.appendChild(label);
        alertBox.appendChild(btn);
        repliesNotice.appendChild(alertBox);
    }

    function updateStatus(data) {
        if (statusBadge) {
            statusBadge.textContent = data.status_name;
            statusBadge.style.backgroundColor = data.status_color;
        }
        if (statusText) {
            statusText.textContent = data.status_name;
        }
        if (statusDot) {
            statusDot.style.backgroundColor = data.status_color;
        }
        if (statusSelect) {
            statusSelect.value = data.status_id;
            statusSelect.style.backgroundColor = data.status_color;
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatTime(value) {
        var d = new Date((value || '').replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function appendChatMessage(data, isMine) {
        if (!chatList) return;

        if (data.chat_id && chatList.querySelector('[data-chat-id="' + data.chat_id + '"]')) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'mb-2 d-flex ' + (isMine ? 'justify-content-end' : 'justify-content-start');
        if (data.chat_id) {
            wrap.dataset.chatId = data.chat_id;
        }

        var bubble = document.createElement('div');
        bubble.className = 'p-2 rounded ' + (isMine ? 'bg-primary text-white' : 'bg-light');
        bubble.style.maxWidth = '85%';

        var meta = document.createElement('div');
        meta.className = 'small';
        meta.style.opacity = '0.75';
        meta.textContent = (data.sender_name || '') + (data.created_at ? ' · ' + formatTime(data.created_at) : '');

        var body = document.createElement('div');
        body.style.whiteSpace = 'pre-wrap';
        body.textContent = data.message || '';

        bubble.appendChild(meta);
        bubble.appendChild(body);
        wrap.appendChild(bubble);
        chatList.appendChild(wrap);
        chatList.scrollTop = chatList.scrollHeight;
    }

    function connect() {
        var es = new EventSource('sse_ticket_stream.php?ticket_id=' + encodeURIComponent(ticketId));

        es.onmessage = function (event) {
            var data;
            try {
                data = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            if (data.type === 'status') {
                updateStatus(data);
            } else if (data.type === 'reply') {
                showReplyNotice(data.by);
            } else if (data.type === 'chat') {
                var isMine = String(data.sender_id) === String(userId) && data.sender_type === userType;
                appendChatMessage(data, isMine);
            }
        };
    }

    (window.__ticketChatHistory || []).forEach(function (data) {
        var isMine = String(data.sender_id) === String(userId) && data.sender_type === userType;
        appendChatMessage(data, isMine);
    });

    connect();

    if (chatForm && chatInput) {
        chatForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var message = chatInput.value.trim();
            if (!message) return;

            var formData = new FormData();
            formData.append('add_ticket_chat_message', '1');
            formData.append('ticket_id', ticketId);
            formData.append('message', message);
            formData.append('csrf_token', csrfToken);

            chatInput.disabled = true;

            fetch('post.php', { method: 'POST', body: formData })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.ok) {
                        appendChatMessage({
                            chat_id: resp.id,
                            message: message,
                            sender_name: userName,
                            created_at: new Date().toISOString().slice(0, 19).replace('T', ' ')
                        }, true);
                        chatInput.value = '';
                    }
                })
                .finally(function () {
                    chatInput.disabled = false;
                    chatInput.focus();
                });
        });
    }
})();
