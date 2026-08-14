import styles from './Header.module.scss';

interface HeaderProps {
  searchQuery: string;
  onSearchChange: (query: string) => void;
  onRefresh: () => void;
  onToggleSidebar: () => void;
}

function Header({ searchQuery, onSearchChange, onRefresh, onToggleSidebar }: HeaderProps) {
  return (
    <header className={styles.header}>
      <div className={styles.logo}>
        <button className={styles.menuButton} aria-label="Toggle sidebar" onClick={onToggleSidebar}>
          <svg width="24" height="24" viewBox="0 0 24 24" fill="#5f6368">
            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
          </svg>
        </button>
        <span className={styles.logoText}>
          <span className={styles.logoD}>D</span>mail
        </span>
      </div>
      <div className={styles.searchBar}>
        <svg className={styles.searchIcon} width="20" height="20" viewBox="0 0 24 24" fill="#5f6368">
          <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
        </svg>
        <input
          type="text"
          className={styles.searchInput}
          placeholder="Search mail"
          value={searchQuery}
          onChange={(e) => onSearchChange(e.target.value)}
        />
        {searchQuery && (
          <button
            className={styles.clearButton}
            onClick={() => onSearchChange('')}
            aria-label="Clear search"
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="#5f6368">
              <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" />
            </svg>
          </button>
        )}
      </div>
      <div className={styles.actions}>
        <button
          className={styles.iconButton}
          onClick={onRefresh}
          aria-label="Refresh"
          title="Refresh"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="#5f6368">
            <path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z" />
          </svg>
        </button>
      </div>
    </header>
  );
}

export default Header;
