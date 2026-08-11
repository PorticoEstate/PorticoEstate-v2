export interface Email {
  from: string;
  to: string;
  subject: string;
  date: string;
  filename: string;
  snippet: string;
  read: boolean;
  trashed: boolean;
}

export type Folder = 'inbox' | 'trash';
