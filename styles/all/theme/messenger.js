(function () {
	'use strict';

	var cfg = window.MESSENGER_CONFIG || {};
	var chatShell = document.querySelector('[data-msgr="chat"]');
	var groupShell = document.querySelector('[data-msgr="group"]');
	var composeShell = document.querySelector('[data-msgr="compose"]');
	var mode = chatShell ? 'chat' : (groupShell ? 'group' : (composeShell ? 'compose' : 'roster'));
	var pollTimer = null;
	var typingHeartbeatTimer = null;
	var typingActive = false;
	var lastMsgId = parseInt(cfg.lastMsgId || '0', 10);
	var oldestMsgId = parseInt(cfg.oldestMsgId || '0', 10);
	var hasOlder = !!cfg.hasOlder;
	var loadingOlder = false;

	function syncStandaloneLayout() {
		var isStandalone = document.body.classList.contains('msgr-standalone');
		var isEmbedded = document.body.classList.contains('msgr-embedded');
		if (!isStandalone && !isEmbedded) {
			return;
		}

		var root = document.documentElement;
		var navbar = document.querySelector('#page-header .navbar')
			|| document.querySelector('.navbar')
			|| document.querySelector('#page-header nav')
			|| document.querySelector('#page-header');
		var topOffset = 0;

		if (navbar) {
			var navRect = navbar.getBoundingClientRect();
			topOffset = Math.max(0, Math.round(navRect.bottom));
			if (topOffset < 24) {
				topOffset = Math.max(topOffset, Math.round(navRect.height));
			}
			root.style.setProperty('--msgr-navbar-height', Math.round(navRect.height) + 'px');
		}

		if (isEmbedded) {
			var app = document.querySelector('[data-msgr-app]');
			if (app) {
				topOffset = Math.max(0, Math.round(app.getBoundingClientRect().top));
			}
		}

		root.style.setProperty('--msgr-top-offset', topOffset + 'px');
		syncBottomNavHeight();
		syncEmbeddedAppHeight();

		if (isStandalone && window.matchMedia('(max-width: 899px)').matches) {
			document.body.style.paddingTop = '0';

			['inner-wrap', 'outer-wrap', 'wrap', 'page-body'].forEach(function (id) {
				var node = document.getElementById(id);
				if (node) {
					node.style.paddingTop = '0';
					node.style.marginTop = '0';
				}
			});
		}
	}

	function isVisibleElement(node) {
		if (!node) {
			return false;
		}

		var style = window.getComputedStyle(node);
		if (style.display === 'none' || style.visibility === 'hidden') {
			return false;
		}

		var rect = node.getBoundingClientRect();
		return rect.height > 0 && rect.width > 0;
	}

	function isBottomNavBar(node) {
		if (!isVisibleElement(node)) {
			return false;
		}

		var style = window.getComputedStyle(node);
		if (style.position !== 'fixed' && style.position !== 'sticky') {
			return false;
		}

		var rect = node.getBoundingClientRect();
		return rect.bottom >= window.innerHeight - 4 && rect.width >= window.innerWidth * 0.45;
	}

	function findBottomNav() {
		var selectors = [
			'#mobile-footer-nav',
			'.mobile-footer-nav',
			'#mobile-nav',
			'.mobile-nav-bar',
			'.footer-nav-bar',
			'#bottom-bar',
			'.bottom-nav',
			'.mobile-bottom-nav',
			'#mobile-tab-nav',
			'.mobile-tab-nav',
			'#footer-nav',
			'nav.footer-nav',
			'#nav-tabbar',
			'.nav-tabbar',
			'.mobile-links',
			'#mobile-links'
		];
		var i;

		for (i = 0; i < selectors.length; i++) {
			var match = document.querySelector(selectors[i]);
			if (match && isBottomNavBar(match)) {
				return match;
			}
		}

		var nodes = document.querySelectorAll('body nav, body div, body footer, body ul');
		for (i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			if (node.closest('[data-msgr-app]') || (node.id && node.id.indexOf('msgr-') === 0)) {
				continue;
			}

			if (node.closest('#page-footer') || node.id === 'page-footer') {
				continue;
			}

			if (isBottomNavBar(node)) {
				return node;
			}
		}

		return null;
	}

	function syncBottomNavHeight() {
		var root = document.documentElement;

		if (!window.matchMedia('(max-width: 899px)').matches) {
			root.style.setProperty('--msgr-bottom-nav-height', '0px');
			return;
		}

		var bottomNav = findBottomNav();
		var bottomHeight = bottomNav
			? Math.max(0, Math.round(bottomNav.getBoundingClientRect().height))
			: 0;

		root.style.setProperty('--msgr-bottom-nav-height', bottomHeight + 'px');
	}

	function syncEmbeddedAppHeight() {
		var root = document.documentElement;

		if (!document.body.classList.contains('msgr-embedded')
			|| !window.matchMedia('(max-width: 899px)').matches) {
			root.style.removeProperty('--msgr-app-height');
			return;
		}

		var app = document.querySelector('[data-msgr-app]');
		if (!app) {
			return;
		}

		var top = Math.max(0, Math.round(app.getBoundingClientRect().top));
		var bottomNav = parseFloat(getComputedStyle(root).getPropertyValue('--msgr-bottom-nav-height')) || 0;
		var bottom = window.innerHeight - bottomNav;
		var footer = document.querySelector('#page-footer');

		if (footer) {
			var footerRect = footer.getBoundingClientRect();
			if (footerRect.top < bottom) {
				bottom = Math.max(top + 280, footerRect.top);
			}
		}

		var height = Math.max(280, Math.floor(bottom - top));
		root.style.setProperty('--msgr-app-height', height + 'px');
	}

	function mountOverlays() {
		[
			'msgr-actions-backdrop',
			'msgr-roster-dropdown',
			'msgr-roster-sheet',
			'msgr-delete-modal',
			'msgr-wallpaper-modal',
			'msgr-image-lightbox',
			'msgr-toast'
		].forEach(function (id) {
			var el = document.getElementById(id);
			if (el && el.parentNode !== document.body) {
				document.body.appendChild(el);
			}
		});
	}

	function initUcpLayout() {
		if (!document.body.classList.contains('msgr-ucp')) {
			return;
		}

		function viewportHeight() {
			return window.visualViewport ? window.visualViewport.height : window.innerHeight;
		}

		function resize() {
			var app = document.querySelector('[data-msgr-app]');
			if (!app) {
				return;
			}

			var top = Math.max(0, Math.round(app.getBoundingClientRect().top));
			var height = Math.max(360, viewportHeight() - top - 16);
			app.style.height = height + 'px';
			app.style.maxHeight = height + 'px';
		}

		resize();
		window.addEventListener('resize', resize);
		window.addEventListener('orientationchange', resize);
		window.addEventListener('load', resize);
		if (window.visualViewport) {
			window.visualViewport.addEventListener('resize', resize);
		}
	}

	function initStandaloneLayout() {
		if (!document.body.classList.contains('msgr-standalone')
			&& !document.body.classList.contains('msgr-embedded')) {
			return;
		}

		syncStandaloneLayout();
		syncBottomNavHeight();
		syncEmbeddedAppHeight();
		window.addEventListener('resize', function () {
			syncStandaloneLayout();
			syncBottomNavHeight();
			syncEmbeddedAppHeight();
		});
		window.addEventListener('orientationchange', function () {
			syncStandaloneLayout();
			syncBottomNavHeight();
			syncEmbeddedAppHeight();
		});
		window.addEventListener('load', function () {
			syncStandaloneLayout();
			syncBottomNavHeight();
			syncEmbeddedAppHeight();
		});
		window.setTimeout(function () {
			syncStandaloneLayout();
			syncBottomNavHeight();
			syncEmbeddedAppHeight();
		}, 150);
	}

	function initMsgIdsFromDom() {
		document.querySelectorAll('#msgr-messages .msgr-message[data-msg-id]').forEach(function (node) {
			var id = parseInt(node.getAttribute('data-msg-id') || '0', 10);
			lastMsgId = Math.max(lastMsgId, id);
			if (!oldestMsgId || id < oldestMsgId) {
				oldestMsgId = id;
			}
		});
	}

	function buildMessageMenuHtml(message) {
		var labels = cfg.labels || {};
		var menu = '<div class="msgr-bubble-menu">' +
			'<button type="button" class="msgr-msg-menu-btn" aria-haspopup="menu" aria-expanded="false" aria-label="' + escapeAttr(labels.messageActions || labels.chatActions || 'Actions') + '">' +
				'<i class="icon fa-chevron-down fa-fw" aria-hidden="true"></i>' +
			'</button>' +
			'<div class="msgr-msg-dropdown" role="menu" hidden>' +
				'<button type="button" role="menuitem" class="msgr-msg-menu-item msgr-msg-quote">' +
					'<i class="icon fa-quote-left fa-fw" aria-hidden="true"></i>' +
					'<span>' + escapeAttr(labels.quote || 'Quote') + '</span>' +
				'</button>';

		if (message.can_edit) {
			menu += '<button type="button" role="menuitem" class="msgr-msg-menu-item msgr-msg-edit">' +
				'<i class="icon fa-pencil fa-fw" aria-hidden="true"></i>' +
				'<span>' + escapeAttr(labels.editMessage || 'Edit') + '</span>' +
			'</button>';
		}

		if (message.is_own) {
			menu += '<button type="button" role="menuitem" class="msgr-msg-menu-item msgr-msg-delete is-danger">' +
				'<i class="icon fa-trash fa-fw" aria-hidden="true"></i>' +
				'<span>' + escapeAttr(labels.deleteMessage || 'Delete') + '</span>' +
			'</button>';
		}

		menu += '</div></div>';
		return menu;
	}

	function buildMessageHtml(message) {
		var authorHtml = '';
		var labels = cfg.labels || {};
		if (cfg.chatType === 'group' && !message.is_own && message.author_username) {
			authorHtml = '<div class="msgr-bubble-author">' + escapeAttr(message.author_username) + '</div>';
		}

		var editedHtml = message.is_edited
			? '<span class="msgr-edited-label">' + escapeAttr(labels.edited || 'edited') + '</span>'
			: '';

		return '<div class="msgr-bubble">' +
			buildMessageMenuHtml(message) +
			authorHtml +
			'<div class="msgr-bubble-content">' + message.message_html + '</div>' +
			'<footer class="msgr-bubble-footer">' +
				editedHtml +
				'<time data-ts="' + (parseInt(message.message_time, 10) || 0) + '">' + (message.time_formatted || '') + '</time>' +
				renderReadStatusHtml(message.read_status) +
			'</footer>' +
		'</div>';
	}

	function escapeAttr(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;');
	}

	function createMessageNode(message) {
		var row = document.createElement('div');
		var authorName = message.author_username || (message.is_own ? (cfg.currentUsername || '') : (cfg.partnerName || ''));
		row.className = 'msgr-message ' + (message.is_own ? 'is-own' : 'is-other');
		row.id = 'p' + message.msg_id;
		row.setAttribute('data-msg-id', message.msg_id);
		row.setAttribute('data-author-name', authorName);
		row.setAttribute('data-message-plain', message.message_plain || '');
		row.innerHTML = buildMessageHtml(message);
		return row;
	}

	function setLoadOlderVisible(visible) {
		var wrap = document.getElementById('msgr-load-older');
		if (!wrap) {
			return;
		}
		wrap.hidden = !visible;
		hasOlder = visible;
	}

	function postFormData(url, formData) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: formData
		}).then(function (response) {
			return response.text().then(function (text) {
				var payload = null;
				if (text) {
					try {
						payload = JSON.parse(text);
					} catch (error) {
						payload = { success: false, error: text.substring(0, 200) };
					}
				}

				if (!response.ok) {
					throw payload || { success: false, error: 'HTTP ' + response.status };
				}

				return payload || { success: false };
			});
		});
	}

	function postJson(url, data) {
		var body = new URLSearchParams();
		Object.keys(data).forEach(function (key) {
			if (Array.isArray(data[key])) {
				data[key].forEach(function (value) {
					body.append(key + '[]', value);
				});
				return;
			}
			body.append(key, data[key]);
		});

		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body.toString()
		}).then(function (response) {
			return response.text().then(function (text) {
				var payload = null;
				if (text) {
					try {
						payload = JSON.parse(text);
					} catch (error) {
						payload = { success: false, error: text.substring(0, 300) };
					}
				}

				if (!response.ok) {
					throw payload || { success: false, error: 'HTTP ' + response.status };
				}

				return payload || { success: false, error: 'Empty response' };
			});
		});
	}

	function uploadErrorMessage(payload, fallback) {
		if (payload && payload.error) {
			return payload.error;
		}
		if (payload && payload.message) {
			return payload.message;
		}
		return fallback || 'Upload failed.';
	}

	function compressImageForUpload(file, maxWidth, quality) {
		if (!window.HTMLCanvasElement || !file.type || file.type.indexOf('image/') !== 0) {
			return Promise.resolve(file);
		}

		if (file.size <= 512 * 1024) {
			return Promise.resolve(file);
		}

		return new Promise(function (resolve) {
			var img = new Image();
			var objectUrl = URL.createObjectURL(file);

			img.onload = function () {
				URL.revokeObjectURL(objectUrl);

				var width = img.width;
				var height = img.height;
				if (width > maxWidth) {
					height = Math.round(height * (maxWidth / width));
					width = maxWidth;
				}

				var canvas = document.createElement('canvas');
				canvas.width = width;
				canvas.height = height;

				var ctx = canvas.getContext('2d');
				if (!ctx || typeof canvas.toBlob !== 'function') {
					resolve(file);
					return;
				}

				ctx.drawImage(img, 0, 0, width, height);
				canvas.toBlob(function (blob) {
					resolve(blob || file);
				}, 'image/jpeg', quality);
			};

			img.onerror = function () {
				URL.revokeObjectURL(objectUrl);
				resolve(file);
			};

			img.src = objectUrl;
		});
	}

	function readFileAsUploadPayload(file) {
		return compressImageForUpload(file, 1600, 0.85).then(function (uploadFile) {
			return new Promise(function (resolve, reject) {
				var reader = new FileReader();
				reader.onload = function () {
					var dataUrl = String(reader.result || '');
					var comma = dataUrl.indexOf(',');
					var base64 = comma >= 0 ? dataUrl.substring(comma + 1) : dataUrl;
					var filename = file.name || 'image.jpg';

					if (uploadFile !== file) {
						filename = filename.replace(/\.[^.]+$/, '') + '.jpg';
					}

					resolve({
						filename: filename,
						base64: base64
					});
				};
				reader.onerror = reject;
				reader.readAsDataURL(uploadFile);
			});
		});
	}

	function syncNavbarNotifications(count) {
		if (typeof count !== 'number' || count < 0) {
			return;
		}

		var btn = document.getElementById('notification_list_button');
		var badge = btn ? btn.querySelector('.badge') : null;
		if (!badge) {
			badge = document.querySelector('#notification_list_button .badge, .notification-list-link .badge');
		}

		if (count <= 0) {
			if (badge && badge.parentNode) {
				badge.parentNode.removeChild(badge);
			}
			return;
		}

		if (!badge && btn) {
			badge = document.createElement('span');
			badge.className = 'badge';
			btn.appendChild(badge);
		}

		if (badge) {
			badge.textContent = String(count);
		}
	}

	function syncNavbarPrivateMessages(count) {
		if (typeof count !== 'number' || count < 0) {
			return;
		}

		var selectors = [
			'a[href*="messenger"] .badge',
			'a[href*="privmsg"] .badge',
			'a[href*="messenger"] strong.badge',
			'a[href*="privmsg"] strong.badge'
		];

		document.querySelectorAll(selectors.join(', ')).forEach(function (badge) {
			if (count <= 0) {
				badge.classList.add('hidden');
				badge.textContent = '0';
				return;
			}

			badge.classList.remove('hidden');
			badge.textContent = String(count);
		});
	}

	function handleMarkReadResponse(payload) {
		if (!payload || !payload.success) {
			return;
		}

		if (typeof payload.notifications_count === 'number') {
			syncNavbarNotifications(payload.notifications_count);
		}

		if (typeof payload.unread_pm_count === 'number') {
			syncNavbarPrivateMessages(payload.unread_pm_count);
		}
	}

	function getJson(url) {
		return fetch(url, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				'X-Requested-With': 'XMLHttpRequest',
				'Accept': 'application/json',
				'Cache-Control': 'no-cache',
				'Pragma': 'no-cache'
			}
		}).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok) {
					throw payload;
				}
				return payload;
			});
		});
	}

	function scrollMessagesToBottom() {
		var box = document.getElementById('msgr-messages');
		if (!box) {
			return;
		}

		function applyScroll() {
			box.scrollTop = box.scrollHeight;
		}

		applyScroll();
		window.requestAnimationFrame(function () {
			applyScroll();
			window.requestAnimationFrame(applyScroll);
		});
		window.setTimeout(applyScroll, 60);
	}

	function renderReadStatusHtml(readStatus) {
		if (!readStatus) {
			return '';
		}

		var cls = readStatus === 'read' ? 'is-read' : 'is-delivered';
		return '<span class="msgr-read-status ' + cls + '">' +
			'<i class="icon fa-check fa-fw msgr-tick msgr-tick-1"></i>' +
			'<i class="icon fa-check fa-fw msgr-tick msgr-tick-2"></i>' +
		'</span>';
	}

	function updateReadStatuses(statuses) {
		if (!statuses || !statuses.length) {
			return;
		}

		statuses.forEach(function (item) {
			var row = document.querySelector('#msgr-messages [data-msg-id="' + item.msg_id + '"]');
			if (!row || !row.classList.contains('is-own')) {
				return;
			}

			var footer = row.querySelector('.msgr-bubble-footer');
			if (!footer) {
				return;
			}

			var current = footer.querySelector('.msgr-read-status');
			var next = renderReadStatusHtml(item.read_status);
			if (!next) {
				return;
			}

			if (current) {
				if (current.classList.contains('is-read') && item.read_status !== 'read') {
					return;
				}
				current.outerHTML = next;
			} else {
				footer.insertAdjacentHTML('beforeend', next);
			}
		});
	}

	function appendMessage(message) {
		var box = document.getElementById('msgr-messages');
		if (!box || !message) {
			return;
		}

		var existing = box.querySelector('[data-msg-id="' + message.msg_id + '"]');
		if (existing) {
			if (message.is_own && message.read_status) {
				updateReadStatuses([{ msg_id: message.msg_id, read_status: message.read_status }]);
			}
			return;
		}

		var row = createMessageNode(message);

		box.appendChild(row);
		lastMsgId = Math.max(lastMsgId, parseInt(message.msg_id, 10));
		scrollMessagesToBottom();
	}

	function prependMessages(messages) {
		var box = document.getElementById('msgr-messages');
		if (!box || !messages || !messages.length) {
			return;
		}

		var loadOlder = document.getElementById('msgr-load-older');
		var anchor = box.querySelector('.msgr-message[data-msg-id]');
		var previousHeight = box.scrollHeight;
		var previousTop = box.scrollTop;

		messages.forEach(function (message) {
			if (box.querySelector('[data-msg-id="' + message.msg_id + '"]')) {
				return;
			}

			var row = createMessageNode(message);
			if (anchor) {
				box.insertBefore(row, anchor);
			} else if (loadOlder) {
				loadOlder.insertAdjacentElement('afterend', row);
			} else {
				box.insertBefore(row, box.firstChild);
			}

			if (!oldestMsgId || parseInt(message.msg_id, 10) < oldestMsgId) {
				oldestMsgId = parseInt(message.msg_id, 10);
			}
		});

		box.scrollTop = previousTop + (box.scrollHeight - previousHeight);
	}

	function loadOlderMessages() {
		if (!cfg.apiChat || !oldestMsgId || !hasOlder || loadingOlder) {
			return Promise.resolve(false);
		}

		var wrap = document.getElementById('msgr-load-older');
		loadingOlder = true;
		if (wrap) {
			wrap.classList.add('is-loading');
		}

		var url = cfg.apiChat +
			'?before=' + encodeURIComponent(oldestMsgId) +
			'&limit=' + encodeURIComponent(cfg.loadOlderLimit || 20);

		return getJson(url).then(function (payload) {
			if (!payload || !payload.messages) {
				return false;
			}

			prependMessages(payload.messages);

			if (payload.oldest_msg_id) {
				oldestMsgId = parseInt(payload.oldest_msg_id, 10);
			}

			setLoadOlderVisible(!!payload.has_older);
			return true;
		}).catch(function () {
			return false;
		}).finally(function () {
			loadingOlder = false;
			if (wrap) {
				wrap.classList.remove('is-loading');
			}
		});
	}

	function loadOlderUntil(msgId) {
		msgId = parseInt(msgId, 10);
		if (!msgId) {
			return Promise.resolve(false);
		}

		var remaining = 100; // safety cap of 100 batches

		function attempt() {
			if (document.querySelector('.msgr-message[data-msg-id="' + msgId + '"]')) {
				return Promise.resolve(true);
			}

			// Target is newer than the oldest loaded message but still missing:
			// it does not exist in this chat (deleted), stop loading.
			if (oldestMsgId && msgId >= oldestMsgId) {
				return Promise.resolve(false);
			}

			if (!hasOlder || remaining <= 0) {
				return Promise.resolve(false);
			}

			remaining--;
			return loadOlderMessages().then(function (loaded) {
				return loaded ? attempt() : false;
			});
		}

		return attempt();
	}

	function initLoadOlder() {
		var btn = document.getElementById('msgr-load-older-btn');
		if (!btn) {
			return;
		}

		setLoadOlderVisible(hasOlder);
		btn.addEventListener('click', loadOlderMessages);
	}

	function getActivePartnerId() {
		return parseInt(cfg.partnerId || '0', 10);
	}

	function getActiveGroupId() {
		return parseInt(cfg.groupId || '0', 10);
	}

	function getRosterKey(chat) {
		if (chat && chat.chat_type === 'group') {
			return 'g:' + chat.group_id;
		}
		return 'p:' + (chat.partner_id || 0);
	}

	function ensureRosterList() {
		var sidebar = document.querySelector('.msgr-sidebar');
		if (!sidebar) {
			return null;
		}

		var list = document.getElementById('msgr-roster');
		if (list) {
			return list;
		}

		var empty = sidebar.querySelector('.msgr-empty-sidebar');
		if (empty) {
			empty.parentNode.removeChild(empty);
		}

		list = document.createElement('ul');
		list.className = 'msgr-roster';
		list.id = 'msgr-roster';
		sidebar.appendChild(list);
		return list;
	}

	function setRosterBadge(sideEl, unreadCount) {
		if (!sideEl) {
			return;
		}

		var badge = sideEl.querySelector('.msgr-unread-badge');
		if (unreadCount > 0) {
			if (!badge) {
				badge = document.createElement('span');
				badge.className = 'msgr-unread-badge';
				sideEl.appendChild(badge);
			}
			badge.textContent = String(unreadCount);
			badge.classList.toggle('is-wide', String(unreadCount).length > 1);
		} else if (badge) {
			badge.parentNode.removeChild(badge);
		}
	}

	function initRosterBadgeShapes() {
		document.querySelectorAll('.msgr-unread-badge').forEach(function (badge) {
			badge.classList.toggle('is-wide', (badge.textContent || '').trim().length > 1);
		});
	}

	function setOnlineDot(avatarWrap, isOnline) {
		if (!avatarWrap) {
			return;
		}

		var dot = avatarWrap.querySelector('.msgr-online-dot');
		if (isOnline) {
			if (!dot) {
				dot = document.createElement('span');
				dot.className = 'msgr-online-dot';
				dot.setAttribute('aria-hidden', 'true');
				avatarWrap.appendChild(dot);
			}
		} else if (dot) {
			dot.parentNode.removeChild(dot);
		}
	}

	function setPinIcon(sideEl, isPinned) {
		if (!sideEl) {
			return;
		}

		var icon = sideEl.querySelector('.msgr-pin-icon');
		if (isPinned) {
			if (!icon) {
				icon = document.createElement('i');
				icon.className = 'icon fa-thumb-tack fa-fw msgr-pin-icon';
				icon.setAttribute('aria-hidden', 'true');
				sideEl.insertBefore(icon, sideEl.firstChild);
			}
		} else if (icon) {
			icon.parentNode.removeChild(icon);
		}
	}

	function applyRosterItemState(item, chat, activePartnerId, activeGroupId) {
		var isGroup = chat.chat_type === 'group';
		item.classList.toggle('is-group', isGroup);
		item.classList.toggle('is-pinned', !!chat.is_pinned);
		item.classList.toggle('has-unread', (chat.unread_count || 0) > 0);
		item.classList.toggle('is-active', isGroup
			? (activeGroupId > 0 && chat.group_id === activeGroupId)
			: (activePartnerId > 0 && chat.partner_id === activePartnerId));

		if (isGroup) {
			item.setAttribute('data-group-id', String(chat.group_id));
			item.setAttribute('data-chat-type', 'group');
			item.removeAttribute('data-partner-id');
		} else {
			item.setAttribute('data-partner-id', String(chat.partner_id));
			item.setAttribute('data-chat-type', 'direct');
			item.removeAttribute('data-group-id');
		}

		var avatarWrap = item.querySelector('.msgr-avatar-wrap');
		if (avatarWrap && isGroup) {
			avatarWrap.innerHTML = '<span class="msgr-avatar-fallback msgr-group-avatar"><i class="icon fa-users fa-fw"></i></span>';
		}

		var name = item.querySelector('.msgr-roster-meta strong');
		if (name) {
			name.textContent = chat.username || '';
			if (chat.user_colour) {
				name.style.color = '#' + chat.user_colour;
			} else {
				name.style.color = '';
			}
		}

		var time = item.querySelector('.msgr-time');
		if (time) {
			time.textContent = chat.time_formatted || '';
			time.setAttribute('data-ts', String(parseInt(chat.last_time, 10) || 0));
			time.removeAttribute('data-rel');
		}

		var preview = item.querySelector('.msgr-preview');
		if (preview) {
			preview.textContent = chat.preview || '';
		}

		setOnlineDot(item.querySelector('.msgr-avatar-wrap'), !!chat.is_online);
		setPinIcon(item.querySelector('.msgr-roster-side'), !!chat.is_pinned);
		setRosterBadge(item.querySelector('.msgr-roster-side'), chat.unread_count || 0);

		var link = item.querySelector('.msgr-roster-link');
		if (link && chat.chat_url) {
			link.setAttribute('href', chat.chat_url);
		}
	}

	function updatePartnerPresence(chats) {
		var partnerId = getActivePartnerId();
		if (!partnerId || !chats || !chats.length) {
			return;
		}

		var chat = null;
		for (var i = 0; i < chats.length; i++) {
			if (parseInt(chats[i].partner_id, 10) === partnerId) {
				chat = chats[i];
				break;
			}
		}

		if (!chat) {
			return;
		}

		document.querySelectorAll('.msgr-partner-presence').forEach(function (node) {
			node.textContent = chat.presence_text || '';
			node.classList.toggle('is-online', !!chat.is_online);
		});

		setOnlineDot(document.querySelector('.msgr-chat-topbar .msgr-avatar-wrap'), !!chat.is_online);
		setOnlineDot(document.querySelector('.msgr-partner-details .msgr-avatar-wrap'), !!chat.is_online);
	}

	function formatTypingLabel(users) {
		var labels = cfg.labels || {};
		if (!users || !users.length) {
			return '';
		}

		if (users.length === 1) {
			var nameOne = users[0].username || cfg.partnerName || '';
			return (labels.typingOne || '%s is typing…').replace('%s', nameOne);
		}

		if (users.length === 2) {
			return (labels.typingTwo || '%1$s and %2$s are typing…')
				.replace('%1$s', users[0].username || '')
				.replace('%2$s', users[1].username || '');
		}

		return (labels.typingMany || '%1$s and %2$d others are typing…')
			.replace('%1$s', users[0].username || '')
			.replace('%2$d', String(users.length - 1));
	}

	function setTypingIndicatorVisible(isVisible, text) {
		var indicators = document.querySelectorAll('.msgr-typing-indicator, .msgr-compose-typing');
		if (!indicators.length) {
			return;
		}

		document.querySelectorAll('.msgr-partner-presence').forEach(function (node) {
			node.hidden = !!isVisible;
		});

		var membersLine = document.querySelector('.msgr-group-members-line');
		if (membersLine) {
			membersLine.hidden = !!isVisible;
		}

		indicators.forEach(function (indicator) {
			if (isVisible) {
				indicator.textContent = text || '';
				indicator.hidden = false;
				indicator.classList.add('is-active');
				return;
			}

			indicator.hidden = true;
			indicator.classList.remove('is-active');
			indicator.textContent = '';
		});

		if (!isVisible) {
			document.querySelectorAll('.msgr-partner-presence').forEach(function (node) {
				node.hidden = false;
			});

			if (membersLine) {
				membersLine.hidden = false;
			}
		}
	}

	function updateTypingIndicator(payload) {
		if (!payload) {
			setTypingIndicatorVisible(false);
			return;
		}

		if (cfg.chatType === 'group') {
			var typingUsers = payload.typing_users || [];
			if (!typingUsers.length) {
				setTypingIndicatorVisible(false);
				return;
			}

			setTypingIndicatorVisible(true, formatTypingLabel(typingUsers));
			return;
		}

		if (payload.partner_typing) {
			var partnerLabel = (cfg.labels && cfg.labels.typingOne) || '%s is typing…';
			setTypingIndicatorVisible(true, partnerLabel.replace('%s', cfg.partnerName || ''));
			return;
		}

		setTypingIndicatorVisible(false);
	}

	function sendTypingHeartbeat() {
		if (!cfg.apiTyping) {
			return;
		}

		var payload = {};
		if (cfg.chatType === 'group' && cfg.groupId) {
			payload.group_id = cfg.groupId;
		} else if (cfg.partnerId) {
			payload.partner_id = cfg.partnerId;
		} else {
			return;
		}

		postJson(cfg.apiTyping, payload).catch(function () {
			// ignore heartbeat errors
		});
	}

	function stopTypingHeartbeat() {
		typingActive = false;
		if (typingHeartbeatTimer) {
			window.clearInterval(typingHeartbeatTimer);
			typingHeartbeatTimer = null;
		}
	}

	function startTypingHeartbeat() {
		if (!cfg.apiTyping || typingActive) {
			return;
		}

		typingActive = true;
		sendTypingHeartbeat();

		if (typingHeartbeatTimer) {
			window.clearInterval(typingHeartbeatTimer);
		}

		typingHeartbeatTimer = window.setInterval(sendTypingHeartbeat, 2000);
	}

	function initTypingIndicator() {
		var input = document.getElementById('msgr-input');
		if (!input || !cfg.apiTyping) {
			return;
		}

		var debounceTimer = null;

		input.addEventListener('input', function () {
			if (!input.value.trim()) {
				stopTypingHeartbeat();
				return;
			}

			if (debounceTimer) {
				window.clearTimeout(debounceTimer);
			}

			debounceTimer = window.setTimeout(function () {
				startTypingHeartbeat();
			}, 300);
		});

		input.addEventListener('blur', stopTypingHeartbeat);

		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && !event.shiftKey) {
				stopTypingHeartbeat();
			}
		});
	}

	function createRosterItem(chat, activePartnerId, activeGroupId) {
		var item = document.createElement('li');
		item.className = 'msgr-roster-item';
		var isGroup = chat.chat_type === 'group';
		if (isGroup) {
			item.setAttribute('data-group-id', String(chat.group_id));
			item.setAttribute('data-chat-type', 'group');
		} else {
			item.setAttribute('data-partner-id', String(chat.partner_id));
			item.setAttribute('data-chat-type', 'direct');
		}

		var colourStyle = chat.user_colour ? ' style="color:#' + chat.user_colour + ';"' : '';
		var avatarHtml = isGroup
			? '<span class="msgr-avatar-fallback msgr-group-avatar"><i class="icon fa-users fa-fw"></i></span>'
			: (chat.avatar || '<span class="msgr-avatar-fallback"><i class="icon fa-user fa-fw"></i></span>') +
				(chat.is_online ? '<span class="msgr-online-dot" aria-hidden="true"></span>' : '');

		item.innerHTML =
			'<label class="msgr-roster-check" hidden>' +
				'<input type="checkbox" class="msgr-roster-checkbox" tabindex="-1" />' +
				'<span class="msgr-roster-check-box" aria-hidden="true"></span>' +
			'</label>' +
			'<a href="' + (chat.chat_url || '#') + '" class="msgr-roster-link">' +
				'<div class="msgr-avatar-wrap">' + avatarHtml + '</div>' +
				'<div class="msgr-roster-body">' +
					'<div class="msgr-roster-meta">' +
						'<strong' + colourStyle + '></strong>' +
						'<time class="msgr-time"></time>' +
					'</div>' +
					'<p class="msgr-preview"></p>' +
				'</div>' +
				'<div class="msgr-roster-side"></div>' +
			'</a>' +
			'<button type="button" class="msgr-roster-menu-btn" aria-haspopup="menu">' +
				'<i class="icon fa-ellipsis-v fa-fw" aria-hidden="true"></i>' +
			'</button>';

		var menuBtn = item.querySelector('.msgr-roster-menu-btn');
		if (menuBtn && cfg.labels && cfg.labels.chatActions) {
			menuBtn.setAttribute('aria-label', cfg.labels.chatActions);
		}

		applyRosterItemState(item, chat, activePartnerId, activeGroupId);
		ensureRosterCheckbox(item);
		if (rosterSelectMode) {
			setRosterItemSelectMode(item, true);
		}
		return item;
	}

	function sortRosterChats(chats) {
		return chats.slice().sort(function (a, b) {
			if (!!a.is_pinned !== !!b.is_pinned) {
				return a.is_pinned ? -1 : 1;
			}
			return (b.last_time || 0) - (a.last_time || 0);
		});
	}

	function updateRoster(chats) {
		if (!chats || !chats.length) {
			return;
		}

		var list = ensureRosterList();
		if (!list) {
			return;
		}

		var activePartnerId = getActivePartnerId();
		var activeGroupId = getActiveGroupId();
		var sorted = sortRosterChats(chats);
		var seen = {};

		sorted.forEach(function (chat) {
			var key = getRosterKey(chat);
			seen[key] = true;
			var item = list.querySelector('[data-chat-type="' + (chat.chat_type || 'direct') + '"]' +
				(chat.chat_type === 'group'
					? '[data-group-id="' + chat.group_id + '"]'
					: '[data-partner-id="' + chat.partner_id + '"]'));
			if (!item) {
				item = createRosterItem(chat, activePartnerId, activeGroupId);
			} else {
				applyRosterItemState(item, chat, activePartnerId, activeGroupId);
			}
			list.appendChild(item);
		});

		list.querySelectorAll('.msgr-roster-item[data-chat-type]').forEach(function (item) {
			var key = item.getAttribute('data-chat-type') === 'group'
				? ('g:' + item.getAttribute('data-group-id'))
				: ('p:' + item.getAttribute('data-partner-id'));
			if (!seen[key]) {
				item.parentNode.removeChild(item);
			} else if (rosterSelectMode) {
				setRosterItemSelectMode(item, true);
			}
		});

		cacheRosterChats(sorted);
		if (rosterSelectMode) {
			updateRosterSelectCount();
		}
	}

	var rosterChatCache = {};
	var rosterMenuState = null;
	var rosterMenuItem = null;
	var toastTimer = null;
	var rosterSelectMode = false;
	var lastEditSync = Math.floor(Date.now() / 1000);
	var editingMsgId = 0;

	function cacheRosterChats(chats) {
		rosterChatCache = {};
		chats.forEach(function (chat) {
			rosterChatCache[getRosterKey(chat)] = chat;
		});
	}

	function partnerApiUrl(template, partnerId) {
		if (!template) {
			return '';
		}
		// The placeholder 0 can sit at the end (/chat/0) or mid-path (/group/0/delete).
		return template.replace(/\/0(\/|\?|$)/, '/' + partnerId + '$1');
	}

	function getChatFromItem(item) {
		var isGroup = item.getAttribute('data-chat-type') === 'group' || item.hasAttribute('data-group-id');
		var groupId = parseInt(item.getAttribute('data-group-id') || '0', 10);
		var partnerId = parseInt(item.getAttribute('data-partner-id') || '0', 10);
		var key = isGroup ? 'g:' + groupId : 'p:' + partnerId;
		if (rosterChatCache[key]) {
			return rosterChatCache[key];
		}

		var nameEl = item.querySelector('.msgr-roster-meta strong');
		return {
			chat_type: isGroup ? 'group' : 'direct',
			group_id: groupId,
			partner_id: partnerId,
			username: nameEl ? nameEl.textContent : '',
			is_pinned: item.classList.contains('is-pinned'),
			unread_count: item.classList.contains('has-unread') ? 1 : 0
		};
	}

	function ensureRosterCheckbox(item) {
		if (!item || item.querySelector('.msgr-roster-check')) {
			return;
		}

		var label = document.createElement('label');
		label.className = 'msgr-roster-check';
		label.hidden = true;
		label.innerHTML = '<input type="checkbox" class="msgr-roster-checkbox" tabindex="-1" />' +
			'<span class="msgr-roster-check-box" aria-hidden="true"></span>';
		item.insertBefore(label, item.firstChild);
	}

	function setRosterItemSelectMode(item, enabled) {
		ensureRosterCheckbox(item);
		var check = item.querySelector('.msgr-roster-check');
		var menuBtn = item.querySelector('.msgr-roster-menu-btn');
		if (check) {
			check.hidden = !enabled;
		}
		if (menuBtn) {
			menuBtn.hidden = !!enabled;
		}
		if (!enabled) {
			item.classList.remove('is-selected');
			var input = item.querySelector('.msgr-roster-checkbox');
			if (input) {
				input.checked = false;
			}
		}
	}

	function getSelectedRosterItems() {
		var list = document.getElementById('msgr-roster');
		if (!list) {
			return [];
		}
		return Array.prototype.slice.call(list.querySelectorAll('.msgr-roster-item.is-selected'));
	}

	function updateRosterSelectCount() {
		var countEl = document.getElementById('msgr-roster-select-count');
		var deleteBtn = document.getElementById('msgr-roster-select-delete');
		var labels = cfg.labels || {};
		var count = getSelectedRosterItems().length;
		if (countEl) {
			countEl.textContent = (labels.selectedCount || '%d selected').replace('%d', count);
		}
		if (deleteBtn) {
			deleteBtn.disabled = count < 1;
		}
	}

	function setRosterSelectMode(enabled) {
		rosterSelectMode = !!enabled;
		var sidebar = document.querySelector('.msgr-sidebar');
		var toggle = document.getElementById('msgr-roster-select-toggle');
		var bar = document.getElementById('msgr-roster-select-bar');
		var label = toggle ? toggle.querySelector('.msgr-btn-select-label') : null;
		var labels = cfg.labels || {};

		if (sidebar) {
			sidebar.classList.toggle('is-selecting', rosterSelectMode);
		}
		if (toggle) {
			toggle.setAttribute('aria-pressed', rosterSelectMode ? 'true' : 'false');
			toggle.classList.toggle('is-active', rosterSelectMode);
		}
		if (label) {
			label.textContent = rosterSelectMode
				? (labels.selectDone || 'Done')
				: (labels.selectChats || 'Select chats');
		}
		if (bar) {
			bar.hidden = !rosterSelectMode;
		}

		closeRosterMenu();

		var list = document.getElementById('msgr-roster');
		if (list) {
			list.querySelectorAll('.msgr-roster-item').forEach(function (item) {
				setRosterItemSelectMode(item, rosterSelectMode);
			});
		}

		updateRosterSelectCount();
	}

	function toggleRosterItemSelection(item, force) {
		if (!item || !rosterSelectMode) {
			return;
		}

		var input = item.querySelector('.msgr-roster-checkbox');
		var selected = typeof force === 'boolean' ? force : !item.classList.contains('is-selected');
		item.classList.toggle('is-selected', selected);
		if (input) {
			input.checked = selected;
		}
		updateRosterSelectCount();
	}

	function openDeleteSelectedChatsModal() {
		var items = getSelectedRosterItems();
		if (!items.length) {
			return;
		}

		var modal = document.getElementById('msgr-delete-modal');
		var title = document.getElementById('msgr-delete-modal-title');
		var cancelBtn = document.getElementById('msgr-delete-cancel');
		var meBtn = document.getElementById('msgr-delete-me');
		var bothBtn = document.getElementById('msgr-delete-both');
		var labels = cfg.labels || {};

		if (!modal || !title || !cancelBtn || !meBtn) {
			return;
		}

		var partnerIds = [];
		var groupIds = [];
		items.forEach(function (item) {
			var chat = getChatFromItem(item);
			if (chat.chat_type === 'group' && chat.group_id) {
				groupIds.push(chat.group_id);
			} else if (chat.partner_id) {
				partnerIds.push(chat.partner_id);
			}
		});

		modal.dataset.mode = 'chats';
		modal.dataset.partnerIds = partnerIds.join(',');
		modal.dataset.groupIds = groupIds.join(',');
		delete modal.dataset.partnerId;
		delete modal.dataset.msgId;

		title.textContent = (labels.deleteChatsConfirm || 'Delete %d selected chats?').replace('%d', items.length);
		cancelBtn.textContent = labels.cancel || 'Cancel';
		meBtn.textContent = labels.deleteMe || 'Delete for me';
		if (bothBtn) {
			bothBtn.textContent = labels.deleteBoth || 'Delete for both';
			var hideBoth = !partnerIds.length || !cfg.allowDeleteBoth;
			bothBtn.hidden = hideBoth;
			bothBtn.style.display = hideBoth ? 'none' : '';
		}

		modal.hidden = false;

		cancelBtn.onclick = function () {
			modal.hidden = true;
		};

		meBtn.onclick = function () {
			deleteSelectedChats(false);
			modal.hidden = true;
		};

		if (bothBtn) {
			bothBtn.onclick = function () {
				deleteSelectedChats(true);
				modal.hidden = true;
			};
		}
	}

	function deleteSelectedChats(forBoth) {
		var modal = document.getElementById('msgr-delete-modal');
		var partnerIds = [];
		var groupIds = [];

		if (modal) {
			(modal.dataset.partnerIds || '').split(',').forEach(function (id) {
				id = parseInt(id, 10);
				if (id > 0) {
					partnerIds.push(id);
				}
			});
			(modal.dataset.groupIds || '').split(',').forEach(function (id) {
				id = parseInt(id, 10);
				if (id > 0) {
					groupIds.push(id);
				}
			});
		}

		if (!partnerIds.length && !groupIds.length) {
			return;
		}

		var url = cfg.apiDeleteChats;
		if (!url) {
			return;
		}

		postJson(url, {
			partner_ids: partnerIds,
			group_ids: groupIds,
			for_both: forBoth ? 1 : 0
		}).then(function (payload) {
			if (!payload || !payload.success) {
				return;
			}

			var deletedPartners = payload.deleted_partners || partnerIds;
			var deletedGroups = payload.deleted_groups || groupIds;
			var activeDeleted = false;

			deletedPartners.forEach(function (partnerId) {
				var item = document.querySelector('.msgr-roster-item[data-partner-id="' + partnerId + '"]');
				if (item && item.parentNode) {
					item.parentNode.removeChild(item);
				}
				delete rosterChatCache['p:' + partnerId];
				if (getActivePartnerId() === parseInt(partnerId, 10)) {
					activeDeleted = true;
				}
			});

			deletedGroups.forEach(function (groupId) {
				var item = document.querySelector('.msgr-roster-item[data-group-id="' + groupId + '"]');
				if (item && item.parentNode) {
					item.parentNode.removeChild(item);
				}
				delete rosterChatCache['g:' + groupId];
				if (getActiveGroupId() === parseInt(groupId, 10)) {
					activeDeleted = true;
				}
			});

			setRosterSelectMode(false);
			showToast(cfg.labels && (cfg.labels.toastChatsDeleted || cfg.labels.toastDeleted));

			if (activeDeleted && cfg.rosterUrl) {
				window.location.href = cfg.rosterUrl;
				return;
			}

			pollMessenger();
		}).catch(function () {
			// ignore
		});
	}

	function initRosterSelect() {
		var toggle = document.getElementById('msgr-roster-select-toggle');
		var cancelBtn = document.getElementById('msgr-roster-select-cancel');
		var deleteBtn = document.getElementById('msgr-roster-select-delete');
		var list = document.getElementById('msgr-roster');

		if (toggle) {
			toggle.addEventListener('click', function () {
				setRosterSelectMode(!rosterSelectMode);
			});
		}
		if (cancelBtn) {
			cancelBtn.addEventListener('click', function () {
				setRosterSelectMode(false);
			});
		}
		if (deleteBtn) {
			deleteBtn.addEventListener('click', function () {
				openDeleteSelectedChatsModal();
			});
		}
		if (list) {
			list.addEventListener('click', function (event) {
				if (!rosterSelectMode) {
					return;
				}

				var item = event.target.closest('.msgr-roster-item');
				if (!item || !list.contains(item)) {
					return;
				}

				event.preventDefault();
				event.stopPropagation();
				toggleRosterItemSelection(item);
			});
		}

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && rosterSelectMode) {
				setRosterSelectMode(false);
			}
		});
	}

	function initRelativeTimes() {
		var labels = cfg.labels || {};

		function relativeLabel(diff) {
			if (diff < 60) {
				return labels.timeJustNow || 'less than a minute ago';
			}
			var minutes = Math.floor(diff / 60);
			if (minutes < 60) {
				return minutes === 1
					? (labels.timeMinuteAgo || '1 minute ago')
					: (labels.timeMinutesAgo || '%d minutes ago').replace('%d', minutes);
			}
			var hours = Math.floor(minutes / 60);
			if (hours < 24) {
				return hours === 1
					? (labels.timeHourAgo || '1 hour ago')
					: (labels.timeHoursAgo || '%d hours ago').replace('%d', hours);
			}
			var days = Math.floor(hours / 24);
			return days === 1
				? (labels.timeDayAgo || '1 day ago')
				: (labels.timeDaysAgo || '%d days ago').replace('%d', days);
		}

		function updateRelativeTimes() {
			var now = Math.floor(Date.now() / 1000);
			document.querySelectorAll('time[data-ts]').forEach(function (el) {
				var ts = parseInt(el.getAttribute('data-ts'), 10);
				if (!ts) {
					return;
				}
				var diff = Math.max(0, now - ts);

				// Only live-update timestamps that were recent at some point this
				// session; older ones keep their server-side formatting.
				if (el.getAttribute('data-rel') !== '1') {
					if (diff >= 3600) {
						return;
					}
					el.setAttribute('data-rel', '1');
				}

				var label = relativeLabel(diff);
				if (el.textContent !== label) {
					el.textContent = label;
				}
			});
		}

		updateRelativeTimes();
		window.setInterval(updateRelativeTimes, 30000);
	}

	function showToast(message) {
		var toast = document.getElementById('msgr-toast');
		if (!toast || !message) {
			return;
		}

		toast.textContent = message;
		toast.hidden = false;
		window.clearTimeout(toastTimer);
		toastTimer = window.setTimeout(function () {
			toast.hidden = true;
		}, 2400);
	}

	function closeRosterMenu() {
		var dropdown = document.getElementById('msgr-roster-dropdown');
		var sheet = document.getElementById('msgr-roster-sheet');
		var backdrop = document.getElementById('msgr-actions-backdrop');

		if (dropdown) {
			dropdown.hidden = true;
			dropdown.innerHTML = '';
		}
		if (sheet) {
			sheet.hidden = true;
		}
		if (backdrop) {
			backdrop.hidden = true;
		}
		if (rosterMenuItem) {
			rosterMenuItem.classList.remove('is-menu-open');
			rosterMenuItem = null;
		}
		rosterMenuState = null;
	}

	function buildActionButtons(chat) {
		var labels = cfg.labels || {};
		var actions = [];

		if (chat.chat_type === 'group') {
			actions.push({
				id: 'delete',
				label: labels.groupLeave || labels.deleteChat || 'Delete',
				icon: 'fa-trash',
				danger: true
			});
			return actions;
		}

		actions.push({
			id: 'pin',
			label: chat.is_pinned ? (labels.unpin || 'Unpin') : (labels.pin || 'Pin'),
			icon: 'fa-thumb-tack',
			danger: false
		});

		if ((chat.unread_count || 0) > 0) {
			actions.push({
				id: 'read',
				label: labels.markRead || 'Mark as read',
				icon: 'fa-check',
				danger: false
			});
		}

		actions.push({
			id: 'delete',
			label: labels.deleteChat || 'Delete',
			icon: 'fa-trash',
			danger: true
		});

		return actions;
	}

	function handleRosterAction(actionId) {
		if (!rosterMenuState) {
			return;
		}

		var chat = rosterMenuState;
		closeRosterMenu();

		if (actionId === 'pin') {
			toggleChatPin(chat);
		} else if (actionId === 'read') {
			markChatRead(chat);
		} else if (actionId === 'delete') {
			openDeleteModal(chat);
		}
	}

	function renderMenuActions(container, chat, role) {
		container.innerHTML = '';
		buildActionButtons(chat).forEach(function (action) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.setAttribute('role', role);
			btn.className = action.danger ? 'is-danger' : '';
			btn.innerHTML = '<i class="icon ' + action.icon + ' fa-fw" aria-hidden="true"></i><span></span>';
			btn.querySelector('span').textContent = action.label;
			btn.addEventListener('click', function () {
				handleRosterAction(action.id);
			});
			container.appendChild(btn);
		});
	}

	function openRosterMenu(item, mode) {
		syncBottomNavHeight();

		var chat = getChatFromItem(item);
		if (!chat || (!chat.partner_id && !chat.group_id)) {
			return;
		}

		closeRosterMenu();
		rosterMenuState = chat;
		rosterMenuItem = item;
		item.classList.add('is-menu-open');

		var backdrop = document.getElementById('msgr-actions-backdrop');
		var dropdown = document.getElementById('msgr-roster-dropdown');
		var sheet = document.getElementById('msgr-roster-sheet');
		var sheetTitle = document.getElementById('msgr-roster-sheet-title');
		var sheetActions = document.getElementById('msgr-roster-sheet-actions');
		var isMobile = window.matchMedia('(max-width: 899px)').matches;

		if (isMobile || mode === 'sheet') {
			if (backdrop) {
				backdrop.hidden = false;
			}
			if (sheet && sheetActions) {
				if (sheetTitle) {
					sheetTitle.textContent = chat.username || '';
				}
				renderMenuActions(sheetActions, chat, 'menuitem');
				sheet.hidden = false;
			}
			return;
		}

		if (dropdown) {
			renderMenuActions(dropdown, chat, 'menuitem');
			dropdown.hidden = false;
			var btn = item.querySelector('.msgr-roster-menu-btn');
			var rect = btn ? btn.getBoundingClientRect() : item.getBoundingClientRect();
			var top = rect.bottom + 6;
			var left = Math.max(8, rect.right - 210);
			if (top + 160 > window.innerHeight) {
				top = Math.max(8, rect.top - 160);
			}
			dropdown.style.top = top + 'px';
			dropdown.style.left = left + 'px';
		}
	}

	function toggleChatPin(chat) {
		var url = partnerApiUrl(cfg.apiPinChat, chat.partner_id);
		if (!url) {
			return;
		}

		postJson(url, {}).then(function (payload) {
			if (!payload || !payload.success) {
				return;
			}

			chat.is_pinned = !!payload.pinned;
			rosterChatCache[getRosterKey(chat)] = chat;
			var item = document.querySelector('.msgr-roster-item[data-partner-id="' + chat.partner_id + '"]');
			if (item) {
				applyRosterItemState(item, chat, getActivePartnerId());
				var list = document.getElementById('msgr-roster');
				if (list) {
					list.appendChild(item);
				}
			}

			showToast(chat.is_pinned ? (cfg.labels && cfg.labels.toastPinned) : (cfg.labels && cfg.labels.toastUnpinned));
			pollMessenger();
		}).catch(function () {
			// ignore
		});
	}

	function markChatRead(chat) {
		var url = partnerApiUrl(cfg.apiReadTemplate, chat.partner_id);
		if (!url) {
			return;
		}

		postJson(url, {}).then(function (payload) {
			handleMarkReadResponse(payload);
			if (!payload || !payload.success) {
				return;
			}

			chat.unread_count = 0;
			rosterChatCache[getRosterKey(chat)] = chat;
			var item = document.querySelector('.msgr-roster-item[data-partner-id="' + chat.partner_id + '"]');
			if (item) {
				applyRosterItemState(item, chat, getActivePartnerId());
			}
			showToast(cfg.labels && cfg.labels.toastRead);
			pollMessenger();
		}).catch(function () {
			// ignore
		});
	}

	function openDeleteModal(chat) {
		var modal = document.getElementById('msgr-delete-modal');
		var title = document.getElementById('msgr-delete-modal-title');
		var cancelBtn = document.getElementById('msgr-delete-cancel');
		var meBtn = document.getElementById('msgr-delete-me');
		var bothBtn = document.getElementById('msgr-delete-both');
		var labels = cfg.labels || {};

		if (!modal || !title || !cancelBtn || !meBtn) {
			return;
		}

		var isGroup = chat.chat_type === 'group';

		modal.dataset.mode = isGroup ? 'group' : 'chat';
		modal.dataset.partnerId = String(chat.partner_id || 0);
		delete modal.dataset.msgId;

		title.textContent = (labels.deleteConfirm || 'Delete %s?').replace('%s', chat.username || '');
		cancelBtn.textContent = labels.cancel || 'Cancel';
		meBtn.textContent = isGroup
			? (labels.groupLeave || labels.deleteMe || 'Delete')
			: (labels.deleteMe || 'Delete for me');
		if (bothBtn) {
			bothBtn.textContent = labels.deleteBoth || 'Delete for both';
			var hideBoth = isGroup || !cfg.allowDeleteBoth;
			bothBtn.hidden = hideBoth;
			// phpBB button CSS (display: inline-block) overrules the hidden attribute.
			bothBtn.style.display = hideBoth ? 'none' : '';
		}

		modal.hidden = false;

		cancelBtn.onclick = function () {
			modal.hidden = true;
		};

		meBtn.onclick = function () {
			if (isGroup) {
				deleteGroupChat(chat.group_id);
			} else {
				deleteChat(chat.partner_id, false);
			}
			modal.hidden = true;
		};

		if (bothBtn) {
			bothBtn.onclick = function () {
				deleteChat(chat.partner_id, true);
				modal.hidden = true;
			};
		}
	}

	function deleteGroupChat(groupId) {
		var url = partnerApiUrl(cfg.apiGroupDelete, groupId);
		if (!url || !groupId) {
			return;
		}

		postJson(url, {}).then(function (payload) {
			if (!payload || !payload.success) {
				return;
			}

			var item = document.querySelector('.msgr-roster-item[data-group-id="' + groupId + '"]');
			if (item && item.parentNode) {
				item.parentNode.removeChild(item);
			}
			delete rosterChatCache['g:' + groupId];
			showToast(cfg.labels && cfg.labels.toastDeleted);

			if (getActiveGroupId() === parseInt(groupId, 10) && cfg.rosterUrl) {
				window.location.href = cfg.rosterUrl;
				return;
			}

			pollMessenger();
		}).catch(function () {
			// ignore
		});
	}

	function deleteChat(partnerId, forBoth) {
		var url = partnerApiUrl(cfg.apiDeleteChat, partnerId);
		if (!url || !parseInt(partnerId, 10)) {
			return;
		}

		postJson(url, { for_both: forBoth ? 1 : 0 }).then(function (payload) {
			if (!payload || !payload.success) {
				return;
			}

			var item = document.querySelector('.msgr-roster-item[data-partner-id="' + partnerId + '"]');
			if (item && item.parentNode) {
				item.parentNode.removeChild(item);
			}
			delete rosterChatCache['p:' + partnerId];
			showToast(cfg.labels && cfg.labels.toastDeleted);

			if (getActivePartnerId() === parseInt(partnerId, 10) && cfg.rosterUrl) {
				window.location.href = cfg.rosterUrl;
				return;
			}

			pollMessenger();
		}).catch(function () {
			// ignore
		});
	}

	function initRosterActions() {
		syncBottomNavHeight();

		var list = document.getElementById('msgr-roster');
		var backdrop = document.getElementById('msgr-actions-backdrop');
		var modal = document.getElementById('msgr-delete-modal');

		if (list) {
			list.querySelectorAll('.msgr-roster-item').forEach(function (item) {
				if (!item.querySelector('.msgr-roster-menu-btn')) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'msgr-roster-menu-btn';
					btn.setAttribute('aria-haspopup', 'menu');
					if (cfg.labels && cfg.labels.chatActions) {
						btn.setAttribute('aria-label', cfg.labels.chatActions);
					}
					btn.innerHTML = '<i class="icon fa-ellipsis-v fa-fw" aria-hidden="true"></i>';
					item.appendChild(btn);
				}
			});

			list.addEventListener('click', function (event) {
				if (rosterSelectMode) {
					return;
				}
				var menuBtn = event.target.closest('.msgr-roster-menu-btn');
				if (menuBtn) {
					event.preventDefault();
					event.stopPropagation();
					var mode = window.matchMedia('(max-width: 899px)').matches ? 'sheet' : 'dropdown';
					openRosterMenu(menuBtn.closest('.msgr-roster-item'), mode);
					return;
				}
			});
		}

		if (backdrop) {
			backdrop.addEventListener('click', closeRosterMenu);
		}

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeRosterMenu();
				if (modal) {
					modal.hidden = true;
				}
			}
		});

		document.addEventListener('click', function (event) {
			var dropdown = document.getElementById('msgr-roster-dropdown');
			if (!dropdown || dropdown.hidden) {
				return;
			}
			if (event.target.closest('#msgr-roster-dropdown') || event.target.closest('.msgr-roster-menu-btn')) {
				return;
			}
			closeRosterMenu();
		});

		if (modal) {
			modal.addEventListener('click', function (event) {
				if (event.target === modal) {
					modal.hidden = true;
				}
			});
		}

		if (list) {
			var initial = [];
			list.querySelectorAll('.msgr-roster-item[data-partner-id]').forEach(function (item) {
				initial.push(getChatFromItem(item));
			});
			cacheRosterChats(initial);
		}
	}

	function bumpRosterAfterSend(partnerId, messageText) {
		var list = document.getElementById('msgr-roster');
		if (!list) {
			return;
		}

		var item = list.querySelector('[data-partner-id="' + partnerId + '"]');
		if (!item) {
			pollMessenger();
			return;
		}

		var preview = item.querySelector('.msgr-preview');
		if (preview) {
			preview.textContent = (cfg.youPrefix || 'You: ') + messageText;
		}

		item.classList.remove('has-unread');
		setRosterBadge(item.querySelector('.msgr-roster-side'), 0);

		if (item.classList.contains('is-pinned')) {
			list.insertBefore(item, list.firstChild);
			return;
		}

		var insertBefore = null;
		var nodes = list.querySelectorAll('.msgr-roster-item');
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i] !== item && !nodes[i].classList.contains('is-pinned')) {
				insertBefore = nodes[i];
				break;
			}
		}

		if (insertBefore) {
			list.insertBefore(item, insertBefore);
		} else {
			list.appendChild(item);
		}
	}

	function removeMessageFromDom(msgId) {
		var node = document.querySelector('.msgr-message[data-msg-id="' + msgId + '"]');
		if (node && node.parentNode) {
			node.parentNode.removeChild(node);
		}
	}

	function getLoadedMsgIdBounds() {
		var box = document.getElementById('msgr-messages');
		if (!box) {
			return null;
		}

		var min = 0;
		var max = 0;
		var found = false;

		box.querySelectorAll('.msgr-message[data-msg-id]').forEach(function (node) {
			var id = parseInt(node.getAttribute('data-msg-id'), 10);
			if (id <= 0) {
				return;
			}

			if (!found) {
				min = id;
				max = id;
				found = true;
			} else {
				min = Math.min(min, id);
				max = Math.max(max, id);
			}
		});

		return found ? { min: min, max: max } : null;
	}

	function syncVisibleMessages(serverVisibleIds) {
		var visible = {};
		(serverVisibleIds || []).forEach(function (id) {
			visible[parseInt(id, 10)] = true;
		});

		var box = document.getElementById('msgr-messages');
		if (!box) {
			return;
		}

		box.querySelectorAll('.msgr-message[data-msg-id]').forEach(function (node) {
			var id = parseInt(node.getAttribute('data-msg-id'), 10);
			if (id > 0 && !visible[id] && node.parentNode) {
				node.parentNode.removeChild(node);
			}
		});
	}

	function pollMessenger() {
		if (!cfg.apiPoll) {
			return;
		}

		var params = ['_=' + Date.now()];
		if (cfg.chatType === 'group' && cfg.groupId) {
			params.push('group_id=' + encodeURIComponent(cfg.groupId));
			params.push('since=' + encodeURIComponent(lastMsgId));
		} else if (cfg.partnerId) {
			params.push('partner_id=' + encodeURIComponent(cfg.partnerId));
			params.push('since=' + encodeURIComponent(lastMsgId));
			params.push('since_edit=' + encodeURIComponent(lastEditSync));

			var bounds = getLoadedMsgIdBounds();
			if (bounds) {
				params.push('sync_min=' + encodeURIComponent(bounds.min));
				params.push('sync_max=' + encodeURIComponent(bounds.max));
			}
		}

		var url = cfg.apiPoll + '?' + params.join('&');

		getJson(url).then(function (payload) {
			if (!payload) {
				return;
			}
			if (payload.sync_messages) {
				syncVisibleMessages(payload.visible_msg_ids);
			}
			if (payload.updated_messages && payload.updated_messages.length) {
				payload.updated_messages.forEach(applyUpdatedMessage);
				lastEditSync = Math.floor(Date.now() / 1000);
			}
			if (payload.roster) {
				updateRoster(payload.roster);
				updatePartnerPresence(payload.roster);
			}
			if (payload.messages) {
				payload.messages.forEach(appendMessage);
			}
			if (payload.read_statuses) {
				updateReadStatuses(payload.read_statuses);
			}
			updateTypingIndicator(payload);
		}).catch(function () {
			// ignore polling errors
		});
	}

	function startPolling() {
		var interval = parseInt(cfg.pollInterval || '8', 10) * 1000;
		if (mode === 'chat' || mode === 'group') {
			interval = Math.min(interval, 4000);
		}
		if (pollTimer) {
			window.clearInterval(pollTimer);
		}
		pollMessenger();
		pollTimer = window.setInterval(pollMessenger, interval);

		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) {
				pollMessenger();
			}
		});
	}

	function closeComposeToolsPanel() {
		var panel = document.getElementById('msgr-compose-tools-panel');
		var btn = document.getElementById('msgr-attach-btn');
		if (!panel) {
			return;
		}
		panel.classList.remove('is-open');
		if (btn) {
			btn.setAttribute('aria-expanded', 'false');
		}
	}

	function closeEmojiPanel() {
		var panel = document.getElementById('msgr-emoji-panel');
		var btn = document.getElementById('msgr-emoji-btn');
		if (!panel) {
			return;
		}
		panel.hidden = true;
		if (btn) {
			btn.setAttribute('aria-expanded', 'false');
		}
	}

	function closeGiphyPanel() {
		var panel = document.getElementById('msgr-giphy-panel');
		var btn = document.getElementById('msgr-giphy-btn');
		if (!panel) {
			return;
		}
		panel.hidden = true;
		if (btn) {
			btn.setAttribute('aria-expanded', 'false');
		}
	}

	function initBbcodeTools() {
		var input = document.getElementById('msgr-input');
		if (!input) {
			return;
		}

		document.querySelectorAll('.msgr-tool-btn[data-bbcode]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var tag = btn.getAttribute('data-bbcode');
				var start = input.selectionStart || 0;
				var end = input.selectionEnd || 0;
				var selected = input.value.substring(start, end);
				var insert = '';

				if (tag === 'url') {
					var url = window.prompt('URL', 'https://');
					if (!url) {
						return;
					}
					insert = selected ? '[url=' + url + ']' + selected + '[/url]' : '[url]' + url + '[/url]';
				} else if (tag === 'quote') {
					var cite = (cfg.partnerName || '').trim();
					insert = cite
						? '[quote=' + cite + ']' + (selected || '') + '[/quote]'
						: '[quote]' + (selected || '') + '[/quote]';
				} else {
					insert = '[' + tag + ']' + (selected || '') + '[/' + tag + ']';
				}

				insertAtCursor(input, insert);
				btn.blur();
			});
		});
	}

	function initComposeAttach() {
		var btn = document.getElementById('msgr-attach-btn');
		var panel = document.getElementById('msgr-compose-tools-panel');
		if (!btn || !panel) {
			return;
		}

		btn.hidden = false;

		btn.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();

			var willOpen = !panel.classList.contains('is-open');
			closeEmojiPanel();
			closeGiphyPanel();

			if (willOpen) {
				panel.classList.add('is-open');
				btn.setAttribute('aria-expanded', 'true');
			} else {
				closeComposeToolsPanel();
			}

			btn.blur();
		});

		document.addEventListener('click', function (event) {
			if (!panel.classList.contains('is-open')) {
				return;
			}

			if (panel.contains(event.target) || btn.contains(event.target)) {
				return;
			}

			closeComposeToolsPanel();
		});
	}

	function initEmojiPicker() {
		var btn = document.getElementById('msgr-emoji-btn');
		var panel = document.getElementById('msgr-emoji-panel');
		var input = document.getElementById('msgr-input');
		var smilies = cfg.smilies || [];

		if (!btn || !panel || !input) {
			return;
		}

		if (!smilies.length) {
			btn.hidden = true;
			return;
		}

		btn.hidden = false;
		panel.innerHTML = smilies.map(function (smiley) {
			return '<button type="button" class="msgr-emoji-item" data-code="' + escapeAttr(smiley.code) + '" title="' + escapeAttr(smiley.code) + '">' +
				'<img src="' + escapeAttr(smiley.url) + '" width="' + (smiley.width || 20) + '" height="' + (smiley.height || 20) + '" alt="' + escapeAttr(smiley.code) + '" loading="lazy">' +
			'</button>';
		}).join('');

		function closePanel() {
			closeEmojiPanel();
		}

		function openPanel() {
			closeComposeToolsPanel();
			closeGiphyPanel();
			panel.hidden = false;
			btn.setAttribute('aria-expanded', 'true');
		}

		btn.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			if (panel.hidden) {
				openPanel();
			} else {
				closePanel();
			}
			btn.blur();
		});

		panel.addEventListener('click', function (event) {
			var item = event.target.closest('.msgr-emoji-item');
			if (!item) {
				return;
			}

			insertAtCursor(input, item.getAttribute('data-code') || '');
			closePanel();
		});

		document.addEventListener('click', function (event) {
			if (panel.hidden) {
				return;
			}

			if (panel.contains(event.target) || btn.contains(event.target)) {
				return;
			}

			closePanel();
		});
	}

	function initGiphyPicker() {
		var giphy = cfg.giphy || {};
		var btn = document.getElementById('msgr-giphy-btn');
		var panel = document.getElementById('msgr-giphy-panel');
		var viewport = document.getElementById('msgr-giphy-viewport');
		var searchInput = document.getElementById('msgr-giphy-search');
		var moreBtn = document.getElementById('msgr-giphy-more');
		var lessBtn = document.getElementById('msgr-giphy-less');
		var input = document.getElementById('msgr-input');
		var labels = cfg.labels || {};
		var offset = 0;
		var endpoint = 'gifs';
		var limit = Math.max(1, Math.min(50, parseInt(giphy.limit, 10) || 25));
		var searchTimer = null;
		var requestId = 0;

		if (!btn || !panel || !viewport || !searchInput || !input || !giphy.enabled) {
			if (btn && !giphy.enabled) {
				btn.hidden = true;
			}
			return;
		}

		btn.hidden = false;

		function setStatus(message) {
			viewport.innerHTML = '<div class="msgr-giphy-status">' + escapeAttr(message || '') + '</div>';
			if (moreBtn) {
				moreBtn.hidden = true;
			}
			if (lessBtn) {
				lessBtn.hidden = true;
			}
		}

		function updateNav(hasResults) {
			if (!moreBtn || !lessBtn) {
				return;
			}
			var query = (searchInput.value || '').trim();
			lessBtn.hidden = !(hasResults && offset >= limit);
			moreBtn.hidden = !(hasResults && query !== '');
		}

		function buildInsertText(url) {
			if (!url) {
				return '';
			}
			if (giphy.autoImage) {
				return ' ' + url + ' ';
			}
			return ' [url=' + url + '][img]' + url + '[/img][/url] ';
		}

		function renderResults(items) {
			if (!items.length) {
				setStatus(labels.giphyEmpty || 'No results.');
				return;
			}

			var miniClass = giphy.miniViewport ? ' msgr-giphy-mini' : '';
			viewport.innerHTML = items.map(function (item) {
				return '<button type="button" class="msgr-giphy-item' + miniClass + '" data-url="' + escapeAttr(item.url) + '" title="GIF">' +
					'<img src="' + escapeAttr(item.still) + '" data-animate="' + escapeAttr(item.url) + '" data-still="' + escapeAttr(item.still) + '" alt="" loading="lazy">' +
				'</button>';
			}).join('');
			updateNav(true);
		}

		function fetchGiphy() {
			var query = (searchInput.value || '').trim();
			if (!giphy.apiKey) {
				setStatus(labels.giphyNoKey || 'Giphy API key is missing.');
				return;
			}
			if (!query) {
				viewport.innerHTML = '';
				updateNav(false);
				return;
			}

			var currentRequest = ++requestId;
			setStatus(labels.searching || 'Searching…');

			var url = 'https://api.giphy.com/v1/' + endpoint + '/search'
				+ '?q=' + encodeURIComponent(query)
				+ '&api_key=' + encodeURIComponent(giphy.apiKey)
				+ '&rating=' + encodeURIComponent(giphy.rating || 'g')
				+ '&lang=' + encodeURIComponent(giphy.lang || 'en')
				+ '&offset=' + offset
				+ '&limit=' + limit;

			fetch(url).then(function (response) {
				if (!response.ok) {
					throw new Error('giphy');
				}
				return response.json();
			}).then(function (payload) {
				if (currentRequest !== requestId) {
					return;
				}
				var data = (payload && payload.data) || [];
				var items = data.map(function (item) {
					var images = item.images || {};
					var still = (images.fixed_height_still && images.fixed_height_still.url) || '';
					var animated = giphy.original
						? ((images.original && images.original.webp) || (images.original && images.original.url) || '')
						: ((images.fixed_height && images.fixed_height.webp) || (images.fixed_height && images.fixed_height.url) || '');
					return {
						still: still || animated,
						url: animated || still
					};
				}).filter(function (item) {
					return item.url;
				});
				renderResults(items);
			}).catch(function () {
				if (currentRequest !== requestId) {
					return;
				}
				setStatus(labels.giphyFailed || 'Failed to load GIFs.');
			});
		}

		function openPanel() {
			closeComposeToolsPanel();
			closeEmojiPanel();
			panel.hidden = false;
			btn.setAttribute('aria-expanded', 'true');
			window.setTimeout(function () {
				searchInput.focus();
			}, 0);
		}

		function closePanel() {
			closeGiphyPanel();
		}

		btn.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			if (panel.hidden) {
				openPanel();
			} else {
				closePanel();
			}
			btn.blur();
		});

		panel.querySelectorAll('.msgr-giphy-mode').forEach(function (modeBtn) {
			modeBtn.addEventListener('click', function () {
				endpoint = modeBtn.getAttribute('data-giphy-mode') || 'gifs';
				panel.querySelectorAll('.msgr-giphy-mode').forEach(function (el) {
					el.classList.toggle('is-active', el === modeBtn);
				});
				offset = 0;
				fetchGiphy();
			});
		});

		searchInput.addEventListener('input', function () {
			offset = 0;
			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(fetchGiphy, 280);
		});

		searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				offset = 0;
				fetchGiphy();
			}
		});

		if (moreBtn) {
			moreBtn.addEventListener('click', function () {
				offset += limit;
				fetchGiphy();
			});
		}

		if (lessBtn) {
			lessBtn.addEventListener('click', function () {
				offset = Math.max(0, offset - limit);
				fetchGiphy();
			});
		}

		viewport.addEventListener('click', function (event) {
			var item = event.target.closest('.msgr-giphy-item');
			if (!item) {
				return;
			}
			insertAtCursor(input, buildInsertText(item.getAttribute('data-url') || ''));
			closePanel();
		});

		viewport.addEventListener('mouseover', function (event) {
			var img = event.target.closest('.msgr-giphy-item img');
			if (!img) {
				return;
			}
			img.src = img.getAttribute('data-animate') || img.src;
		});

		viewport.addEventListener('mouseout', function (event) {
			var img = event.target.closest('.msgr-giphy-item img');
			if (!img) {
				return;
			}
			img.src = img.getAttribute('data-still') || img.src;
		});

		document.addEventListener('click', function (event) {
			if (panel.hidden) {
				return;
			}
			if (panel.contains(event.target) || btn.contains(event.target)) {
				return;
			}
			closePanel();
		});
	}

	function initComposeInputAutoGrow(input) {
		if (!input) {
			return function () {};
		}

		var maxHeight = 120;

		function resizeInput() {
			maxHeight = 120;
			input.style.height = 'auto';
			var nextHeight = Math.min(input.scrollHeight, maxHeight);
			if (window.matchMedia('(min-width: 900px)').matches) {
				nextHeight = input.value.trim() ? Math.max(24, nextHeight) : 24;
			}
			input.style.height = nextHeight + 'px';
			input.style.overflowY = input.scrollHeight > maxHeight ? 'auto' : 'hidden';
		}

		input.addEventListener('input', resizeInput);
		window.addEventListener('resize', resizeInput);
		resizeInput();

		return resizeInput;
	}

	function initComposeImageUpload(onChange) {
		if (mode !== 'chat') {
			return;
		}

		var fileInput = document.getElementById('msgr-upload-input');
		if (!fileInput) {
			return;
		}

		var uploadBtn = document.getElementById('msgr-upload-btn');
		var uploading = false;

		function setBusy(isBusy) {
			uploading = isBusy;
			if (uploadBtn) {
				uploadBtn.classList.toggle('is-busy', isBusy);
				uploadBtn.setAttribute('aria-busy', isBusy ? 'true' : 'false');
			}
		}

		fileInput.addEventListener('change', function () {
			var file = fileInput.files && fileInput.files[0];
			fileInput.value = '';
			if (!file || uploading) {
				return;
			}

			if (!cfg.apiUpload || !cfg.uploadHash) {
				window.alert((cfg.labels && cfg.labels.uploadFailed) || 'Upload is not available.');
				return;
			}

			if (!file.type || file.type.indexOf('image/') !== 0) {
				window.alert((cfg.labels && cfg.labels.uploadImagesOnly) || 'Only images are allowed.');
				return;
			}

			setBusy(true);

			readFileAsUploadPayload(file).then(function (payloadData) {
				if (!payloadData.base64) {
					window.alert(uploadErrorMessage(null, (cfg.labels && cfg.labels.uploadFailed) || 'Upload failed.'));
					return;
				}

				var formData = new FormData();
				formData.append('hash', cfg.uploadHash);
				formData.append('image_filename', payloadData.filename);
				formData.append('image_data', payloadData.base64);

				return postFormData(cfg.apiUpload, formData);
			}).then(function (payload) {
				if (!payload) {
					return;
				}

				if (!payload.success || !payload.attach_id) {
					window.alert(uploadErrorMessage(payload, (cfg.labels && cfg.labels.uploadFailed) || 'Upload failed.'));
					return;
				}

				if (typeof onChange === 'function') {
					onChange({
						attach_id: payload.attach_id,
						preview_url: payload.preview_url || '',
						real_filename: payload.real_filename || file.name
					});
				}
			}).catch(function (payload) {
				window.alert(uploadErrorMessage(payload, (cfg.labels && cfg.labels.uploadFailed) || 'Upload failed.'));
			}).finally(function () {
				setBusy(false);
			});
		});
	}

	function renderComposeAttachments(pendingAttachments) {
		var wrap = document.getElementById('msgr-compose-attachments');
		if (!wrap) {
			return;
		}

		wrap.innerHTML = '';
		if (!pendingAttachments.length) {
			wrap.hidden = true;
			return;
		}

		wrap.hidden = false;
		pendingAttachments.forEach(function (attachment) {
			var item = document.createElement('div');
			item.className = 'msgr-compose-attachment';
			item.dataset.attachId = String(attachment.attach_id);

			var img = document.createElement('img');
			img.src = attachment.preview_url;
			img.alt = attachment.real_filename || '';
			item.appendChild(img);

			var removeBtn = document.createElement('button');
			removeBtn.type = 'button';
			removeBtn.className = 'msgr-compose-attachment-remove';
			removeBtn.setAttribute('aria-label', (cfg.labels && cfg.labels.removeAttachment) || 'Remove');
			removeBtn.innerHTML = '&times;';
			removeBtn.addEventListener('click', function () {
				var index = pendingAttachments.indexOf(attachment);
				if (index !== -1) {
					pendingAttachments.splice(index, 1);
				}
				renderComposeAttachments(pendingAttachments);
			});
			item.appendChild(removeBtn);
			wrap.appendChild(item);
		});
	}

	function getPendingAttachmentIds(pendingAttachments) {
		return pendingAttachments
			.filter(function (attachment) {
				return attachment && attachment.attach_id && !attachment._removed;
			})
			.map(function (attachment) {
				return attachment.attach_id;
			});
	}

	function initComposeForm() {
		var form = document.getElementById('msgr-compose-form');
		var input = document.getElementById('msgr-input');
		if (!form || !input || !cfg.apiSend) {
			return;
		}

		var activeId = cfg.chatType === 'group' ? cfg.groupId : cfg.partnerId;
		if (!activeId) {
			return;
		}

		var resizeInput = initComposeInputAutoGrow(input);
		var payloadKey = cfg.chatType === 'group' ? 'group_id' : 'partner_id';
		var sending = false;
		var pendingAttachments = [];

		initComposeImageUpload(function (attachment) {
			pendingAttachments.push(attachment);
			renderComposeAttachments(pendingAttachments);
			closeComposeToolsPanel();
			input.focus();
		});

		// Prefill with a forum post quote (PM button on a post).
		if (cfg.prefillMessage && !input.value) {
			input.value = cfg.prefillMessage;
			resizeInput();
			input.focus();
			input.setSelectionRange(input.value.length, input.value.length);

			// Drop the quote_post parameter so a refresh doesn't re-insert the quote.
			if (window.history && window.history.replaceState) {
				var cleanedUrl = window.location.href
					.replace(/([?&])quote_post=\d+(&?)/, function (m, sep, amp) {
						return amp ? sep : '';
					})
					.replace(/[?&]$/, '');
				window.history.replaceState(null, '', cleanedUrl);
			}
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			if (sending) {
				return;
			}

			var text = input.value.trim();
			var attachmentIds = getPendingAttachmentIds(pendingAttachments);
			if (!text && !attachmentIds.length) {
				return;
			}

			var sentText = text;
			var sentAttachments = pendingAttachments.slice();

			sending = true;
			stopTypingHeartbeat();

			// Clear the input right away so a second Enter cannot resend the same text.
			input.value = '';
			resizeInput();
			pendingAttachments = [];
			renderComposeAttachments(pendingAttachments);

			var sendBtn = form.querySelector('.msgr-send-btn');
			if (sendBtn) {
				sendBtn.disabled = true;
			}

			var payload = {
				message: text,
				hash: cfg.sendHash || ''
			};
			if (attachmentIds.length) {
				payload.attachment_ids = attachmentIds;
			}
			payload[payloadKey] = activeId;

			postJson(cfg.apiSend, payload).then(function (response) {
				if (response && response.success && response.message) {
					appendMessage(response.message);
					if (cfg.chatType !== 'group') {
						bumpRosterAfterSend(cfg.partnerId, text);
					}
					scrollMessagesToBottom();
				} else {
					restoreInput();
					if (response && response.error) {
						window.alert(response.error);
					}
				}
			}).catch(function (response) {
				restoreInput();
				if (response && response.error) {
					window.alert(response.error);
				}
			}).finally(function () {
				sending = false;
				if (sendBtn) {
					sendBtn.disabled = false;
				}
				input.focus();
			});

			function restoreInput() {
				if (input.value.trim() === '' && !getPendingAttachmentIds(pendingAttachments).length) {
					input.value = sentText;
					resizeInput();
					pendingAttachments = sentAttachments.map(function (attachment) {
						var copy = Object.assign({}, attachment);
						delete copy._removed;
						return copy;
					});
					renderComposeAttachments(pendingAttachments);
				}
			}
		});

		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && !event.shiftKey) {
				event.preventDefault();
				form.dispatchEvent(new Event('submit', { cancelable: true }));
			}
		});
	}

	function initComposeMemberPicker() {
		var input = document.getElementById('msgr-member-query');
		var list = document.getElementById('msgr-member-suggestions');
		var selectedWrap = document.getElementById('msgr-member-selected');
		var selectedAvatar = document.getElementById('msgr-member-selected-avatar');
		var selectedName = document.getElementById('msgr-member-selected-name');
		var clearBtn = document.getElementById('msgr-member-clear');
		var startBtn = document.getElementById('msgr-start-chat');
		var picker = document.getElementById('msgr-member-picker');

		if (!input || !list || !cfg.apiMembers) {
			return;
		}

		var timer = null;
		var selectedMember = null;
		var activeIndex = -1;
		var currentMembers = [];

		function hideSuggestions() {
			list.hidden = true;
			list.innerHTML = '';
			activeIndex = -1;
			currentMembers = [];
		}

		function buildChatUrl(member) {
			if (member && member.chat_url) {
				return member.chat_url;
			}
			if (cfg.chatUrlTemplate && member) {
				var id = String(member.user_id);
				if (cfg.chatUrlTemplate.indexOf('partner_id=') !== -1) {
					return cfg.chatUrlTemplate.replace(/([?&]partner_id=)0(?!\d)/, '$1' + id);
				}
				return cfg.chatUrlTemplate.replace(/0(?=[^0-9]*$)/, id);
			}
			return '';
		}

		function goToChat(member) {
			var url = buildChatUrl(member);
			if (url) {
				window.location.href = url;
			}
		}

		function findExactMember(members, query) {
			var needle = query.toLowerCase();
			for (var i = 0; i < members.length; i++) {
				if ((members[i].username || '').toLowerCase() === needle) {
					return members[i];
				}
			}
			return null;
		}

		function renderSuggestions(members) {
			currentMembers = members || [];
			list.innerHTML = '';

			if (!currentMembers.length) {
				var empty = document.createElement('li');
				empty.className = 'msgr-member-empty';
				empty.textContent = cfg.noMembersLabel || 'No members found.';
				list.appendChild(empty);
				list.hidden = false;
				return;
			}

			currentMembers.forEach(function (member, index) {
				var item = document.createElement('li');
				item.className = 'msgr-member-suggestion';
				item.setAttribute('role', 'option');
				item.setAttribute('data-index', String(index));

				var avatarWrap = document.createElement('div');
				avatarWrap.className = 'msgr-avatar-wrap';
				avatarWrap.innerHTML = member.avatar || '<span class="msgr-avatar-fallback"><i class="icon fa-user fa-fw"></i></span>';

				var nameSpan = document.createElement('span');
				nameSpan.className = 'msgr-member-name';
				nameSpan.textContent = member.username || '';
				if (member.user_colour) {
					nameSpan.style.color = '#' + member.user_colour;
				}

				item.appendChild(avatarWrap);
				item.appendChild(nameSpan);

				item.addEventListener('mousedown', function (event) {
					event.preventDefault();
					goToChat(member);
				});

				list.appendChild(item);
			});

			list.hidden = false;
			activeIndex = -1;
		}

		function selectMember(member) {
			selectedMember = member;
			hideSuggestions();
			input.value = '';
			input.hidden = true;

			selectedAvatar.innerHTML = member.avatar || '<span class="msgr-avatar-fallback"><i class="icon fa-user fa-fw"></i></span>';
			selectedName.textContent = member.username;
			if (member.user_colour) {
				selectedName.style.color = '#' + member.user_colour;
			} else {
				selectedName.style.color = '';
			}

			selectedWrap.hidden = false;
			if (startBtn) {
				startBtn.disabled = false;
			}
		}

		function clearSelection() {
			selectedMember = null;
			selectedWrap.hidden = true;
			input.hidden = false;
			input.value = '';
			input.focus();
			if (startBtn) {
				startBtn.disabled = true;
			}
		}

		function fetchMembers(submitExact) {
			var q = input.value.trim();
			if (q.length < 1) {
				hideSuggestions();
				return;
			}

			getJson(cfg.apiMembers + '?q=' + encodeURIComponent(q)).then(function (payload) {
				var members = payload && payload.members ? payload.members : [];
				if (submitExact) {
					var exact = findExactMember(members, q);
					if (exact) {
						goToChat(exact);
						return;
					}
					if (members.length === 1) {
						goToChat(members[0]);
						return;
					}
				}
				renderSuggestions(members);
			}).catch(function () {
				hideSuggestions();
			});
		}

		function highlightSuggestion(index) {
			var items = list.querySelectorAll('.msgr-member-suggestion');
			items.forEach(function (node, i) {
				node.classList.toggle('is-active', i === index);
			});
		}

		input.addEventListener('input', function () {
			window.clearTimeout(timer);
			timer = window.setTimeout(fetchMembers, 200);
		});

		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				if (!list.hidden && activeIndex >= 0 && currentMembers[activeIndex]) {
					goToChat(currentMembers[activeIndex]);
					return;
				}
				if (!list.hidden && currentMembers.length === 1) {
					goToChat(currentMembers[0]);
					return;
				}
				fetchMembers(true);
				return;
			}

			if (list.hidden || !currentMembers.length) {
				return;
			}

			if (event.key === 'ArrowDown') {
				event.preventDefault();
				activeIndex = Math.min(activeIndex + 1, currentMembers.length - 1);
				highlightSuggestion(activeIndex);
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				activeIndex = Math.max(activeIndex - 1, 0);
				highlightSuggestion(activeIndex);
			} else if (event.key === 'Escape') {
				hideSuggestions();
			}
		});

		document.addEventListener('click', function (event) {
			if (picker && !picker.contains(event.target)) {
				hideSuggestions();
			}
		});

		if (clearBtn) {
			clearBtn.addEventListener('click', clearSelection);
		}

		if (startBtn) {
			startBtn.addEventListener('click', function () {
				if (selectedMember) {
					goToChat(selectedMember);
					return;
				}
				fetchMembers(true);
			});
		}

		input.focus();
	}

	function initComposeTabs() {
		var tabs = document.querySelectorAll('[data-compose-tab]');
		var panels = document.querySelectorAll('[data-compose-panel]');
		if (!tabs.length || !panels.length) {
			return;
		}

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				var target = tab.getAttribute('data-compose-tab');
				tabs.forEach(function (node) {
					var active = node === tab;
					node.classList.toggle('is-active', active);
					node.setAttribute('aria-selected', active ? 'true' : 'false');
				});
				panels.forEach(function (panel) {
					var show = panel.getAttribute('data-compose-panel') === target;
					panel.hidden = !show;
					panel.classList.toggle('is-active', show);
				});
			});
		});
	}

	function initComposeGroupPicker() {
		var input = document.getElementById('msgr-group-member-query');
		var list = document.getElementById('msgr-group-member-suggestions');
		var selectedList = document.getElementById('msgr-group-selected-list');
		var titleInput = document.getElementById('msgr-group-title');
		var startBtn = document.getElementById('msgr-start-group');

		if (!input || !list || !selectedList || !startBtn || !cfg.apiGroupMembers || !cfg.apiGroupCreate) {
			return;
		}

		if (!cfg.groupCreateHash) {
			startBtn.disabled = true;
			startBtn.title = cfg.groupCreateFailedLabel || '';
		}

		var timer = null;
		var selectedMembers = {};

		function updateStartButton() {
			var count = Object.keys(selectedMembers).length;
			startBtn.disabled = count === 0;
		}

		function resolveGroupError(payload) {
			if (payload && payload.error) {
				return payload.error;
			}

			return cfg.groupCreateFailedLabel || 'Het groepsgesprek kon niet worden gestart.';
		}

		function buildDefaultGroupTitle() {
			var names = Object.keys(selectedMembers).map(function (userId) {
				return selectedMembers[userId].username || '';
			}).filter(function (name) {
				return name !== '';
			});

			if (!names.length) {
				return cfg.groupTitleDefaultLabel || 'Groepsgesprek';
			}

			return names.join(', ');
		}

		function renderSelected() {
			selectedList.innerHTML = '';
			Object.keys(selectedMembers).forEach(function (userId) {
				var member = selectedMembers[userId];
				var item = document.createElement('li');
				item.className = 'msgr-group-selected-item';
				item.innerHTML = '<span>' + escapeAttr(member.username || '') + '</span>' +
					'<button type="button" class="msgr-group-remove" data-user-id="' + escapeAttr(userId) + '" aria-label="Remove">' +
					'<i class="icon fa-times fa-fw" aria-hidden="true"></i></button>';
				selectedList.appendChild(item);
			});
			updateStartButton();
		}

		function addMember(member) {
			if (!member || !member.user_id) {
				return;
			}
			selectedMembers[String(member.user_id)] = member;
			renderSelected();
			input.value = '';
			list.hidden = true;
			list.innerHTML = '';
		}

		selectedList.addEventListener('click', function (event) {
			var btn = event.target.closest('.msgr-group-remove');
			if (!btn) {
				return;
			}
			delete selectedMembers[btn.getAttribute('data-user-id')];
			renderSelected();
		});

		if (titleInput) {
			titleInput.addEventListener('input', updateStartButton);
		}

		input.addEventListener('input', function () {
			var query = input.value.trim();
			window.clearTimeout(timer);
			if (query.length < 2) {
				list.hidden = true;
				list.innerHTML = '';
				return;
			}
			timer = window.setTimeout(function () {
				getJson(cfg.apiGroupMembers + '?q=' + encodeURIComponent(query)).then(function (payload) {
					var members = (payload && payload.members) || [];
					list.innerHTML = '';
					if (!members.length) {
						var empty = document.createElement('li');
						empty.className = 'msgr-member-empty';
						empty.textContent = cfg.noMembersLabel || 'No members found.';
						list.appendChild(empty);
					} else {
						members.forEach(function (member) {
							if (selectedMembers[String(member.user_id)]) {
								return;
							}
							var item = document.createElement('li');
							item.className = 'msgr-member-suggestion';
							item.textContent = member.username || '';
							item.addEventListener('click', function () {
								addMember(member);
							});
							list.appendChild(item);
						});
					}
					list.hidden = false;
				});
			}, 250);
		});

		startBtn.addEventListener('click', function () {
			var memberIds = Object.keys(selectedMembers).map(function (id) {
				return parseInt(id, 10);
			}).filter(function (id) {
				return id > 0;
			});
			var title = titleInput ? titleInput.value.trim() : '';
			if (!title) {
				title = buildDefaultGroupTitle();
			}
			if (!memberIds.length) {
				window.alert(cfg.groupMembersRequiredLabel || 'Selecteer minimaal één deelnemer.');
				return;
			}

			startBtn.disabled = true;
			postJson(cfg.apiGroupCreate, {
				title: title,
				member_ids: memberIds,
				hash: cfg.groupCreateHash || ''
			}).then(function (payload) {
				if (payload && payload.success && payload.chat_url) {
					window.location.href = payload.chat_url;
					return;
				}
				window.alert(resolveGroupError(payload));
			}).catch(function (payload) {
				window.alert(resolveGroupError(payload));
			}).finally(function () {
				updateStartButton();
			});
		});
	}

	function initComposeBulkSend() {
		var input = document.getElementById('msgr-bulk-member-query');
		var list = document.getElementById('msgr-bulk-member-suggestions');
		var selectedList = document.getElementById('msgr-bulk-selected-list');
		var messageInput = document.getElementById('msgr-bulk-message');
		var sendBtn = document.getElementById('msgr-send-bulk');

		if (!input || !list || !selectedList || !messageInput || !sendBtn || !cfg.apiMembers || !cfg.apiSendBulk) {
			return;
		}

		var timer = null;
		var selectedMembers = {};
		var sending = false;

		function updateSendButton() {
			var count = Object.keys(selectedMembers).length;
			var hasMessage = messageInput.value.trim() !== '';
			sendBtn.disabled = sending || count === 0 || !hasMessage;
		}

		function renderSelectedMembers() {
			selectedList.innerHTML = '';
			Object.keys(selectedMembers).forEach(function (userId) {
				var member = selectedMembers[userId];
				var item = document.createElement('li');
				item.className = 'msgr-group-selected-item';
				item.innerHTML = '<span>' + escapeAttr(member.username || '') + '</span>' +
					'<button type="button" class="msgr-group-selected-remove" aria-label="' + escapeAttr((cfg.labels && cfg.labels.removeAttachment) || 'Remove') + '">&times;</button>';
				item.querySelector('.msgr-group-selected-remove').addEventListener('click', function () {
					delete selectedMembers[userId];
					renderSelectedMembers();
					updateSendButton();
				});
				selectedList.appendChild(item);
			});
			updateSendButton();
		}

		function addMember(member) {
			if (!member || !member.user_id) {
				return;
			}
			selectedMembers[String(member.user_id)] = member;
			input.value = '';
			list.hidden = true;
			list.innerHTML = '';
			renderSelectedMembers();
		}

		input.addEventListener('input', function () {
			window.clearTimeout(timer);
			var q = input.value.trim();
			if (q.length < 1) {
				list.hidden = true;
				list.innerHTML = '';
				return;
			}

			timer = window.setTimeout(function () {
				getJson(cfg.apiMembers + '?q=' + encodeURIComponent(q)).then(function (payload) {
					var members = (payload && payload.members) || [];
					list.innerHTML = '';
					if (!members.length) {
						var empty = document.createElement('li');
						empty.className = 'msgr-member-empty';
						empty.textContent = cfg.noMembersLabel || 'No members found.';
						list.appendChild(empty);
					} else {
						members.forEach(function (member) {
							if (selectedMembers[String(member.user_id)]) {
								return;
							}
							var item = document.createElement('li');
							item.className = 'msgr-member-suggestion';
							item.textContent = member.username || '';
							item.addEventListener('mousedown', function (event) {
								event.preventDefault();
								addMember(member);
							});
							list.appendChild(item);
						});
					}
					list.hidden = false;
				}).catch(function () {
					list.hidden = true;
					list.innerHTML = '';
				});
			}, 200);
		});

		messageInput.addEventListener('input', updateSendButton);

		sendBtn.addEventListener('click', function () {
			if (sending) {
				return;
			}

			var recipientIds = Object.keys(selectedMembers).map(function (id) {
				return parseInt(id, 10);
			}).filter(function (id) {
				return id > 0;
			});
			var message = messageInput.value.trim();

			if (!recipientIds.length) {
				window.alert(cfg.bulkRecipientsRequiredLabel || 'Select at least one recipient.');
				return;
			}
			if (!message) {
				return;
			}

			sending = true;
			updateSendButton();

			postJson(cfg.apiSendBulk, {
				message: message,
				recipient_ids: recipientIds,
				hash: cfg.sendHash || ''
			}).then(function (payload) {
				if (!payload || !payload.success) {
					window.alert((payload && payload.error) || 'Could not send the message.');
					return;
				}

				var sentCount = parseInt(payload.sent_count, 10) || 0;
				var failedCount = parseInt(payload.failed_count, 10) || 0;
				var toastMessage;

				if (failedCount > 0) {
					toastMessage = (cfg.bulkPartialLabel || 'Message sent to %1$d recipient(s). %2$d could not be delivered.')
						.replace('%1$d', sentCount)
						.replace('%2$d', failedCount);
				} else {
					toastMessage = (cfg.bulkSentLabel || 'Message sent to %d recipient(s).')
						.replace('%d', sentCount);
				}

				if (cfg.rosterUrl) {
					try {
						sessionStorage.setItem('msgr_bulk_toast', toastMessage);
					} catch (error) {
						// ignore
					}
					window.location.href = cfg.rosterUrl;
					return;
				}

				showToast(toastMessage);
				selectedMembers = {};
				messageInput.value = '';
				renderSelectedMembers();
			}).catch(function (payload) {
				window.alert((payload && payload.error) || 'Could not send the message.');
			}).finally(function () {
				sending = false;
				updateSendButton();
			});
		});
	}

	function messageApiUrl(template, msgId) {
		if (!template) {
			return '';
		}
		return template.replace(/\/0(\?|$)/, '/' + msgId + '$1');
	}

	function insertAtCursor(input, text) {
		if (!input) {
			return;
		}
		var start = input.selectionStart || 0;
		var end = input.selectionEnd || 0;
		input.value = input.value.substring(0, start) + text + input.value.substring(end);
		input.focus();
		input.selectionStart = input.selectionEnd = start + text.length;
		input.dispatchEvent(new Event('input', { bubbles: true }));
	}

	function quoteMessageFromNode(messageNode) {
		var input = document.getElementById('msgr-input');
		if (!input || !messageNode) {
			return;
		}

		var author = messageNode.getAttribute('data-author-name') || cfg.partnerName || '';
		var msgId = parseInt(messageNode.getAttribute('data-msg-id') || '0', 10);
		var plain = messageNode.getAttribute('data-message-plain') || '';
		plain = plain.replace(/\s+/g, ' ').trim();
		if (plain.length > 500) {
			plain = plain.substring(0, 500) + '…';
		}

		var quote;
		if (author && msgId > 0) {
			quote = '[quote="' + author + ', post_id=' + msgId + '"]' + plain + '[/quote]\n';
		} else if (author) {
			quote = '[quote=' + author + ']' + plain + '[/quote]\n';
		} else {
			quote = '[quote]' + plain + '[/quote]\n';
		}

		insertAtCursor(input, quote);
	}

	function filterRosterItems(query) {
		var list = document.getElementById('msgr-roster');
		if (!list) {
			return;
		}

		var needle = (query || '').toLowerCase();
		list.querySelectorAll('.msgr-roster-item[data-partner-id]').forEach(function (item) {
			if (needle.length < 2) {
				item.hidden = false;
				return;
			}

			var name = '';
			var preview = '';
			var nameEl = item.querySelector('.msgr-roster-meta strong');
			var previewEl = item.querySelector('.msgr-preview');
			if (nameEl) {
				name = nameEl.textContent.toLowerCase();
			}
			if (previewEl) {
				preview = previewEl.textContent.toLowerCase();
			}

			item.hidden = name.indexOf(needle) === -1 && preview.indexOf(needle) === -1;
		});
	}

	function showSearchLoading(container) {
		if (!container) {
			return;
		}
		container.hidden = false;
		container.innerHTML = '<div class="msgr-search-empty">' + escapeAttr((cfg.labels && cfg.labels.searching) || 'Searching…') + '</div>';
	}

	function performSearch(query, partnerId, resultsContainer) {
		if (!cfg.apiSearch || query.length < 2) {
			renderSearchResults(resultsContainer, null);
			return Promise.resolve([]);
		}

		showSearchLoading(resultsContainer);

		var url = cfg.apiSearch + '?q=' + encodeURIComponent(query);
		if (partnerId) {
			url += '&partner_id=' + encodeURIComponent(partnerId);
		}

		return getJson(url).then(function (payload) {
			var results = (payload && payload.results) ? payload.results : [];
			renderSearchResults(
				resultsContainer,
				results,
				results.length ? '' : ((cfg.labels && cfg.labels.searchNoResults) || 'No messages found.')
			);
			return results;
		}).catch(function () {
			renderSearchResults(
				resultsContainer,
				[],
				(cfg.labels && cfg.labels.searchNoResults) || 'No messages found.'
			);
			return [];
		});
	}

	function getMessagePlainText(item) {
		var plain = item.getAttribute('data-message-plain') || '';
		if (plain) {
			return plain.toLowerCase();
		}

		var content = item.querySelector('.msgr-bubble-content');
		return content ? (content.textContent || '').toLowerCase() : '';
	}

	function renderSearchResults(container, results, emptyLabel) {
		if (!container) {
			return;
		}

		container.innerHTML = '';

		if (!results || !results.length) {
			if (emptyLabel) {
				container.hidden = false;
				container.innerHTML = '<div class="msgr-search-empty">' + escapeAttr(emptyLabel) + '</div>';
			} else {
				container.hidden = true;
			}
			return;
		}

		container.hidden = false;
		results.forEach(function (result) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'msgr-search-result';
			btn.innerHTML =
				'<span class="msgr-search-result-meta">' +
					'<strong>' + escapeAttr(result.partner_username || '') + '</strong>' +
					'<time>' + escapeAttr(result.time_formatted || '') + '</time>' +
				'</span>' +
				'<span class="msgr-search-result-snippet">' + escapeAttr(result.snippet || '') + '</span>';

			btn.addEventListener('click', function () {
				var msgId = parseInt(result.msg_id, 10) || extractMsgIdFromHref(result.chat_url);
				var current = window.location.href.split('#')[0];
				var target = result.chat_url ? result.chat_url.split('#')[0] : '';
				var sameChat = !target || target === current;

				function finishJump() {
					clearChatSearchFilter();
					scrollToMessage(msgId);
					if (window.history && window.history.replaceState) {
						window.history.replaceState(null, '', '#msg-' + msgId);
					}
					renderSearchResults(container, null);
				}

				// Message in the current chat: scroll (and load older batches when needed).
				if (msgId && sameChat) {
					if (document.querySelector('.msgr-message[data-msg-id="' + msgId + '"]')) {
						finishJump();
						return;
					}

					btn.classList.add('is-loading');
					loadOlderUntil(msgId).then(function (found) {
						btn.classList.remove('is-loading');
						if (found) {
							finishJump();
						}
					});
					return;
				}

				if (result.chat_url) {
					window.location.href = result.chat_url;
				}
			});

			container.appendChild(btn);
		});
	}

	function filterChatMessages(query) {
		var list = document.getElementById('msgr-messages');
		if (!list) {
			return 0;
		}

		var needle = (query || '').toLowerCase();
		var visible = 0;

		list.querySelectorAll('.msgr-message[data-msg-id]').forEach(function (item) {
			if (needle.length < 2) {
				item.hidden = false;
				item.classList.remove('is-search-hit');
				visible++;
				return;
			}

			var plain = getMessagePlainText(item);
			var match = plain.indexOf(needle) !== -1;
			item.hidden = !match;
			item.classList.toggle('is-search-hit', match);
			if (match) {
				visible++;
			}
		});

		return visible;
	}

	function extractMsgIdFromHref(href) {
		if (!href) {
			return 0;
		}

		var hashMatch = href.match(/#(?:p|msg-)(\d+)$/i);
		if (hashMatch) {
			return parseInt(hashMatch[1], 10);
		}

		var paramMatch = href.match(/[?&]p=(\d+)/i);
		if (paramMatch) {
			return parseInt(paramMatch[1], 10);
		}

		return 0;
	}

	function jumpToQuotedMessage(msgId) {
		if (msgId <= 0) {
			return false;
		}

		function markHash() {
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', '#msg-' + msgId);
			} else {
				window.location.hash = 'msg-' + msgId;
			}
		}

		if (scrollToMessage(msgId)) {
			markHash();
			return true;
		}

		// Quoted message is older than the loaded history: fetch it first.
		loadOlderUntil(msgId).then(function (found) {
			if (found && scrollToMessage(msgId)) {
				markHash();
			}
		});

		return false;
	}

	function scrollToMessage(msgId) {
		var node = document.querySelector('.msgr-message[data-msg-id="' + msgId + '"]');
		if (!node) {
			return false;
		}

		node.hidden = false;
		node.classList.add('is-search-hit');
		node.scrollIntoView({ behavior: 'smooth', block: 'center' });
		window.setTimeout(function () {
			node.classList.remove('is-search-hit');
		}, 2500);
		return true;
	}

	function clearChatSearchFilter() {
		var input = document.getElementById('msgr-search-chat') || document.querySelector('.msgr-search-chat');
		if (input && input.value) {
			input.value = '';
		}
		filterChatMessages('');
	}

	function scrollToMessageFromHash() {
		var match = window.location.hash.match(/^#(?:msg-|p)(\d+)$/);
		if (!match) {
			return;
		}

		var msgId = match[1];
		if (scrollToMessage(msgId)) {
			return;
		}

		window.setTimeout(function () {
			if (scrollToMessage(msgId)) {
				return;
			}

			// Older message that is not part of the initial batch: keep loading
			// history until we find it.
			loadOlderUntil(msgId).then(function (found) {
				if (found) {
					scrollToMessage(msgId);
				}
			});
		}, 400);
	}

	function openDeleteMessageModal(msgId) {
		var modal = document.getElementById('msgr-delete-modal');
		var title = document.getElementById('msgr-delete-modal-title');
		var cancelBtn = document.getElementById('msgr-delete-cancel');
		var meBtn = document.getElementById('msgr-delete-me');
		var bothBtn = document.getElementById('msgr-delete-both');
		var labels = cfg.labels || {};

		if (!modal || !title || !cancelBtn || !meBtn) {
			return;
		}

		modal.dataset.mode = 'message';
		modal.dataset.msgId = String(msgId);
		delete modal.dataset.partnerId;

		title.textContent = labels.deleteMessageConfirm || 'Delete this message?';
		cancelBtn.textContent = labels.cancel || 'Cancel';
		meBtn.textContent = labels.deleteMe || 'Delete for me';
		if (bothBtn) {
			bothBtn.textContent = labels.deleteBoth || 'Delete for both';
			bothBtn.hidden = !cfg.allowDeleteBoth;
		}

		modal.hidden = false;

		cancelBtn.onclick = function () {
			modal.hidden = true;
		};

		meBtn.onclick = function () {
			deleteMessage(msgId, false);
			modal.hidden = true;
		};

		if (bothBtn) {
			bothBtn.onclick = function () {
				deleteMessage(msgId, true);
				modal.hidden = true;
			};
		}
	}

	function deleteMessage(msgId, forBoth) {
		var url = messageApiUrl(cfg.apiDeleteMessage, msgId);
		if (!url) {
			return;
		}

		postJson(url, { for_both: forBoth ? 1 : 0 }).then(function (payload) {
			if (!payload || !payload.success) {
				return;
			}

			removeMessageFromDom(msgId);

			showToast(cfg.labels && cfg.labels.toastMessageDeleted);
			pollMessenger();
		}).catch(function () {
			// ignore
		});
	}

	function applyUpdatedMessage(message) {
		if (!message || !message.msg_id) {
			return;
		}

		if (editingMsgId === parseInt(message.msg_id, 10)) {
			return;
		}

		var node = document.getElementById('p' + message.msg_id) ||
			document.querySelector('#msgr-messages .msgr-message[data-msg-id="' + message.msg_id + '"]');
		if (!node) {
			return;
		}

		node.setAttribute('data-message-plain', message.message_plain || '');
		node.innerHTML = buildMessageHtml(message);
	}

	function cancelMessageEdit(messageNode) {
		editingMsgId = 0;
		if (!messageNode) {
			return;
		}
		messageNode.classList.remove('is-editing');
		var draft = messageNode._msgrEditHtml;
		if (typeof draft === 'string') {
			messageNode.innerHTML = draft;
			delete messageNode._msgrEditHtml;
		}
	}

	function startMessageEdit(messageNode) {
		if (!messageNode || cfg.chatType === 'group') {
			return;
		}

		var msgId = parseInt(messageNode.getAttribute('data-msg-id') || '0', 10);
		var url = messageApiUrl(cfg.apiEditMessage, msgId);
		if (!url || msgId <= 0) {
			return;
		}

		if (editingMsgId && editingMsgId !== msgId) {
			var prev = document.querySelector('#msgr-messages .msgr-message.is-editing');
			cancelMessageEdit(prev);
		}

		getJson(url).then(function (payload) {
			if (!payload || !payload.success) {
				window.alert((payload && payload.error) || (cfg.labels && cfg.labels.editFailed) || 'Could not edit.');
				return;
			}

			editingMsgId = msgId;
			messageNode._msgrEditHtml = messageNode.innerHTML;
			messageNode.classList.add('is-editing');

			var labels = cfg.labels || {};
			messageNode.innerHTML =
				'<div class="msgr-bubble msgr-bubble-edit">' +
					'<textarea class="msgr-edit-input" rows="3"></textarea>' +
					'<div class="msgr-edit-actions">' +
						'<button type="button" class="button button-secondary msgr-edit-cancel">' +
							escapeAttr(labels.cancel || 'Cancel') +
						'</button>' +
						'<button type="button" class="button msgr-edit-save">' +
							escapeAttr(labels.saveEdit || 'Save') +
						'</button>' +
					'</div>' +
				'</div>';

			var input = messageNode.querySelector('.msgr-edit-input');
			var saveBtn = messageNode.querySelector('.msgr-edit-save');
			var cancelBtn = messageNode.querySelector('.msgr-edit-cancel');
			if (input) {
				input.value = payload.text || '';
				input.focus();
				input.setSelectionRange(input.value.length, input.value.length);
			}
			if (cancelBtn) {
				cancelBtn.addEventListener('click', function () {
					cancelMessageEdit(messageNode);
				});
			}
			if (saveBtn) {
				saveBtn.addEventListener('click', function () {
					saveMessageEdit(messageNode, msgId, input ? input.value : '');
				});
			}
			if (input) {
				input.addEventListener('keydown', function (event) {
					if (event.key === 'Escape') {
						event.preventDefault();
						cancelMessageEdit(messageNode);
					}
					if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
						event.preventDefault();
						saveMessageEdit(messageNode, msgId, input.value);
					}
				});
			}
		}).catch(function (payload) {
			window.alert((payload && payload.error) || (cfg.labels && cfg.labels.editFailed) || 'Could not edit.');
		});
	}

	function saveMessageEdit(messageNode, msgId, text) {
		var url = messageApiUrl(cfg.apiEditMessage, msgId);
		if (!url) {
			return;
		}

		text = String(text || '').trim();
		if (!text) {
			window.alert((cfg.labels && cfg.labels.editFailed) || 'Could not edit.');
			return;
		}

		var saveBtn = messageNode ? messageNode.querySelector('.msgr-edit-save') : null;
		if (saveBtn) {
			saveBtn.disabled = true;
		}

		postJson(url, {
			message: text,
			hash: cfg.sendHash || ''
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.message) {
				if (saveBtn) {
					saveBtn.disabled = false;
				}
				window.alert((payload && payload.error) || (cfg.labels && cfg.labels.editFailed) || 'Could not edit.');
				return;
			}

			editingMsgId = 0;
			delete messageNode._msgrEditHtml;
			messageNode.classList.remove('is-editing');
			messageNode.setAttribute('data-message-plain', payload.message.message_plain || '');
			messageNode.innerHTML = buildMessageHtml(payload.message);
			lastEditSync = Math.floor(Date.now() / 1000);
			showToast(cfg.labels && cfg.labels.toastMessageEdited);
			pollMessenger();
		}).catch(function (payload) {
			if (saveBtn) {
				saveBtn.disabled = false;
			}
			window.alert((payload && payload.error) || (cfg.labels && cfg.labels.editFailed) || 'Could not edit.');
		});
	}

	function closeMessageMenus(exceptNode) {
		document.querySelectorAll('.msgr-message.is-menu-open').forEach(function (node) {
			if (exceptNode && node === exceptNode) {
				return;
			}
			node.classList.remove('is-menu-open');
			var btn = node.querySelector('.msgr-msg-menu-btn');
			var dropdown = node.querySelector('.msgr-msg-dropdown');
			if (btn) {
				btn.setAttribute('aria-expanded', 'false');
			}
			if (dropdown) {
				dropdown.hidden = true;
				dropdown.style.position = '';
				dropdown.style.top = '';
				dropdown.style.left = '';
				dropdown.style.right = '';
				dropdown.style.minWidth = '';
			}
		});
	}

	function positionMessageDropdown(messageNode) {
		var btn = messageNode.querySelector('.msgr-msg-menu-btn');
		var dropdown = messageNode.querySelector('.msgr-msg-dropdown');
		if (!btn || !dropdown) {
			return;
		}

		dropdown.hidden = false;
		var rect = btn.getBoundingClientRect();
		var width = Math.max(168, dropdown.offsetWidth || 168);
		var left = Math.max(8, rect.right - width);
		var top = rect.bottom + 4;

		if (top + 120 > window.innerHeight) {
			top = Math.max(8, rect.top - 120);
		}

		dropdown.style.position = 'fixed';
		dropdown.style.top = top + 'px';
		dropdown.style.left = left + 'px';
		dropdown.style.right = 'auto';
		dropdown.style.minWidth = width + 'px';
	}

	function toggleMessageMenu(messageNode) {
		if (!messageNode) {
			return;
		}

		var isOpen = messageNode.classList.contains('is-menu-open');
		closeMessageMenus();

		if (isOpen) {
			return;
		}

		var btn = messageNode.querySelector('.msgr-msg-menu-btn');
		var dropdown = messageNode.querySelector('.msgr-msg-dropdown');
		if (!btn || !dropdown) {
			return;
		}

		messageNode.classList.add('is-menu-open');
		btn.setAttribute('aria-expanded', 'true');
		positionMessageDropdown(messageNode);
	}

	function initChatWallpaper() {
		var openBtns = document.querySelectorAll('.msgr-wallpaper-btn');
		var modal = document.getElementById('msgr-wallpaper-modal');
		var grid = document.getElementById('msgr-wallpaper-grid');
		var closeBtn = document.getElementById('msgr-wallpaper-close');
		var uploadInput = document.getElementById('msgr-wallpaper-upload-input');
		var state = {
			wallpaper: cfg.chatWallpaper || 'default',
			customUrl: cfg.chatWallpaperCustomUrl || ''
		};

		function applyChatWallpaper(wallpaper, customUrl) {
			var box = document.getElementById('msgr-messages');
			if (!box) {
				return;
			}

			box.removeAttribute('data-msgr-wallpaper');
			box.style.backgroundImage = '';
			box.style.backgroundSize = '';
			box.style.backgroundPosition = '';
			box.style.backgroundColor = '';

			if (!wallpaper || wallpaper === 'default') {
				return;
			}

			if (wallpaper === 'custom' && customUrl) {
				box.setAttribute('data-msgr-wallpaper', 'custom');
				box.style.backgroundImage = 'url("' + customUrl.replace(/"/g, '\\"') + '")';
				box.style.backgroundSize = 'cover';
				box.style.backgroundPosition = 'center center';
				return;
			}

			box.setAttribute('data-msgr-wallpaper', wallpaper);
		}

		function renderWallpaperGrid() {
			if (!grid) {
				return;
			}

			grid.innerHTML = '';
			(cfg.wallpaperPresets || []).forEach(function (preset) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'msgr-wallpaper-option';
				btn.setAttribute('data-wallpaper', preset.id);
				btn.setAttribute('aria-label', preset.label || preset.id);
				btn.classList.toggle('is-active', preset.id === state.wallpaper);

				var preview = document.createElement('span');
				preview.className = 'msgr-wallpaper-preview msgr-wallpaper-preview--' + preset.id;
				btn.appendChild(preview);

				var label = document.createElement('span');
				label.className = 'msgr-wallpaper-label';
				label.textContent = preset.label || preset.id;
				btn.appendChild(label);

				btn.addEventListener('click', function () {
					saveWallpaper(preset.id);
				});

				grid.appendChild(btn);
			});

			if (state.wallpaper === 'custom' && state.customUrl) {
				var customBtn = document.createElement('button');
				customBtn.type = 'button';
				customBtn.className = 'msgr-wallpaper-option is-active';
				customBtn.setAttribute('data-wallpaper', 'custom');
				customBtn.setAttribute('aria-label', cfg.wallpaperCustomLabel || 'Your photo');

				var customPreview = document.createElement('span');
				customPreview.className = 'msgr-wallpaper-preview msgr-wallpaper-preview--custom';
				customPreview.style.backgroundImage = 'url("' + state.customUrl + '")';
				customBtn.appendChild(customPreview);

				var customLabel = document.createElement('span');
				customLabel.className = 'msgr-wallpaper-label';
				customLabel.textContent = cfg.wallpaperCustomLabel || 'Your photo';
				customBtn.appendChild(customLabel);

				grid.appendChild(customBtn);
			}
		}

		function saveWallpaper(wallpaper) {
			if (!cfg.apiWallpaper || !cfg.wallpaperHash) {
				return;
			}

			postJson(cfg.apiWallpaper, {
				wallpaper: wallpaper,
				hash: cfg.wallpaperHash
			}).then(function (payload) {
				if (!payload || !payload.success) {
					window.alert((payload && payload.error) || 'Could not save wallpaper.');
					return;
				}

				state.wallpaper = payload.wallpaper || wallpaper;
				state.customUrl = payload.custom_url || '';
				cfg.chatWallpaper = state.wallpaper;
				cfg.chatWallpaperCustomUrl = state.customUrl;
				applyChatWallpaper(state.wallpaper, state.customUrl);
				renderWallpaperGrid();
				showToast(cfg.wallpaperSavedLabel || 'Wallpaper updated');
				if (modal) {
					modal.hidden = true;
				}
			}).catch(function (payload) {
				window.alert((payload && payload.error) || 'Could not save wallpaper.');
			});
		}

		function uploadWallpaper(file) {
			if (!file || !cfg.apiWallpaperUpload || !cfg.wallpaperHash) {
				return;
			}

			readFileAsUploadPayload(file).then(function (payloadData) {
				if (!payloadData.base64) {
					throw new Error('upload failed');
				}

				return postJson(cfg.apiWallpaperUpload, {
					image_data: payloadData.base64,
					hash: cfg.wallpaperHash
				});
			}).then(function (payload) {
				if (!payload || !payload.success) {
					window.alert((payload && payload.error) || 'Could not upload wallpaper.');
					return;
				}

				state.wallpaper = 'custom';
				state.customUrl = payload.custom_url || '';
				cfg.chatWallpaper = 'custom';
				cfg.chatWallpaperCustomUrl = state.customUrl;
				applyChatWallpaper('custom', state.customUrl);
				renderWallpaperGrid();
				showToast(cfg.wallpaperSavedLabel || 'Wallpaper updated');
				if (modal) {
					modal.hidden = true;
				}
			}).catch(function () {
				window.alert('Could not upload wallpaper.');
			});
		}

		applyChatWallpaper(state.wallpaper, state.customUrl);

		if (!openBtns.length || !modal || !grid) {
			return;
		}

		renderWallpaperGrid();

		openBtns.forEach(function (openBtn) {
			openBtn.addEventListener('click', function () {
				renderWallpaperGrid();
				modal.hidden = false;
				document.body.classList.add('msgr-wallpaper-open');
			});
		});

		function closeWallpaperModal() {
			modal.hidden = true;
			document.body.classList.remove('msgr-wallpaper-open');
		}

		if (closeBtn) {
			closeBtn.addEventListener('click', closeWallpaperModal);
		}

		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				closeWallpaperModal();
			}
		});

		if (uploadInput) {
			uploadInput.addEventListener('change', function () {
				var file = uploadInput.files && uploadInput.files[0];
				uploadInput.value = '';
				if (file) {
					uploadWallpaper(file);
				}
			});
		}
	}

	function initImageLightbox() {
		var overlay = document.getElementById('msgr-image-lightbox');
		var img = document.getElementById('msgr-image-lightbox-img');
		var closeBtn = document.getElementById('msgr-image-lightbox-close');
		if (!overlay || !img) {
			return;
		}

		function closeLightbox() {
			overlay.hidden = true;
			img.removeAttribute('src');
			img.alt = '';
			document.body.classList.remove('msgr-lightbox-open');
		}

		function openLightbox(src, alt) {
			if (!src) {
				return;
			}
			img.src = src;
			img.alt = alt || '';
			overlay.hidden = false;
			document.body.classList.add('msgr-lightbox-open');
		}

		if (closeBtn) {
			closeBtn.addEventListener('click', closeLightbox);
		}

		overlay.addEventListener('click', function (event) {
			if (event.target === overlay) {
				closeLightbox();
			}
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !overlay.hidden) {
				closeLightbox();
			}
		});

		document.addEventListener('click', function (event) {
			var link = event.target.closest('.msgr-bubble-content .msgr-image-link');
			if (!link) {
				return;
			}

			var src = link.getAttribute('data-msgr-image') || link.getAttribute('href');
			if (!src) {
				return;
			}

			event.preventDefault();
			openLightbox(src, link.querySelector('img') ? link.querySelector('img').alt : '');
		});
	}

	function initMessageActions() {
		var messages = document.getElementById('msgr-messages');
		if (!messages) {
			return;
		}

		messages.addEventListener('click', function (event) {
			var quoteLink = event.target.closest('.msgr-bubble-content blockquote cite a, .msgr-bubble-content blockquote .quotetitle a, .msgr-bubble-content .quote cite a');
			if (quoteLink) {
				var quoteHref = quoteLink.getAttribute('href') || '';
				// Quotes of forum posts keep their viewtopic link: navigate normally.
				if (quoteHref.toLowerCase().indexOf('viewtopic') === -1) {
					var msgId = extractMsgIdFromHref(quoteHref);
					if (msgId > 0) {
						event.preventDefault();
						event.stopPropagation();
						jumpToQuotedMessage(msgId);
						return;
					}
				}
			}

			var menuBtn = event.target.closest('.msgr-msg-menu-btn');
			if (menuBtn) {
				event.preventDefault();
				event.stopPropagation();
				toggleMessageMenu(menuBtn.closest('.msgr-message'));
				return;
			}

			var quoteBtn = event.target.closest('.msgr-msg-quote');
			if (quoteBtn) {
				event.preventDefault();
				event.stopPropagation();
				closeMessageMenus();
				quoteMessageFromNode(quoteBtn.closest('.msgr-message'));
				return;
			}

			var editBtn = event.target.closest('.msgr-msg-edit');
			if (editBtn) {
				event.preventDefault();
				event.stopPropagation();
				closeMessageMenus();
				startMessageEdit(editBtn.closest('.msgr-message'));
				return;
			}

			var deleteBtn = event.target.closest('.msgr-msg-delete');
			if (deleteBtn) {
				event.preventDefault();
				event.stopPropagation();
				var messageNode = deleteBtn.closest('.msgr-message');
				var msgId = parseInt(messageNode.getAttribute('data-msg-id') || '0', 10);
				closeMessageMenus();
				if (msgId > 0) {
					openDeleteMessageModal(msgId);
				}
			}
		});

		document.addEventListener('click', function (event) {
			if (event.target.closest('.msgr-bubble-menu')) {
				return;
			}
			closeMessageMenus();
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeMessageMenus();
			}
		});
	}

	function initSearch() {
		var input = document.getElementById('msgr-search');
		var results = document.getElementById('msgr-search-results');
		if (!input) {
			return;
		}

		var timer = null;
		input.addEventListener('input', function () {
			window.clearTimeout(timer);
			var q = input.value.trim();

			if (q.length < 2) {
				filterRosterItems('');
				renderSearchResults(results, null);
				return;
			}

			filterRosterItems(q);

			timer = window.setTimeout(function () {
				performSearch(q, 0, results);
			}, 300);
		});
	}

	function initChatSearch() {
		var input = document.getElementById('msgr-search-chat') || document.querySelector('.msgr-search-chat');
		var results = document.getElementById('msgr-search-results-chat');
		if (!input) {
			return;
		}

		var timer = null;
		input.addEventListener('input', function () {
			window.clearTimeout(timer);
			var q = input.value.trim();
			var visible = filterChatMessages(q);

			if (q.length < 2) {
				renderSearchResults(results, null);
				return;
			}

			timer = window.setTimeout(function () {
				performSearch(q, cfg.partnerId || 0, results).then(function (apiResults) {
					var unmatched = apiResults.filter(function (result) {
						return !document.querySelector('.msgr-message[data-msg-id="' + result.msg_id + '"]');
					});

					if (unmatched.length) {
						renderSearchResults(
							results,
							unmatched,
							((cfg.labels && cfg.labels.searchNoResults) || 'No messages found.')
						);
					} else if (!visible) {
						renderSearchResults(
							results,
							[],
							(cfg.labels && cfg.labels.searchNoResults) || 'No messages found.'
						);
					} else {
						renderSearchResults(results, null);
					}

					if (visible === 0 && apiResults.length) {
						scrollToMessage(apiResults[0].msg_id);
					}
				});
			}, 300);
		});
	}

	if (mode === 'chat' || mode === 'group') {
		initMsgIdsFromDom();
		initBbcodeTools();
		initComposeAttach();
		initEmojiPicker();
		initGiphyPicker();
		initComposeForm();
		initTypingIndicator();
		initLoadOlder();
		initImageLightbox();
		initChatWallpaper();
		if (mode === 'chat' || mode === 'group') {
			initMessageActions();
		}
		scrollMessagesToBottom();
		scrollToMessageFromHash();
		window.addEventListener('hashchange', scrollToMessageFromHash);

		if (cfg.apiRead) {
			postJson(cfg.apiRead, {}).then(function () {
				if (mode === 'chat') {
					handleMarkReadResponse.apply(null, arguments);
				}
			}).catch(function () {
				// ignore
			});
		}
	}

	if (cfg.apiPoll && (document.querySelector('[data-msgr-app]') || mode === 'chat' || mode === 'group')) {
		startPolling();
	}

	if (document.getElementById('msgr-member-query')) {
		initComposeMemberPicker();
	}

	if (document.getElementById('msgr-group-member-query')) {
		initComposeGroupPicker();
	}

	if (document.getElementById('msgr-bulk-member-query')) {
		initComposeBulkSend();
	}

	if (document.querySelector('[data-compose-tab]')) {
		initComposeTabs();
	}

	if (document.getElementById('msgr-search')) {
		initSearch();
	}

	if (document.getElementById('msgr-search-chat') || document.querySelector('.msgr-search-chat')) {
		initChatSearch();
	}

	initRosterActions();
	initRosterSelect();
	initRosterBadgeShapes();
	initRelativeTimes();

	try {
		var bulkToast = sessionStorage.getItem('msgr_bulk_toast');
		if (bulkToast) {
			sessionStorage.removeItem('msgr_bulk_toast');
			showToast(bulkToast);
		}
	} catch (error) {
		// ignore
	}
	mountOverlays();
	initStandaloneLayout();
	initUcpLayout();

	if (typeof cfg.notificationsCount === 'number') {
		syncNavbarNotifications(cfg.notificationsCount);
	}

	if (typeof cfg.unreadPmCount === 'number') {
		syncNavbarPrivateMessages(cfg.unreadPmCount);
	}
})();
