// WebSocket client for development environment
const createWebSocketClient = (url = 'ws://localhost:8088/wss') => {
    console.log('Connecting to WebSocket server at:', url);
    
    const ws = new WebSocket(url);
    
    ws.onopen = function() {
        console.log('WebSocket connection established');
        document.getElementById('connection-status').textContent = 'Connected';
        document.getElementById('connection-status').style.color = 'green';
    };
    
    ws.onmessage = function(event) {
        console.log('Message received:', event.data);
        const messagesDiv = document.getElementById('messages');
        if (messagesDiv) {
            const messageEl = document.createElement('div');
            messageEl.className = 'message';
            
            try {
                const data = JSON.parse(event.data);
                messageEl.textContent = JSON.stringify(data, null, 2);
            } catch (e) {
                messageEl.textContent = event.data;
            }
            
            messagesDiv.appendChild(messageEl);
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
    };
    
    ws.onclose = function() {
        console.log('WebSocket connection closed');
        document.getElementById('connection-status').textContent = 'Disconnected';
        document.getElementById('connection-status').style.color = 'red';
    };
    
    ws.onerror = function(error) {
        console.error('WebSocket error:', error);
        document.getElementById('connection-status').textContent = 'Error: ' + (error.message || 'Unknown error');
        document.getElementById('connection-status').style.color = 'red';
    };
    
    return {
        sendMessage: function(type, message, additionalData = {}) {
            if (ws.readyState === WebSocket.OPEN) {
                const data = {
                    type: type,
                    message: message,
                    ...additionalData,
                    timestamp: new Date().toISOString()
                };
                
                ws.send(JSON.stringify(data));
                console.log('Message sent:', data);
                
                const messagesDiv = document.getElementById('messages');
                if (messagesDiv) {
                    const messageEl = document.createElement('div');
                    messageEl.className = 'message sent';
                    messageEl.textContent = 'Sent: ' + JSON.stringify(data);
                    messagesDiv.appendChild(messageEl);
                    messagesDiv.scrollTop = messagesDiv.scrollHeight;
                }
                
                return true;
            } else {
                console.error('WebSocket is not connected');
                return false;
            }
        },
        close: function() {
            ws.close();
        }
    };
};