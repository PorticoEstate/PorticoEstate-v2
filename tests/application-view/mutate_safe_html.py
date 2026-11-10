"""Mutants of utils/safe-html.ts for mutate_safe_html.sh: name -> (old text, new text). Each old text must occur."""
MUTANTS = {
    "no-scheme-check": ("SAFE_URL.test(url) ? url : null", "true ? url : null"),
    "scheme-check-without-the-browsers-trimming": ("const url = href.replace(URL_DROPPED, '').replace(URL_TRIMMED, '');", "const url = href;"),
    "control-character-inside-scheme-ignored": ("return SAFE_URL.test(url) ? url : null;", "return SAFE_URL.test(url.replace(/[\\u0000-\\u001F]/g, '')) ? url : null;"),
    "href-emitted-as-written": ("return SAFE_URL.test(url) ? url : null;", "return SAFE_URL.test(url) ? href.trim() : null;"),
    "scheme-check-case-sensitive": ("const SAFE_URL = /^(https?|mailto|tel):/i;", "const SAFE_URL = /^(https?|mailto|tel):/;"),
    "attributes-passed-through": ("createElement(PARAGRAPH_TAGS.has(name) ? 'p' : name, null, render(children))",
                                  "createElement(PARAGRAPH_TAGS.has(name) ? 'p' : name, node.attribs, render(children))"),
    "img-allowed": ("['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'a']", "['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'a', 'img']"),
    "script-unwrapped-not-dropped": ("['script', 'style',", "['style',"),
    "iframe-unwrapped-not-dropped": ("'iframe', ", ""),
    "forced-rel-removed": ("{href, target: '_blank', rel: 'noopener noreferrer'}", "{href, target: '_blank'}"),
    "empty-blocks-kept": ("if (BLOCK_TAGS.has(name) && isBlank(node)) return createElement(Fragment);", ""),
    "nested-edges-not-trimmed": ("return domToReact(trimEdges(nodes), options);", "return domToReact(nodes, options);"),
    "div-unwrapped-not-paragraph": ("const PARAGRAPH_TAGS = new Set(['div', ", "const PARAGRAPH_TAGS = new Set(["),
    "non-allowed-tag-dropped-with-text": ("if (!ALLOWED_TAGS.has(name) && !PARAGRAPH_TAGS.has(name)) return createElement(Fragment, null, render(children));",
                                          "if (!ALLOWED_TAGS.has(name) && !PARAGRAPH_TAGS.has(name)) return createElement(Fragment);"),
}
