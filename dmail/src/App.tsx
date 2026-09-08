import { useState, useEffect, useCallback, useRef } from 'react';
import type { Email, Folder } from './types';
import Header from './components/Header';
import Sidebar from './components/Sidebar';
import EmailList from './components/EmailList';
import EmailView from './components/EmailView';
import styles from './App.module.scss';

const POLL_INTERVAL = 10_000; // 10s fallback polling

const SIDEBAR_MIN = 150;
const SIDEBAR_MAX = 400;
const SIDEBAR_DEFAULT = 256;
const EMAIL_LIST_MIN = 280;
const EMAIL_LIST_MAX = 600;
const EMAIL_LIST_DEFAULT = 400;

type DragTarget = 'sidebar' | 'emailList' | null;

function App() {
  const [emails, setEmails] = useState<Email[]>([]);
  const [selectedEmail, setSelectedEmail] = useState<Email | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [folder, setFolder] = useState<Folder>('inbox');
  const [trashCount, setTrashCount] = useState(0);
  const [sidebarOpen, setSidebarOpen] = useState(() => {
    const stored = localStorage.getItem('dmail-sidebar');
    return stored !== null ? stored === 'true' : true;
  });
  const folderRef = useRef(folder);

  const [sidebarWidth, setSidebarWidth] = useState(() => {
    const stored = localStorage.getItem('dmail-sidebar-width');
    return stored ? Number(stored) : SIDEBAR_DEFAULT;
  });
  const [emailListWidth, setEmailListWidth] = useState(() => {
    const stored = localStorage.getItem('dmail-emaillist-width');
    return stored ? Number(stored) : EMAIL_LIST_DEFAULT;
  });
  const [dragTarget, setDragTarget] = useState<DragTarget>(null);
  const dragStartX = useRef(0);
  const dragStartWidth = useRef(0);

  const handleMouseDown = useCallback((target: DragTarget, e: React.MouseEvent) => {
    e.preventDefault();
    setDragTarget(target);
    dragStartX.current = e.clientX;
    dragStartWidth.current = target === 'sidebar' ? sidebarWidth : emailListWidth;
  }, [sidebarWidth, emailListWidth]);

  useEffect(() => {
    if (!dragTarget) return;

    const handleMouseMove = (e: MouseEvent) => {
      const delta = e.clientX - dragStartX.current;
      const newWidth = dragStartWidth.current + delta;

      if (dragTarget === 'sidebar') {
        setSidebarWidth(Math.min(SIDEBAR_MAX, Math.max(SIDEBAR_MIN, newWidth)));
      } else {
        setEmailListWidth(Math.min(EMAIL_LIST_MAX, Math.max(EMAIL_LIST_MIN, newWidth)));
      }
    };

    const handleMouseUp = () => {
      setDragTarget((prev) => {
        if (prev === 'sidebar') {
          setSidebarWidth((w) => { localStorage.setItem('dmail-sidebar-width', String(w)); return w; });
        } else if (prev === 'emailList') {
          setEmailListWidth((w) => { localStorage.setItem('dmail-emaillist-width', String(w)); return w; });
        }
        return null;
      });
    };

    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);
    return () => {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
    };
  }, [dragTarget]);

  // Keep ref in sync so SSE/polling callbacks use the latest folder
  useEffect(() => {
    folderRef.current = folder;
  }, [folder]);

  const fetchEmails = useCallback(async (targetFolder?: Folder) => {
    const f = targetFolder ?? folderRef.current;
    try {
      const [mainRes, otherRes] = await Promise.all([
        fetch(`/api/emails?folder=${f}`),
        fetch(`/api/emails?folder=${f === 'inbox' ? 'trash' : 'inbox'}`),
      ]);

      const data: Email[] = await mainRes.json();
      const otherData: Email[] = await otherRes.json();

      setEmails(data);
      if (f === 'inbox') {
        setTrashCount(otherData.length);
      } else {
        setTrashCount(data.length);
      }
    } catch (err) {
      console.error('Failed to fetch emails:', err);
    } finally {
      setLoading(false);
    }
  }, []);

  // Initial load + folder change
  useEffect(() => {
    setSelectedEmail(null);
    setLoading(true);
    fetchEmails(folder);
  }, [folder, fetchEmails]);

  // SSE with polling fallback
  useEffect(() => {
    let pollTimer: ReturnType<typeof setInterval> | null = null;

    const eventSource = new EventSource('/api/events');

    eventSource.onmessage = (event) => {
      if (event.data === 'refresh') {
        fetchEmails();
      }
    };

    eventSource.onerror = () => {
      // SSE failed, fall back to polling
      eventSource.close();
      pollTimer = setInterval(() => fetchEmails(), POLL_INTERVAL);
    };

    // Also check watcher status - if not active, start polling
    fetch('/api/watcher-status')
      .then((res) => res.json())
      .then((data: { active: boolean }) => {
        if (!data.active && !pollTimer) {
          pollTimer = setInterval(() => fetchEmails(), POLL_INTERVAL);
        }
      })
      .catch(() => {
        // Can't reach server, will retry via SSE reconnect
      });

    return () => {
      eventSource.close();
      if (pollTimer) clearInterval(pollTimer);
    };
  }, [fetchEmails]);

  const handleMarkAllRead = async () => {
    try {
      const res = await fetch('/api/emails/mark-all-read', { method: 'POST' });
      if (res.ok) {
        setEmails((prev) => prev.map((e) => ({ ...e, read: true })));
        if (selectedEmail) {
          setSelectedEmail((prev) => prev ? { ...prev, read: true } : null);
        }
      }
    } catch (err) {
      console.error('Failed to mark all as read:', err);
    }
  };

  const handleEmptyTrash = async () => {
    try {
      const res = await fetch('/api/emails/empty-trash', { method: 'DELETE' });
      if (res.ok) {
        setEmails([]);
        setTrashCount(0);
        setSelectedEmail(null);
      }
    } catch (err) {
      console.error('Failed to empty trash:', err);
    }
  };

  const handleTrash = async (filename: string) => {
    try {
      const res = await fetch(`/api/emails/${encodeURIComponent(filename)}/trash`, {
        method: 'POST',
      });
      if (res.ok) {
        setEmails((prev) => prev.filter((e) => e.filename !== filename));
        setTrashCount((prev) => prev + 1);
        if (selectedEmail?.filename === filename) {
          setSelectedEmail(null);
        }
      }
    } catch (err) {
      console.error('Failed to trash email:', err);
    }
  };

  const handleRestore = async (filename: string) => {
    try {
      const res = await fetch(`/api/emails/${encodeURIComponent(filename)}/restore`, {
        method: 'POST',
      });
      if (res.ok) {
        setEmails((prev) => prev.filter((e) => e.filename !== filename));
        setTrashCount((prev) => Math.max(0, prev - 1));
        if (selectedEmail?.filename === filename) {
          setSelectedEmail(null);
        }
      }
    } catch (err) {
      console.error('Failed to restore email:', err);
    }
  };

  const handlePermanentDelete = async (filename: string) => {
    try {
      const res = await fetch(`/api/emails/${encodeURIComponent(filename)}`, {
        method: 'DELETE',
      });
      if (res.ok) {
        setEmails((prev) => prev.filter((e) => e.filename !== filename));
        setTrashCount((prev) => Math.max(0, prev - 1));
        if (selectedEmail?.filename === filename) {
          setSelectedEmail(null);
        }
      }
    } catch (err) {
      console.error('Failed to delete email:', err);
    }
  };

  const handleMarkUnread = async (filename: string) => {
    try {
      const res = await fetch(`/api/emails/${encodeURIComponent(filename)}/unread`, {
        method: 'POST',
      });
      if (res.ok) {
        setEmails((prev) =>
          prev.map((e) =>
            e.filename === filename ? { ...e, read: false } : e
          )
        );
        if (selectedEmail?.filename === filename) {
          setSelectedEmail((prev) => prev ? { ...prev, read: false } : null);
        }
      }
    } catch (err) {
      console.error('Failed to mark as unread:', err);
    }
  };

  const handleSelectEmail = (email: Email) => {
    setSelectedEmail(email);
    if (!email.read) {
      setEmails((prev) =>
        prev.map((e) =>
          e.filename === email.filename ? { ...e, read: true } : e
        )
      );
    }
  };

  const handleFolderChange = (newFolder: Folder) => {
    setFolder(newFolder);
  };

  const unreadCount = folder === 'inbox'
    ? emails.filter((e) => !e.read).length
    : 0;

  useEffect(() => {
    document.title = unreadCount > 0 ? `(${unreadCount}) Dmail` : 'Dmail';
  }, [unreadCount]);

  const filteredEmails = emails.filter((email) => {
    if (!searchQuery) return true;
    const q = searchQuery.toLowerCase();
    return (
      email.subject.toLowerCase().includes(q) ||
      email.from.toLowerCase().includes(q) ||
      email.to.toLowerCase().includes(q) ||
      email.snippet.toLowerCase().includes(q)
    );
  });

  return (
    <div className={styles.app}>
      <Header
        searchQuery={searchQuery}
        onSearchChange={setSearchQuery}
        onRefresh={() => fetchEmails()}
        onToggleSidebar={() => setSidebarOpen((prev) => {
          const next = !prev;
          localStorage.setItem('dmail-sidebar', String(next));
          return next;
        })}
      />
      <div className={styles.body}>
        {dragTarget && <div className={styles.resizeOverlay} />}
        {sidebarOpen && (
          <>
            <div style={{ width: sidebarWidth, minWidth: SIDEBAR_MIN, flexShrink: 0 }}>
              <Sidebar
                emailCount={emails.length}
                unreadCount={unreadCount}
                trashCount={trashCount}
                activeFolder={folder}
                onFolderChange={handleFolderChange}
              />
            </div>
            <div
              className={`${styles.resizeHandle} ${dragTarget === 'sidebar' ? styles.dragging : ''}`}
              onMouseDown={(e) => handleMouseDown('sidebar', e)}
            />
          </>
        )}
        <div style={{ width: emailListWidth, minWidth: EMAIL_LIST_MIN, flexShrink: 0 }}>
          <EmailList
            emails={filteredEmails}
            selectedEmail={selectedEmail}
            onSelectEmail={handleSelectEmail}
            loading={loading}
            folder={folder}
            unreadCount={unreadCount}
            onMarkAllRead={handleMarkAllRead}
            onEmptyTrash={handleEmptyTrash}
            onRefresh={() => fetchEmails()}
          />
        </div>
        <div
          className={`${styles.resizeHandle} ${dragTarget === 'emailList' ? styles.dragging : ''}`}
          onMouseDown={(e) => handleMouseDown('emailList', e)}
        />
        <EmailView
          email={selectedEmail}
          folder={folder}
          onTrash={handleTrash}
          onRestore={handleRestore}
          onPermanentDelete={handlePermanentDelete}
          onMarkUnread={handleMarkUnread}
          onClose={() => setSelectedEmail(null)}
        />
      </div>
    </div>
  );
}

export default App;
