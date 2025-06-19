import {fallbackLng} from "@/app/i18n/settings";
import {i18n} from "i18next";

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

interface NormalizedText {
    title?: string;
    body: string;
}

/**
 * Normalizes HTML text by removing all styling, extracting title from h1 tags,
 * and cleaning up whitespace while preserving newlines from br tags.
 */
export function normalizeText(html: string): NormalizedText {
    if (!html) return { body: '' };
    
    // First unescape HTML entities
    const unescaped = unescapeHTML(html);
    
    // Remove preceding and ending newlines
    const trimmed = unescaped.trim();
    
    // Extract title from first h1 tag
    const h1Match = trimmed.match(/<h1[^>]*>(.*?)<\/h1>/i);
    const title = h1Match ? h1Match[1].replace(/<[^>]*>/g, '').trim() : undefined;
    
    // Convert br tags and block-level tags to newlines before removing other HTML tags
    let body = trimmed.replace(/<br\s*\/?>/gi, '\n');
    body = body.replace(/<\/p>\s*<p[^>]*>/gi, '\n\n'); // Between paragraphs
    body = body.replace(/<p[^>]*>/gi, '').replace(/<\/p>/gi, '\n'); // Start/end of paragraphs
    body = body.replace(/<\/(h[1-6]|div)>/gi, '\n'); // End of headings and divs
    
    // Remove all other HTML tags to get plain text
    body = body.replace(/<[^>]*>/g, '');
    
    // If we extracted a title, remove it from the body
    if (title && body.startsWith(title)) {
        body = body.substring(title.length).trim();
    }
    
    // Clean up extra whitespace but preserve newlines
    body = body.replace(/[ \t]+/g, ' ').replace(/\n[ \t]*/g, '\n').replace(/[ \t]*\n/g, '\n');
    
    // Consolidate multiple newlines into single newlines
    body = body.replace(/\n+/g, '\n');
    
    // Remove leading and trailing newlines
    body = body.replace(/^\n+|\n+$/g, '').trim();
    
    return { title, body };
}
