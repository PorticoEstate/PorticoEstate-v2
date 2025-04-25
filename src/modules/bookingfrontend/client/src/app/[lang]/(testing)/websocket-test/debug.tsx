'use client';

import React, { useEffect, useState } from 'react';
import { checkServiceWorkerSupport } from '@/service/websocket/service-worker-check';
import { getBasePath } from '@/service/websocket/util';

/**
 * A component that displays detailed debugging information about service worker support
 */
export const ServiceWorkerDebug: React.FC = () => {
  const [swSupport, setSwSupport] = useState<{
    supported: boolean;
    reason?: string;
  }>({ supported: false });

  const [browserInfo, setBrowserInfo] = useState<{
    userAgent: string;
    isSecureContext: boolean;
    hasServiceWorkerObject: boolean;
    serviceWorkerHasMethods: boolean;
    protocol: string;
    hostname: string;
    basePath: string;
  }>({
    userAgent: '',
    isSecureContext: false,
    hasServiceWorkerObject: false,
    serviceWorkerHasMethods: false,
    protocol: '',
    hostname: '',
    basePath: ''
  });

  useEffect(() => {
    // Basic browser info
    if (typeof window !== 'undefined') {
      setBrowserInfo({
        userAgent: navigator.userAgent,
        isSecureContext: !!window.isSecureContext,
        hasServiceWorkerObject: 'serviceWorker' in navigator,
        serviceWorkerHasMethods: !!navigator.serviceWorker &&
                               typeof navigator.serviceWorker.register === 'function' &&
                               typeof navigator.serviceWorker.getRegistrations === 'function',
        protocol: window.location.protocol,
        hostname: window.location.hostname,
        basePath: getBasePath()
      });

      // Comprehensive service worker check
      checkServiceWorkerSupport().then(result => {
        setSwSupport(result);
      });
    }
  }, []);


  return (
    <div style={{
      padding: '1rem',
      backgroundColor: '#f5f5f5',
      borderRadius: '0.5rem',
      marginTop: '2rem',
      fontFamily: 'monospace',
    }}>
      <h3>Service Worker Debug Info</h3>

      <div style={{ marginBottom: '1rem' }}>
        <h4>Environment:</h4>
        <ul style={{ listStyleType: 'none', padding: 0 }}>
          <li>• Protocol: <code>{browserInfo.protocol}</code></li>
          <li>• Hostname: <code>{browserInfo.hostname}</code></li>
          <li>• Base Path: <code>{browserInfo.basePath || '(none)'}</code></li>
          <li>• User Agent: <code>{browserInfo.userAgent}</code></li>
          <li>• Secure Context: <code>{browserInfo.isSecureContext ? 'Yes ✅' : 'No ❌'}</code></li>
        </ul>
      </div>

      <div style={{ marginBottom: '1rem' }}>
        <h4>Service Worker API:</h4>
        <ul style={{ listStyleType: 'none', padding: 0 }}>
          <li>• &#39;serviceWorker&#39; in navigator: <code>{browserInfo.hasServiceWorkerObject ? 'Yes ✅' : 'No ❌'}</code></li>
          <li>• Required methods available: <code>{browserInfo.serviceWorkerHasMethods ? 'Yes ✅' : 'No ❌'}</code></li>
        </ul>
      </div>

      <div style={{ marginBottom: '1rem' }}>
        <h4>Comprehensive Check Result:</h4>
        <div style={{
          padding: '0.5rem',
          backgroundColor: swSupport.supported ? '#e6ffe6' : '#ffe6e6',
          borderRadius: '0.25rem',
        }}>
          <strong>Supported: </strong>
          <code>{swSupport.supported ? 'Yes ✅' : 'No ❌'}</code>
          {!swSupport.supported && swSupport.reason && (
            <div>
              <strong>Reason: </strong>
              <code>{swSupport.reason}</code>
            </div>
          )}
        </div>

        <div style={{
          marginTop: '0.5rem',
          padding: '0.5rem',
          backgroundColor: '#e6f7ff',
          borderLeft: '4px solid #1890ff',
          fontSize: '0.9em',
        }}>
          <strong>📢 Service Worker Mode:</strong> Using {swSupport.supported ? 'Service Workers' : 'direct WebSocket fallback'}.
          {swSupport.supported
            ? ' WebSocket connections will persist across page navigations.'
            : ' Service Workers require a secure context (HTTPS or localhost without a port).'}
        </div>
      </div>


      <div style={{ marginTop: '1rem', fontSize: '0.85rem' }}>
        <p>
          <strong>Note:</strong> Service workers require a secure context (HTTPS or localhost).
          They also need proper file hosting with correct MIME types.
        </p>
        <p>
          <strong>Common issues:</strong> HTTP site, cross-origin iframe, Content-Type misconfiguration,
          or <strong>untrusted SSL certificates (bypassed security warnings)</strong>.
        </p>

        {!swSupport.supported && (
          <div style={{
            marginTop: '0.5rem',
            padding: '0.5rem',
            backgroundColor: '#d4edda',
            borderLeft: '4px solid #28a745',
            marginBottom: '0.5rem',
          }}>
            <strong>✅ Using WebSocket Fallback:</strong> The WebSocket functionality will continue
            to work with a direct connection (without service workers). Your real-time features are
            still operational, but connection will not persist across page navigations.
          </div>
        )}

        {swSupport.supported && (
          <div style={{
            marginTop: '0.5rem',
            padding: '0.5rem',
            backgroundColor: '#d4edda',
            borderLeft: '4px solid #28a745',
            marginBottom: '0.5rem',
          }}>
            <strong>✅ Using Service Workers:</strong> WebSocket connections will persist across page
            navigations, providing a seamless real-time experience. Even when navigating between pages,
            your connection will be maintained.
          </div>
        )}
        {(swSupport.reason?.includes('Certificate not trusted') ||
          swSupport.reason?.includes('insecure') ||
          !browserInfo.isSecureContext) && (
          <div style={{
            padding: '0.5rem',
            backgroundColor: '#fff3cd',
            borderLeft: '4px solid #ffc107',
            marginTop: '0.5rem',
          }}>
            <strong>⚠️ Certificate Warning Detected:</strong> Service workers will not work when a site
            is accessed with an untrusted certificate (where you had to click &#34;Accept the Risk&#34; or
            similar). This is a browser security restriction that cannot be bypassed.
            <div style={{ marginTop: '0.5rem' }}>
              <strong>Solutions:</strong>
              <ul>
                <li>Install the mkcert root CA certificate in your browser/OS:
                  <div style={{margin: '0.5rem 0', padding: '0.5rem', backgroundColor: '#f8f9fa', borderRadius: '0.25rem'}}>
                    <p>The certificate is located at: <code>~/Library/Application Support/mkcert/rootCA.pem</code></p>
                    <p>For Firefox: Go to Preferences &gt; Privacy & Security &gt; View Certificates &gt; Authorities &gt; Import</p>
                    <p>For Chrome: Go to Settings &gt; Privacy and security &gt; Security &gt; Manage certificates &gt; Authorities &gt; Import</p>
                    <p>For Safari/macOS: Double-click the rootCA.pem file to add it to the macOS Keychain. Then mark it as trusted.</p>
                  </div>
                </li>
                <li>Once installed, restart your browser and refresh the page</li>
                <li>If still not working, try accessing via localhost instead (which is treated as secure)</li>
              </ul>
            </div>
          </div>
        )}

        {swSupport.reason?.includes('URL scheme') && (
          <div style={{
            padding: '0.5rem',
            backgroundColor: '#fff3cd',
            borderLeft: '4px solid #ffc107',
            marginTop: '0.5rem',
          }}>
            <strong>⚠️ Invalid URL Scheme:</strong> Service workers can only be registered from secure origins (HTTPS or localhost).
            <div style={{ marginTop: '0.5rem' }}>
              <strong>Solutions:</strong>
              <ul>
                <li>Access the site via HTTPS instead of HTTP</li>
                <li>Use localhost for development (automatically treated as secure)</li>
                <li>Check that the hostname matches your SSL certificate</li>
              </ul>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};