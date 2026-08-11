import express from 'express';
import { readdir, readFile, writeFile, unlink, watch } from 'node:fs/promises';
import { join, extname } from 'node:path';

const app = express();
const PORT = process.env.API_PORT || process.env.PORT || 3002;
const EMAILS_DIR = process.env.EMAILS_DIR || '/emails';
const READ_STATUS_FILE = join(EMAILS_DIR, '.dmail-read.json');
const TRASH_FILE = join(EMAILS_DIR, '.dmail-trash.json');

// --- Read status helpers ---

async function getReadStatus() {
  try {
    const data = await readFile(READ_STATUS_FILE, 'utf-8');
    return JSON.parse(data);
  } catch {
    return {};
  }
}

async function saveReadStatus(status) {
  await writeFile(READ_STATUS_FILE, JSON.stringify(status, null, 2));
}

async function markAsRead(filename) {
  const status = await getReadStatus();
  if (status[filename]) return status;
  status[filename] = new Date().toISOString();
  await saveReadStatus(status);
  return status;
}

// --- Trash helpers ---

async function getTrash() {
  try {
    const data = await readFile(TRASH_FILE, 'utf-8');
    return JSON.parse(data);
  } catch {
    return {};
  }
}

async function saveTrash(trash) {
  await writeFile(TRASH_FILE, JSON.stringify(trash, null, 2));
}

// --- Email parsing ---

function parseEmailMetadata(html, filename) {
  const metadata = {
    from: '',
    to: '',
    subject: '',
    date: '',
    filename,
    snippet: '',
  };

  // Format 1: HTML comment metadata <!-- From: ... To: ... -->
  const commentMatch = html.match(/<!--\s*([\s\S]*?)-->/);
  if (commentMatch) {
    const comment = commentMatch[1];
    const fromMatch = comment.match(/From:\s*(.+)/i);
    const toMatch = comment.match(/To:\s*(.+)/i);
    const subjectMatch = comment.match(/Subject:\s*(.+)/i);
    const dateMatch = comment.match(/Date:\s*(.+)/i);

    if (fromMatch) metadata.from = fromMatch[1].trim();
    if (toMatch) metadata.to = toMatch[1].trim();
    if (subjectMatch) metadata.subject = subjectMatch[1].trim();
    if (dateMatch) metadata.date = dateMatch[1].trim();
  }

  // Format 2: Styled div with <strong>From:</strong> value<br>
  if (!metadata.subject) {
    const divMatch = html.match(/<div[^>]*>[\s\S]*?<strong>From:<\/strong>\s*([\s\S]*?)<\/div>/i);
    if (divMatch) {
      const block = divMatch[1];
      const fromMatch = block.match(/^([^<]+)/);
      const toMatch = block.match(/<strong>To:<\/strong>\s*([^<]+)/i);
      const subjectMatch = block.match(/<strong>Subject:<\/strong>\s*([^<]+)/i);
      const dateMatch = block.match(/<strong>Date:<\/strong>\s*([^<]+)/i);

      if (fromMatch) metadata.from = fromMatch[1].trim();
      if (toMatch) metadata.to = toMatch[1].trim();
      if (subjectMatch) metadata.subject = subjectMatch[1].trim();
      if (dateMatch) metadata.date = dateMatch[1].trim();
    }
  }

  // Extract text snippet - strip HTML tags and take body text
  const bodyText = html
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  // Find meaningful content after the metadata
  const helloIdx = bodyText.indexOf('Hello');
  const welcomeIdx = bodyText.indexOf('Welcome');
  const startIdx = helloIdx > 0 ? helloIdx : welcomeIdx > 0 ? welcomeIdx : 0;

  metadata.snippet = bodyText.substring(startIdx).trim().substring(0, 160);

  return metadata;
}

function validateFilename(filename) {
  return !filename.includes('/') && !filename.includes('\\') && !filename.includes('..');
}

// --- SSE for file watching ---

const sseClients = new Set();

app.get('/api/events', (req, res) => {
  res.writeHead(200, {
    'Content-Type': 'text/event-stream',
    'Cache-Control': 'no-cache',
    Connection: 'keep-alive',
  });

  res.write('data: connected\n\n');
  sseClients.add(res);

  req.on('close', () => {
    sseClients.delete(res);
  });
});

function notifyClients() {
  for (const client of sseClients) {
    client.write('data: refresh\n\n');
  }
}

// Watch the emails directory for changes
let watcherActive = false;

async function startWatcher() {
  try {
    const watcher = watch(EMAILS_DIR);
    watcherActive = true;
    console.log('File watcher active on:', EMAILS_DIR);

    // Debounce: don't fire more than once per second
    let debounceTimer = null;
    for await (const event of watcher) {
      // Ignore our own metadata files
      if (event.filename && event.filename.startsWith('.dmail-')) continue;
      if (event.filename && extname(event.filename) !== '.html') continue;

      if (debounceTimer) clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        notifyClients();
      }, 500);
    }
  } catch (err) {
    console.warn('File watcher not available, clients will use polling fallback:', err.message);
    watcherActive = false;
  }
}

// GET /api/emails?folder=inbox|trash - list emails
app.get('/api/emails', async (req, res) => {
  try {
    const folder = req.query.folder || 'inbox';
    const files = await readdir(EMAILS_DIR);
    const htmlFiles = files.filter((f) => extname(f) === '.html');

    const readStatus = await getReadStatus();
    const trash = await getTrash();

    const emails = await Promise.all(
      htmlFiles.map(async (filename) => {
        const content = await readFile(join(EMAILS_DIR, filename), 'utf-8');
        return {
          ...parseEmailMetadata(content, filename),
          read: !!readStatus[filename],
          trashed: !!trash[filename],
        };
      })
    );

    // Filter by folder
    const filtered = folder === 'trash'
      ? emails.filter((e) => e.trashed)
      : emails.filter((e) => !e.trashed);

    // Sort by date descending (newest first)
    filtered.sort((a, b) => {
      if (!a.date || !b.date) return 0;
      return new Date(b.date).getTime() - new Date(a.date).getTime();
    });

    res.json(filtered);
  } catch (err) {
    if (err.code === 'ENOENT') {
      res.json([]);
    } else {
      console.error('Error reading emails:', err);
      res.status(500).json({ error: 'Failed to read emails' });
    }
  }
});

// GET /api/watcher-status - check if file watcher is active
app.get('/api/watcher-status', (_req, res) => {
  res.json({ active: watcherActive });
});

// GET /api/emails/:filename - get raw HTML of a single email
app.get('/api/emails/:filename', async (req, res) => {
  try {
    const filename = req.params.filename;

    if (!validateFilename(filename)) {
      return res.status(400).json({ error: 'Invalid filename' });
    }

    let content = await readFile(join(EMAILS_DIR, filename), 'utf-8');
    await markAsRead(filename);

    // Strip the metadata div that appears before <html in format 2 emails
    const htmlTagIndex = content.indexOf('<html');
    if (htmlTagIndex > 0) {
      content = content.substring(htmlTagIndex);
    }

    res.type('html').send(content);
  } catch (err) {
    if (err.code === 'ENOENT') {
      res.status(404).json({ error: 'Email not found' });
    } else {
      console.error('Error reading email:', err);
      res.status(500).json({ error: 'Failed to read email' });
    }
  }
});

// POST /api/emails/mark-all-read - mark all inbox emails as read
app.post('/api/emails/mark-all-read', async (_req, res) => {
  try {
    const files = await readdir(EMAILS_DIR);
    const htmlFiles = files.filter((f) => extname(f) === '.html');
    const trash = await getTrash();
    const status = await getReadStatus();

    for (const filename of htmlFiles) {
      if (!trash[filename] && !status[filename]) {
        status[filename] = new Date().toISOString();
      }
    }

    await saveReadStatus(status);
    res.json({ success: true });
  } catch (err) {
    console.error('Error marking all as read:', err);
    res.status(500).json({ error: 'Failed to mark all as read' });
  }
});

// DELETE /api/emails/empty-trash - permanently delete all trashed emails
app.delete('/api/emails/empty-trash', async (_req, res) => {
  try {
    const trash = await getTrash();
    const readStatus = await getReadStatus();

    for (const filename of Object.keys(trash)) {
      try {
        await unlink(join(EMAILS_DIR, filename));
      } catch (err) {
        if (err.code !== 'ENOENT') throw err;
      }
      delete readStatus[filename];
    }

    await saveReadStatus(readStatus);
    await saveTrash({});
    res.json({ success: true });
  } catch (err) {
    console.error('Error emptying trash:', err);
    res.status(500).json({ error: 'Failed to empty trash' });
  }
});

// POST /api/emails/:filename/unread - mark as unread
app.post('/api/emails/:filename/unread', async (req, res) => {
  try {
    const filename = req.params.filename;

    if (!validateFilename(filename)) {
      return res.status(400).json({ error: 'Invalid filename' });
    }

    const status = await getReadStatus();
    delete status[filename];
    await saveReadStatus(status);

    res.json({ success: true });
  } catch (err) {
    console.error('Error marking as unread:', err);
    res.status(500).json({ error: 'Failed to mark as unread' });
  }
});

// POST /api/emails/:filename/trash - move to trash
app.post('/api/emails/:filename/trash', async (req, res) => {
  try {
    const filename = req.params.filename;

    if (!validateFilename(filename)) {
      return res.status(400).json({ error: 'Invalid filename' });
    }

    const trash = await getTrash();
    trash[filename] = new Date().toISOString();
    await saveTrash(trash);

    res.json({ success: true });
  } catch (err) {
    console.error('Error trashing email:', err);
    res.status(500).json({ error: 'Failed to trash email' });
  }
});

// POST /api/emails/:filename/restore - restore from trash
app.post('/api/emails/:filename/restore', async (req, res) => {
  try {
    const filename = req.params.filename;

    if (!validateFilename(filename)) {
      return res.status(400).json({ error: 'Invalid filename' });
    }

    const trash = await getTrash();
    delete trash[filename];
    await saveTrash(trash);

    res.json({ success: true });
  } catch (err) {
    console.error('Error restoring email:', err);
    res.status(500).json({ error: 'Failed to restore email' });
  }
});

// DELETE /api/emails/:filename - permanently delete
app.delete('/api/emails/:filename', async (req, res) => {
  try {
    const filename = req.params.filename;

    if (!validateFilename(filename)) {
      return res.status(400).json({ error: 'Invalid filename' });
    }

    await unlink(join(EMAILS_DIR, filename));

    // Clean up read status and trash
    const readStatus = await getReadStatus();
    delete readStatus[filename];
    await saveReadStatus(readStatus);

    const trash = await getTrash();
    delete trash[filename];
    await saveTrash(trash);

    res.json({ success: true });
  } catch (err) {
    if (err.code === 'ENOENT') {
      res.status(404).json({ error: 'Email not found' });
    } else {
      console.error('Error deleting email:', err);
      res.status(500).json({ error: 'Failed to delete email' });
    }
  }
});

// Serve static files from the built React app
app.use(express.static('dist'));

// SPA fallback - serve index.html for all non-API routes
app.get('*', (_req, res) => {
  res.sendFile(join(import.meta.dirname, 'dist', 'index.html'));
});

app.listen(PORT, () => {
  console.log(`Dmail running at http://localhost:${PORT}`);
  console.log(`Reading emails from: ${EMAILS_DIR}`);
  startWatcher();
});
