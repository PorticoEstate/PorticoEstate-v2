import {fallbackLng} from "@/app/i18n/settings";
import {i18n} from "i18next";
import TurndownService from 'turndown';

/**
 * A dictionary of named HTML entities and their corresponding character representations.
 */
const htmlEntities: { [key: string]: string } = {
    nbsp: ' ',
    cent: '¢',
    pound: '£',
    yen: '¥',
    euro: '€',
    copy: '©',
    reg: '®',
    lt: '<',
    gt: '>',
    quot: '"',
    amp: '&',
    apos: '\''
};

/**
 * Converts HTML entities in a string to their corresponding characters.
 * Handles both named entities (e.g., &amp;) and numeric entities (e.g., &#x26; or &#38;).
 *
 * @param str - The input string containing HTML entities.
 * @returns The unescaped string with HTML entities replaced by their respective characters.
 */
export function unescapeHTML(str: string): string {
    return str.replace(/&([^;]+);/g, (entity: string, entityCode: string): string => {
        let match: RegExpMatchArray | null;

        // Check if the entity code matches a named entity
        if (entityCode in htmlEntities) {
            return htmlEntities[entityCode];
        }
        // Check for hexadecimal numeric entities (e.g., &#x26;)
        else if ((match = entityCode.match(/^#x([\da-fA-F]+)$/))) {
            return String.fromCharCode(parseInt(match[1], 16));
        }
        // Check for decimal numeric entities (e.g., &#38;)
        else if ((match = entityCode.match(/^#(\d+)$/))) {
            return String.fromCharCode(parseInt(match[1], 10));
        }
        // If no match, return the entity as-is
        else {
            return entity;
        }
    });
}


export function extractDescriptionText(description_json: string, i18n: i18n): string | null {
    const descriptionJson = JSON.parse(description_json);
    // @ts-ignore
    let description = descriptionJson[i18n.language];
    if (!description) {
        // @ts-ignore
        description = descriptionJson[fallbackLng.key];
    }
    if (!description) {
        return null;
    }
    return description;
}


interface ParsedMarkdownText {
    title?: string;
    markdown: string;
}

/**
 * Converts HTML to Markdown while stripping styling and preserving semantic structure.
 * Handles multiple br tags like normalizeText and treats empty content (like just <br>) as empty.
 */
export function parseHtmlToMarkdown(html: string): ParsedMarkdownText {
    if (!html) return { markdown: '' };

    // First unescape HTML entities
    const unescaped = unescapeHTML(html);
    const trimmed = unescaped.trim();

    // Check if content is essentially empty (just br tags, whitespace, etc.)
    const contentCheck = trimmed.replace(/<br\s*\/?>/gi, '').replace(/\s+/g, '');
    if (!contentCheck || contentCheck.length === 0) {
        return { markdown: '' };
    }

    // Clean up problematic HTML patterns before conversion
    let cleanedHtml = trimmed;
    
    // Fix bold tags that contain line breaks - move br outside
    cleanedHtml = cleanedHtml.replace(/<b>([^<]*)<br[^>]*>([^<]*)<\/b>/gi, '<b>$1</b><br><b>$2</b>');
    cleanedHtml = cleanedHtml.replace(/<strong>([^<]*)<br[^>]*>([^<]*)<\/strong>/gi, '<strong>$1</strong><br><strong>$2</strong>');
    
    // Remove br tags within bold tags (they should be outside)
    cleanedHtml = cleanedHtml.replace(/<b>([^<]*)<br[^>]*><\/b>/gi, '<b>$1</b>');
    cleanedHtml = cleanedHtml.replace(/<strong>([^<]*)<br[^>]*><\/strong>/gi, '<strong>$1</strong>');
    
    // Configure Turndown to strip styling and preserve semantic structure
    const turndownService = new TurndownService({
        headingStyle: 'atx',
        codeBlockStyle: 'fenced',
        bulletListMarker: '-'
    });

    // Custom rule for links to preserve target="_blank"
    turndownService.addRule('links', {
        filter: 'a',
        replacement: function (content, node) {
            const href = (node as HTMLAnchorElement).getAttribute('href');
            const target = (node as HTMLAnchorElement).getAttribute('target');
            if (!href) return content;
            
            // Mark external links (target="_blank") with a special syntax
            if (target === '_blank') {
                return `[${content}](${href}){:target="_blank"}`;
            }
            return `[${content}](${href})`;
        }
    });

    // Remove all styling attributes but preserve semantic tags
    turndownService.remove(['script', 'style']);

    // Convert HTML to Markdown
    let markdown = turndownService.turndown(cleanedHtml);

    // Handle multiple br tags like normalizeText - convert sequences of br to newlines
    markdown = markdown.replace(/\\\n/g, '\n'); // Turndown escapes line breaks
    markdown = markdown.replace(/\n{3,}/g, '\n\n'); // Consolidate multiple newlines
    
    // Note: Turndown handles bold conversion correctly, so we don't need to clean up ** markers

    // Extract title from first heading
    const titleMatch = markdown.match(/^#\s+(.+)$/m);
    const title = titleMatch ? titleMatch[1].trim() : undefined;

    // If we extracted a title, remove it from the markdown body
    if (titleMatch) {
        markdown = markdown.replace(titleMatch[0], '').trim();
    }

    // Clean up any leading/trailing whitespace
    markdown = markdown.trim();

    return { title, markdown };
}
