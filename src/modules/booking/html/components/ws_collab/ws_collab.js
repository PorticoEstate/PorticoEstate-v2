/**
 * CollabClient — Lightweight WebSocket collaborative editing client.
 *
 * Connects to the Ratchet WS server via /wss (Apache proxy) and provides
 * presence, edit indicators, and live-update hooks for entity pages.
 *
 * Usage:
 *   var collab = new CollabClient({ entityType: 'hospitality', entityId: 42, userName: 'Ola', userId: 7 });
 *   collab.connect();
 *
 * Set COLLAB_DEBUG = true to log all incoming WebSocket messages to the console.
 */

var COLLAB_DEBUG = false;

class CollabClient {
	/**
	 * @param {Object} opts
	 * @param {string} opts.entityType  e.g. 'hospitality'
	 * @param {number|string} opts.entityId
	 * @param {string} opts.userName    Display name for presence
	 * @param {number|string} opts.userId
	 * @param {string} [opts.wsUrl]     Override auto-detected URL
	 */
	constructor(opts) {
		this.entityType = opts.entityType;
		this.entityId = opts.entityId;
		this.userName = opts.userName;
		this.userId = opts.userId;
		this.wsUrl = opts.wsUrl || this._deriveWsUrl();
		this.peerId = crypto.randomUUID();
		this.roomId = 'entity_' + this.entityType + '_' + this.entityId;

		this._ws = null;
		this._connected = false;
		this._destroyed = false;
		this._reconnectAttempt = 0;
		this._reconnectTimer = null;
		this._heartbeatTimer = null;
		this._presenceTimer = null;
		this._activeScopes = new Set();

		// Peer state: peerId → { userName, userId, activeTab, scopes: Set, lastSeen: number }
		this._peers = new Map();
		this._peerTtl = 90000; // 90s
		this._peerCleanupTimer = null;

		// Debounce incoming section updates (articles batch DnD)
		this._sectionDebounceTimers = {};
		this._sectionDebounceMs = 500;

		// Callbacks
		this._onPresenceChange = null;
		this._onEditIndicator = null;
		this._onEntityUpdate = null;
		this._onConflict = null;
		this._onConnectionChange = null;

		this._boundBeforeUnload = this._handleBeforeUnload.bind(this);
	}

	// ── Public API ────────────────────────────────────────────────

	connect() {
		if (this._destroyed) return;
		this._setupConnection();
		window.addEventListener('beforeunload', this._boundBeforeUnload);
		this._startPeerCleanup();
	}

	disconnect() {
		this._destroyed = true;
		window.removeEventListener('beforeunload', this._boundBeforeUnload);
		this._clearTimers();
		if (this._ws) {
			this._sendLeave();
			this._ws.close(1000, 'disconnect');
			this._ws = null;
		}
		this._connected = false;
		this._peers.clear();
	}

	startEditing(scope) {
		if (!scope || this._activeScopes.has(scope)) return;
		this._activeScopes.add(scope);
		this._sendToRoom('edit_start', { peerId: this.peerId, userName: this.userName, scope: scope });
	}

	stopEditing(scope) {
		if (!scope || !this._activeScopes.has(scope)) return;
		this._activeScopes.delete(scope);
		this._sendToRoom('edit_end', { peerId: this.peerId, scope: scope });
	}

	stopAllEditing() {
		this._activeScopes.forEach(function (scope) {
			this._sendToRoom('edit_end', { peerId: this.peerId, scope: scope });
		}.bind(this));
		this._activeScopes.clear();
	}

	onPresenceChange(cb) { this._onPresenceChange = cb; }
	onEditIndicator(cb) { this._onEditIndicator = cb; }
	onEntityUpdate(cb) { this._onEntityUpdate = cb; }
	onConflict(cb) { this._onConflict = cb; }
	onConnectionChange(cb) { this._onConnectionChange = cb; }

	isConnected() { return this._connected; }

	getPeers() {
		var result = [];
		this._peers.forEach(function (p, id) {
			result.push({ peerId: id, userName: p.userName, userId: p.userId, scopes: Array.from(p.scopes) });
		});
		return result;
	}

	getActiveTab() {
		var hash = window.location.hash.replace('#', '');
		return hash || 'details';
	}

	// ── Connection management ─────────────────────────────────────

	_deriveWsUrl() {
		var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
		return proto + '//' + location.host + '/wss';
	}

	_setupConnection() {
		if (this._destroyed) return;

		try {
			this._ws = new WebSocket(this.wsUrl);
		} catch (e) {
			this._scheduleReconnect();
			return;
		}

		this._ws.onopen = function () {
			this._connected = true;
			this._reconnectAttempt = 0;
			this._notifyConnectionChange(true);

			// Subscribe to entity room
			this._send({ type: 'subscribe', entityType: this.entityType, entityId: this.entityId });

			// Announce presence
			this._sendPresence();

			// Re-announce active edit scopes
			this._activeScopes.forEach(function (scope) {
				this._sendToRoom('edit_start', { peerId: this.peerId, userName: this.userName, scope: scope });
			}.bind(this));

			// Start heartbeats
			this._startHeartbeat();
			this._startPresenceHeartbeat();
		}.bind(this);

		this._ws.onmessage = function (event) {
			this._handleMessage(event.data);
		}.bind(this);

		this._ws.onclose = function () {
			this._connected = false;
			this._notifyConnectionChange(false);
			this._clearHeartbeats();
			if (!this._destroyed) {
				this._scheduleReconnect();
			}
		}.bind(this);

		this._ws.onerror = function () {
			// onclose will fire after this
		};
	}

	_scheduleReconnect() {
		if (this._destroyed || this._reconnectTimer) return;

		var baseDelay = Math.min(1000 * Math.pow(2, this._reconnectAttempt), 30000);
		var jitter = Math.random() * 1000;
		var delay = baseDelay + jitter;

		this._reconnectAttempt++;
		this._reconnectTimer = setTimeout(function () {
			this._reconnectTimer = null;
			this._setupConnection();
		}.bind(this), delay);
	}

	_startHeartbeat() {
		this._clearHeartbeats();
		this._heartbeatTimer = setInterval(function () {
			if (this._ws && this._ws.readyState === WebSocket.OPEN) {
				this._send({ type: 'ping' });
			}
		}.bind(this), 30000);
	}

	_startPresenceHeartbeat() {
		this._presenceTimer = setInterval(function () {
			this._sendPresence();
		}.bind(this), 30000);
	}

	_clearHeartbeats() {
		if (this._heartbeatTimer) { clearInterval(this._heartbeatTimer); this._heartbeatTimer = null; }
		if (this._presenceTimer) { clearInterval(this._presenceTimer); this._presenceTimer = null; }
	}

	_clearTimers() {
		this._clearHeartbeats();
		if (this._reconnectTimer) { clearTimeout(this._reconnectTimer); this._reconnectTimer = null; }
		if (this._peerCleanupTimer) { clearInterval(this._peerCleanupTimer); this._peerCleanupTimer = null; }
		Object.keys(this._sectionDebounceTimers).forEach(function (k) {
			clearTimeout(this._sectionDebounceTimers[k]);
		});
		this._sectionDebounceTimers = {};
	}

	_startPeerCleanup() {
		this._peerCleanupTimer = setInterval(function () {
			var now = Date.now();
			var changed = false;
			this._peers.forEach(function (p, id) {
				if (now - p.lastSeen > this._peerTtl) {
					this._peers.delete(id);
					changed = true;
				}
			}.bind(this));
			if (changed && this._onPresenceChange) {
				this._onPresenceChange(this.getPeers());
			}
		}.bind(this), 15000);
	}

	// ── Sending ───────────────────────────────────────────────────

	_send(data) {
		if (this._ws && this._ws.readyState === WebSocket.OPEN) {
			this._ws.send(JSON.stringify(data));
		}
	}

	_sendToRoom(action, payload) {
		this._send({
			type: 'room_message',
			roomId: this.roomId,
			action: action,
			data: payload
		});
	}

	_sendPresence() {
		this._sendToRoom('presence', {
			peerId: this.peerId,
			userName: this.userName,
			userId: this.userId,
			activeTab: this.getActiveTab()
		});
	}

	_sendLeave() {
		// Send edit_end for all active scopes
		this._activeScopes.forEach(function (scope) {
			this._sendToRoom('edit_end', { peerId: this.peerId, scope: scope });
		}.bind(this));
		this._sendToRoom('presence_leave', { peerId: this.peerId });
	}

	_handleBeforeUnload() {
		if (this._ws && this._ws.readyState === WebSocket.OPEN) {
			this._sendLeave();
		}
	}

	// ── Receiving ─────────────────────────────────────────────────

	_handleMessage(raw) {
		var msg;
		try { msg = JSON.parse(raw); } catch (e) { return; }

		if (COLLAB_DEBUG) console.log('[CollabClient] WS ←', msg.type, msg.action || '', msg);

		// Subscription confirmation
		if (msg.type === 'subscription_confirmation') return;

		// Pong
		if (msg.type === 'pong') return;

		// Room messages (our collab protocol)
		if (msg.type === 'room_message') {
			this._handleRoomMessage(msg);
			return;
		}
	}

	_handleRoomMessage(msg) {
		var action = msg.action;
		var data = msg.data || {};

		// Skip our own presence/edit messages (but not server-sent updates/deletes)
		if (data.peerId === this.peerId) {
			if (action !== 'updated' && action !== 'deleted') return;
		}

		switch (action) {
			case 'presence':
				this._handlePresence(data);
				break;
			case 'presence_leave':
				this._handlePresenceLeave(data);
				break;
			case 'edit_start':
				this._handleEditStart(data);
				break;
			case 'edit_end':
				this._handleEditEnd(data);
				break;
			case 'updated':
			case 'changed':
				this._handleEntityUpdated(data);
				break;
			case 'deleted':
				this._handleEntityDeleted(data);
				break;
		}
	}

	_handlePresence(data) {
		if (!data.peerId || data.peerId === this.peerId) return;

		var existing = this._peers.get(data.peerId);
		if (!existing) {
			this._peers.set(data.peerId, {
				userName: data.userName,
				userId: data.userId,
				activeTab: data.activeTab,
				scopes: new Set(),
				lastSeen: Date.now()
			});
		} else {
			existing.userName = data.userName;
			existing.userId = data.userId;
			existing.activeTab = data.activeTab;
			existing.lastSeen = Date.now();
		}

		if (this._onPresenceChange) {
			this._onPresenceChange(this.getPeers());
		}
	}

	_handlePresenceLeave(data) {
		if (!data.peerId || data.peerId === this.peerId) return;

		var peer = this._peers.get(data.peerId);
		if (peer) {
			// Notify about edit_end for all scopes this peer had
			if (peer.scopes.size > 0 && this._onEditIndicator) {
				peer.scopes.forEach(function (scope) {
					this._onEditIndicator({ peerId: data.peerId, userName: peer.userName, scope: scope, editing: false });
				}.bind(this));
			}
			this._peers.delete(data.peerId);
		}

		if (this._onPresenceChange) {
			this._onPresenceChange(this.getPeers());
		}
	}

	_handleEditStart(data) {
		if (!data.peerId || data.peerId === this.peerId) return;

		var peer = this._peers.get(data.peerId);
		if (peer) {
			peer.scopes.add(data.scope);
			peer.lastSeen = Date.now();
		}

		if (this._onEditIndicator) {
			this._onEditIndicator({
				peerId: data.peerId,
				userName: data.userName || (peer && peer.userName) || '?',
				scope: data.scope,
				editing: true
			});
		}
	}

	_handleEditEnd(data) {
		if (!data.peerId || data.peerId === this.peerId) return;

		var peer = this._peers.get(data.peerId);
		if (peer) {
			peer.scopes.delete(data.scope);
			peer.lastSeen = Date.now();
		}

		if (this._onEditIndicator) {
			this._onEditIndicator({
				peerId: data.peerId,
				userName: (peer && peer.userName) || '?',
				scope: data.scope,
				editing: false
			});
		}
	}

	_handleEntityUpdated(data) {
		if (!this._onEntityUpdate) return;

		var section = data.section || 'details';

		// Debounce 'articles' section updates (batch drag-drop causes rapid fire)
		if (section === 'articles') {
			if (this._sectionDebounceTimers[section]) {
				clearTimeout(this._sectionDebounceTimers[section]);
			}
			this._sectionDebounceTimers[section] = setTimeout(function () {
				delete this._sectionDebounceTimers[section];
				this._onEntityUpdate({
					section: section,
					modifiedBy: data.modifiedBy,
					changedFields: data.changedFields || []
				});
			}.bind(this), this._sectionDebounceMs);
		} else {
			this._onEntityUpdate({
				section: section,
				modifiedBy: data.modifiedBy,
				changedFields: data.changedFields || []
			});
		}
	}

	_handleEntityDeleted(data) {
		if (this._onEntityUpdate) {
			this._onEntityUpdate({
				section: '_deleted',
				modifiedBy: data.modifiedBy,
				changedFields: []
			});
		}
	}

	_notifyConnectionChange(connected) {
		if (this._onConnectionChange) {
			this._onConnectionChange(connected);
		}
	}
}
