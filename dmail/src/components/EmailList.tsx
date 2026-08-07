import { useState, useRef, useEffect } from 'react';
import type { Email, Folder } from '../types';
import styles from './EmailList.module.scss';

interface EmailListProps {
  emails: Email[];
  selectedEmail: Email | null;
  onSelectEmail: (email: Email) => void;
  loading: boolean;
  folder: Folder;
  unreadCount: number;
  onMarkAllRead: () => void;
  onEmptyTrash: () => void;
  onRefresh: () => void;
}

function formatDate(dateStr: string): string {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  const now = new Date();
  const isToday =
    date.getDate() === now.getDate() &&
    date.getMonth() === now.getMonth() &&
    date.getFullYear() === now.getFullYear();

  if (isToday) {
    return date.toLocaleTimeString('en-GB', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    });
  }

  return date.toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
  });
}

function getInitial(from: string): string {
  if (!from) return '?';
  // If it's an email, use the part before @
  const name = from.includes('@') ? from.split('@')[0] : from;
  return (name?.[0] ?? '?').toUpperCase();
}

function getAvatarColor(from: string): string {
  const colors = [
    '#1a73e8', '#ea4335', '#34a853', '#fbbc04',
    '#a142f4', '#e8710a', '#129eaf', '#d93025',
  ];
  let hash = 0;
  for (let i = 0; i < from.length; i++) {
    hash = from.charCodeAt(i) + ((hash << 5) - hash);
  }
  return colors[Math.abs(hash) % colors.length] ?? colors[0]!;
}

function EmailList({ emails, selectedEmail, onSelectEmail, loading, folder, unreadCount, onMarkAllRead, onEmptyTrash, onRefresh }: EmailListProps) {
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!menuOpen) return;
    const handleClickOutside = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [menuOpen]);

  const folderLabel = folder === 'trash' ? 'Trash' : 'Inbox';

  const header = (
    <div className={styles.header}>
      <div className={styles.headerLeft}>
        <h2 className={styles.headerTitle}>{folderLabel}</h2>
        {folder === 'inbox' && unreadCount > 0 && (
          <span className={styles.unreadBadge}>{unreadCount}</span>
        )}
      </div>
      <div className={styles.headerActions} ref={menuRef}>
        <button
          className={styles.menuButton}
          onClick={() => setMenuOpen((prev) => !prev)}
          aria-label="More actions"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
            <circle cx="12" cy="6" r="2" />
            <circle cx="12" cy="12" r="2" />
            <circle cx="12" cy="18" r="2" />
          </svg>
        </button>
        {menuOpen && (
          <div className={styles.menu}>
            {folder === 'inbox' && unreadCount > 0 && (
              <button
                className={styles.menuItem}
                onClick={() => { onMarkAllRead(); setMenuOpen(false); }}
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z" />
                </svg>
                Mark all as read
              </button>
            )}
            {folder === 'trash' && emails.length > 0 && (
              <button
                className={styles.menuItem}
                onClick={() => { onEmptyTrash(); setMenuOpen(false); }}
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" />
                </svg>
                Empty trash
              </button>
            )}
            <button
              className={styles.menuItem}
              onClick={() => { onRefresh(); setMenuOpen(false); }}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                <path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z" />
              </svg>
              Refresh
            </button>
          </div>
        )}
      </div>
    </div>
  );

  if (loading) {
    return (
      <div className={styles.container}>
        {header}
        <div className={styles.loading}>Loading emails...</div>
      </div>
    );
  }

  if (emails.length === 0) {
    return (
      <div className={styles.container}>
        {header}
        <div className={styles.empty}>
          {folder === 'trash' ? (
            <svg width="48" height="48" viewBox="0 0 24 24" fill="#dadce0">
              <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" />
            </svg>
          ) : (
            <svg width="48" height="48" viewBox="0 0 24 24" fill="#dadce0">
              <path d="M19 3H4.99c-1.11 0-1.98.89-1.98 2L3 19c0 1.1.88 2 1.99 2H19c1.1 0 2-.9 2-2V5c0-1.11-.9-2-2-2zm0 12h-4c0 1.66-1.35 3-3 3s-3-1.34-3-3H4.99V5H19v10z" />
            </svg>
          )}
          <p>{folder === 'trash' ? 'Trash is empty' : 'No emails found'}</p>
        </div>
      </div>
    );
  }

  return (
    <div className={styles.container}>
      {header}
      <div className={styles.list}>
        {emails.map((email) => (
          <button
            key={email.filename}
            className={`${styles.row} ${
              selectedEmail?.filename === email.filename ? styles.selected : ''
            } ${!email.read ? styles.unread : ''}`}
            onClick={() => onSelectEmail(email)}
          >
            <div
              className={styles.avatar}
              style={{ backgroundColor: getAvatarColor(email.to) }}
            >
              {getInitial(email.to)}
            </div>
            <div className={styles.content}>
              <div className={styles.topRow}>
                <span className={styles.sender}>{email.to}</span>
                <span className={styles.date}>{formatDate(email.date)}</span>
              </div>
              <div className={styles.subject}>{email.subject}</div>
              <div className={styles.snippet}>{email.snippet}</div>
            </div>
          </button>
        ))}
      </div>
    </div>
  );
}

export default EmailList;
