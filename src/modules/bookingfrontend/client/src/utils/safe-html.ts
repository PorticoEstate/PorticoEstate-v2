/**
 * Admin-authored rich text (the kitchen's contact_info, written in a WYSIWYG editor) as React, safely.
 *
 * html-react-parser turns HTML into React elements; it is NOT a sanitiser. React 18 does not block a
 * `javascript:` href and passes every attribute on (style, class, on* strings), and the editor leaves an empty
 * `<p><br></p>` after the text. So this file lets through only what contact text needs:
 *   - tags: p br strong b em i ul ol li a. script/style/iframe/object/embed/svg/math/template/noscript/head/title are
 *     dropped WITH their content; every other tag is dropped and its text kept. Block tags that carry a paragraph
 *     (div, h1-h6, blockquote) keep their text as a `p`, so two of them never run together;
 *   - attributes: none, except an `a` keeps its `href`, and only when it is http, https, mailto or tel, the scheme
 *     read as a browser's URL parser reads it (tab, line feed and carriage return dropped wherever they are, control
 *     characters and spaces trimmed off both ends) and compared case-insensitively, so « JaVa\tScRiPt:» is caught. The
 *     link is emitted AS READ, so a control character inside the scheme (`h&#x01;ttps:`), which a browser takes for a
 *     relative link, is no scheme: that link is refused.
 *     Such a link always opens in a new tab with rel="noopener noreferrer"; an `a` with any other href is just its text;
 *   - text is escaped by React, as ever; nothing goes through dangerouslySetInnerHTML.
 * And it takes out the empty blocks and the line breaks at the edges of a block, so that «text<p><br></p>» is
 * just «text», and a contact with nothing in it is `null`.
 *
 * The input is the HTML AFTER unescapeHTML, as the page has always parsed it. An entity-encoded attribute
 * (`java&#115;cript:`) reaches the scheme check already decoded, which is why that check is on the parsed value.
 */
import {createElement, Fragment, type ReactNode} from 'react';
import {domToReact, Element, htmlToDOM, Text, type DOMNode, type HTMLReactParserOptions} from 'html-react-parser';

const ALLOWED_TAGS = new Set(['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'a']);
/** Tags that hold a paragraph of text: kept as a `p`. */
const PARAGRAPH_TAGS = new Set(['div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote']);
/** Dropped WITH their content: their text is code or markup, not words. */
const DROPPED_TAGS = new Set(['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'head', 'title']);
/** Blocks that are removed when nothing is in them (the editor's «spacing» paragraph). */
const BLOCK_TAGS = new Set(['p', 'li', 'ul', 'ol', ...PARAGRAPH_TAGS]);
const SAFE_URL = /^(https?|mailto|tel):/i;
/** What a browser's URL parser drops wherever it is in a URL: tab, line feed, carriage return. */
const URL_DROPPED = /[\t\n\r]/g;
/** What it trims off both ends: the C0 controls and space. Nothing else is ignored, so a control character INSIDE a scheme leaves no scheme. */
// eslint-disable-next-line no-control-regex
const URL_TRIMMED = /^[\u0000-\u0020]+|[\u0000-\u0020]+$/g;
/** Whitespace, no-break space and zero-width characters: what an empty-looking paragraph holds. */
const BLANK_TEXT = /^[\s\u00A0\u200B-\u200D\uFEFF]*$/;

function isBlank(node: DOMNode): boolean {
    if (node instanceof Text) return BLANK_TEXT.test(node.data);
    if (!(node instanceof Element)) return true; // comment, directive: renders nothing
    const name = node.name.toLowerCase();
    if (name === 'br' || DROPPED_TAGS.has(name)) return true;
    return (node.children as DOMNode[]).every(isBlank);
}

/** The nodes without the blank ones at either end (a trailing <br>, an empty <p>, stray whitespace). */
function trimEdges(nodes: DOMNode[]): DOMNode[] {
    let start = 0;
    let end = nodes.length;
    while (start < end && isBlank(nodes[start])) start++;
    while (end > start && isBlank(nodes[end - 1])) end--;
    return nodes.slice(start, end);
}

/** The href as a browser reads it when its scheme is one of the four allowed, else null. Relative and scheme-less links are not allowed. */
export function safeHref(href: string | undefined): string | null {
    if (!href) return null;
    const url = href.replace(URL_DROPPED, '').replace(URL_TRIMMED, '');
    return SAFE_URL.test(url) ? url : null;
}

function render(nodes: DOMNode[]): ReactNode {
    return domToReact(trimEdges(nodes), options);
}

const options: HTMLReactParserOptions = {
    replace(node) {
        if (!(node instanceof Element)) return undefined; // text: the parser's own, escaped by React
        const name = node.name.toLowerCase();
        const children = node.children as DOMNode[];
        if (DROPPED_TAGS.has(name)) return createElement(Fragment);
        if (name === 'br') return createElement('br');
        if (!ALLOWED_TAGS.has(name) && !PARAGRAPH_TAGS.has(name)) return createElement(Fragment, null, render(children));
        // Empty blocks go wherever they stand: the editor's blank paragraph is spacing, not content.
        if (BLOCK_TAGS.has(name) && isBlank(node)) return createElement(Fragment);
        if (name === 'a') {
            const href = safeHref(node.attribs.href);
            return href
                ? createElement('a', {href, target: '_blank', rel: 'noopener noreferrer'}, render(children))
                : createElement(Fragment, null, render(children));
        }
        return createElement(PARAGRAPH_TAGS.has(name) ? 'p' : name, null, render(children));
    },
};

/** `html` as safe React, or null when there is nothing in it (empty, whitespace, only breaks and empty paragraphs). */
export function safeHtmlToReact(html: string): ReactNode | null {
    const nodes = trimEdges(htmlToDOM(html) as DOMNode[]);
    return nodes.length === 0 ? null : render(nodes);
}
