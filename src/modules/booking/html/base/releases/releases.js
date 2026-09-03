(function () {
	'use strict';

	var CFG = window.__releaseHistory || {};
	var L = CFG.lang || {};
	var REPO = CFG.repo;
	var VERSIONS = CFG.versions || []; // newest first: [{commitId, firstSeen}, ...]

	var API = 'https://api.github.com/repos/' + REPO;
	var CACHE_TTL = 30 * 60 * 1000; // 30 min — be gentle on the unauthenticated rate limit

	var listEl = document.getElementById('release-list');
	var errorEl = document.getElementById('release-error');

	// --- helpers -----------------------------------------------------------

	function shortSha(sha) {
		return (sha || '').slice(0, 7);
	}

	// "2026-06-17 09:44:24.091638" -> "2026-06-17"
	function datePart(ts) {
		return (ts || '').split(' ')[0] || (ts || '');
	}

	function formatDateTime(iso) {
		if (!iso) return '';
		var d = new Date(iso);
		if (isNaN(d.getTime())) return iso;
		return d.toLocaleString();
	}

	function subjectOf(message) {
		return (message || '').split('\n')[0];
	}

	function el(tag, className, text) {
		var e = document.createElement(tag);
		if (className) e.className = className;
		if (text != null) e.textContent = text;
		return e;
	}

	// Normalize a GitHub commit object (from compare or single-commit endpoints).
	function normalize(c) {
		var commit = c.commit || {};
		var author = commit.author || {};
		return {
			sha: c.sha,
			url: c.html_url,
			subject: subjectOf(commit.message),
			authorName: author.name || (c.author && c.author.login) || '',
			date: author.date || ''
		};
	}

	function cachedFetchJson(url) {
		var key = 'release-history:' + url;
		try {
			var raw = sessionStorage.getItem(key);
			if (raw) {
				var entry = JSON.parse(raw);
				if (entry && (Date.now() - entry.t) < CACHE_TTL) {
					return Promise.resolve(entry.d);
				}
			}
		} catch (e) { /* ignore cache read errors */ }

		return fetch(url, { headers: { 'Accept': 'application/vnd.github.v3+json' } })
			.then(function (res) {
				if (!res.ok) {
					var err = new Error('GitHub ' + res.status);
					err.status = res.status;
					throw err;
				}
				return res.json();
			})
			.then(function (data) {
				try {
					sessionStorage.setItem(key, JSON.stringify({ t: Date.now(), d: data }));
				} catch (e) { /* quota / private mode — ignore */ }
				return data;
			});
	}

	// Commits in (base, head] — base excluded, head included — newest first.
	// When there is no base (oldest recorded version) just resolve the head commit.
	function fetchCommits(baseSha, headSha) {
		if (baseSha) {
			return cachedFetchJson(API + '/compare/' + baseSha + '...' + headSha)
				.then(function (data) {
					return (data.commits || []).map(normalize).reverse();
				});
		}
		return cachedFetchJson(API + '/commits/' + headSha)
			.then(function (data) {
				return [normalize(data)];
			});
	}

	// --- rendering ---------------------------------------------------------

	function renderCommitRow(c) {
		var row = el('li', 'release-commit');

		var link = el('a', 'release-commit__sha', shortSha(c.sha));
		link.href = c.url || ('https://github.com/' + REPO + '/commit/' + c.sha);
		link.target = '_blank';
		link.rel = 'noopener';
		row.appendChild(link);

		var body = el('div', 'release-commit__body');
		body.appendChild(el('span', 'release-commit__subject', c.subject));

		var meta = el('span', 'release-commit__meta');
		var bits = [];
		if (c.authorName) bits.push((L.by || 'by') + ' ' + c.authorName);
		if (c.date) bits.push(formatDateTime(c.date));
		meta.textContent = bits.join(' · ');
		body.appendChild(meta);

		row.appendChild(body);
		return row;
	}

	function buildVersionSection(version, index) {
		var section = el('section', 'release-version');

		var header = el('div', 'release-version__header');
		header.appendChild(el('h3', 'release-version__title', datePart(version.firstSeen)));

		if (index === 0 && L.running) {
			header.appendChild(el('span', 'release-version__badge ds-tag', L.running));
		}
		section.appendChild(header);

		var subtitle = el('div', 'release-version__subtitle');
		var shaLink = el('a', 'release-version__sha', shortSha(version.commitId));
		shaLink.href = 'https://github.com/' + REPO + '/commit/' + version.commitId;
		shaLink.target = '_blank';
		shaLink.rel = 'noopener';
		subtitle.appendChild(shaLink);
		var count = el('span', 'release-version__count', L.loading || 'Loading…');
		subtitle.appendChild(count);
		section.appendChild(subtitle);

		var ul = el('ul', 'release-version__commits');
		section.appendChild(ul);

		return { section: section, list: ul, count: count };
	}

	function fillSection(parts, version, baseVersion) {
		var baseSha = baseVersion ? baseVersion.commitId : null;
		fetchCommits(baseSha, version.commitId)
			.then(function (commits) {
				parts.list.innerHTML = '';
				if (!commits.length) {
					parts.count.textContent = L.no_commits || 'No new commits';
					return;
				}
				var template = L.commits_count || '%s commits';
				parts.count.textContent = template.replace('%s', commits.length);
				commits.forEach(function (c) {
					parts.list.appendChild(renderCommitRow(c));
				});
			})
			.catch(function (err) {
				parts.count.textContent = (L.unavailable || 'Commits unavailable')
					+ (err && err.status ? ' (' + err.status + ')' : '');
			});
	}

	// --- init --------------------------------------------------------------

	function init() {
		if (!REPO || !VERSIONS.length) {
			listEl.appendChild(el('p', 'release-history__empty', L.no_commits || 'No versions recorded yet.'));
			return;
		}

		VERSIONS.forEach(function (version, i) {
			var baseVersion = VERSIONS[i + 1]; // the previous (older) recorded commit
			var parts = buildVersionSection(version, i);
			listEl.appendChild(parts.section);
			fillSection(parts, version, baseVersion);
		});
	}

	init();
})();
