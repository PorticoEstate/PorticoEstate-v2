import type { Folder } from '../types';
import styles from './Sidebar.module.scss';

interface SidebarProps {
  emailCount: number;
  unreadCount: number;
  trashCount: number;
  activeFolder: Folder;
  onFolderChange: (folder: Folder) => void;
}

function Sidebar({ emailCount, unreadCount, trashCount, activeFolder, onFolderChange }: SidebarProps) {
  return (
    <aside className={styles.sidebar}>
      <nav className={styles.nav}>
        <button
          className={`${styles.navItem} ${activeFolder === 'inbox' ? styles.active : ''}`}
          onClick={() => onFolderChange('inbox')}
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19 3H4.99c-1.11 0-1.98.89-1.98 2L3 19c0 1.1.88 2 1.99 2H19c1.1 0 2-.9 2-2V5c0-1.11-.9-2-2-2zm0 12h-4c0 1.66-1.35 3-3 3s-3-1.34-3-3H4.99V5H19v10z" />
          </svg>
          <span className={styles.navLabel}>Inbox</span>
          {unreadCount > 0 ? (
            <span className={styles.navCountBold}>{unreadCount}</span>
          ) : (
            <span className={styles.navCount}>{emailCount}</span>
          )}
        </button>

        <button
          className={`${styles.navItem} ${activeFolder === 'trash' ? styles.active : ''}`}
          onClick={() => onFolderChange('trash')}
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
            <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" />
          </svg>
          <span className={styles.navLabel}>Trash</span>
          {trashCount > 0 && (
            <span className={styles.navCount}>{trashCount}</span>
          )}
        </button>
      </nav>
    </aside>
  );
}

export default Sidebar;
