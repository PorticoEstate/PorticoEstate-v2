import { useEffect, useRef, useState } from 'react';
import type { Email, Folder } from '../types';
import styles from './EmailView.module.scss';

interface EmailViewProps {
  email: Email | null;
  folder: Folder;
  onTrash: (filename: string) => void;
  onRestore: (filename: string) => void;
  onPermanentDelete: (filename: string) => void;
  onMarkUnread: (filename: string) => void;
  onClose: () => void;
}

function formatFullDate(dateStr: string): string {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  return date.toLocaleDateString('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }) + ', ' + date.toLocaleTimeString('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });
}

function EmailView({ email, folder, onTrash, onRestore, onPermanentDelete, onMarkUnread, onClose }: EmailViewProps) {
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const [iframeHeight, setIframeHeight] = useState(400);

  useEffect(() => {
    if (!email || !iframeRef.current) return;

    const iframe = iframeRef.current;
    setIframeHeight(400);

    fetch(`/api/emails/${encodeURIComponent(email.filename)}`)
      .then((res) => res.text())
      .then((html) => {
        const doc = iframe.contentDocument;
        if (!doc) return;
        doc.open();
        doc.write(html);
        doc.close();

        // Measure once after content loads, no observer loop
        const measure = () => {
          if (doc.body) {
            const h = doc.body.scrollHeight;
            if (h > 0) setIframeHeight(h);
          }
        };

        // Wait for images/styles to load before measuring
        measure();
        setTimeout(measure, 300);
        setTimeout(measure, 1000);
      })
      .catch((err) => console.error('Failed to load email:', err));
  }, [email]);

  if (!email) {
    return (
      <div className={styles.container}>
        <div className={styles.empty}>
          <svg width="64" height="64" viewBox="0 0 24 24" fill="#dadce0">
            <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" />
          </svg>
          <p>Select an email to read</p>
        </div>
      </div>
    );
  }

  return (
    <div className={styles.container}>
      <div className={styles.toolbar}>
        <button
          className={styles.toolbarButton}
          onClick={onClose}
          aria-label="Back to list"
          title="Back to list"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="#5f6368">
            <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" />
          </svg>
        </button>

        {folder === 'inbox' && (
          <>
            <button
              className={styles.toolbarButton}
              onClick={() => onTrash(email.filename)}
              aria-label="Move to Trash"
              title="Move to Trash"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="#5f6368">
                <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" />
              </svg>
            </button>
            <button
              className={styles.toolbarButton}
              onClick={() => onMarkUnread(email.filename)}
              aria-label="Mark as unread"
              title="Mark as unread"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="#5f6368">
                <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" />
              </svg>
            </button>
          </>
        )}

        {folder === 'trash' && (
          <>
            <button
              className={styles.toolbarButton}
              onClick={() => onRestore(email.filename)}
              aria-label="Move to Inbox"
              title="Move to Inbox"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="#5f6368">
                <path d="M19 3H4.99c-1.11 0-1.98.89-1.98 2L3 19c0 1.1.88 2 1.99 2H19c1.1 0 2-.9 2-2V5c0-1.11-.9-2-2-2zm0 12h-4c0 1.66-1.35 3-3 3s-3-1.34-3-3H4.99V5H19v10z" />
              </svg>
            </button>
            <button
              className={styles.toolbarButtonDanger}
              onClick={() => onPermanentDelete(email.filename)}
              aria-label="Delete forever"
              title="Delete forever"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zm2.46-7.12l1.41-1.41L12 12.59l2.12-2.12 1.41 1.41L13.41 14l2.12 2.12-1.41 1.41L12 15.41l-2.12 2.12-1.41-1.41L10.59 14l-2.13-2.12zM15.5 4l-1-1h-5l-1 1H5v2h14V4z" />
              </svg>
            </button>
          </>
        )}
      </div>

      {folder === 'trash' && (
        <div className={styles.trashBanner}>
          This message is in Trash.
        </div>
      )}

      <div className={styles.content}>
        <h1 className={styles.subject}>{email.subject}</h1>

        <div className={styles.meta}>
          <div className={styles.senderInfo}>
            <div className={styles.avatar}>
              {(email.from[0] ?? '?').toUpperCase()}
            </div>
            <div>
              <div className={styles.senderName}>{email.from}</div>
              <div className={styles.recipient}>to {email.to}</div>
            </div>
          </div>
          <div className={styles.dateText}>{formatFullDate(email.date)}</div>
        </div>

        <iframe
          ref={iframeRef}
          className={styles.emailFrame}
          style={{ height: `${iframeHeight}px` }}
          sandbox="allow-same-origin"
          title="Email content"
        />
      </div>
    </div>
  );
}

export default EmailView;
