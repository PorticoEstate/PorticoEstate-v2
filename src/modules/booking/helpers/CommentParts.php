<?php

namespace App\modules\booking\helpers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * One event history comment (bb_event_comment.comment) as a short list of parts that event_edit.xsl can print
 * without trusting it: text, a line break, or an http(s) link.
 *
 *   ['k' => 't',  'v' => 'text']
 *   ['k' => 'br']
 *   ['k' => 'a',  'href' => 'https://…', 'v' => 'link text']
 *
 * Comments are stored as HTML and some of the text in them comes from people outside the building (the phone
 * and e-mail of an access request, an applicant's input). The page used to print the stored string with
 * disable-output-escaping, so whatever HTML it held ran in every officer's browser. A purifier does not help
 * there: its output is still a string, phpgwapi_xmlhelper::toXML() entity-decodes every string once more on its
 * way to the stylesheet, and an encoded payload comes out of that as live markup again. A list of parts does not
 * depend on how many times anything decodes: the stylesheet prints every v escaped and every href as an
 * attribute, and nothing else of the stored string reaches it.
 *
 * The stored value is read the way the old page read it: html_entity_decode once (what toXML did), then as HTML.
 * Text is kept; elements that run or load something (script, style, iframe, svg ...) are dropped with everything
 * inside them; an <a> is kept only when its href, with the control characters a browser would drop removed,
 * is a plain http or https URL; <p> ends in a line break; every other element is unwrapped and its text kept.
 */
class CommentParts
{
	/** Dropped with everything inside them: nothing in them is text a person wrote to be read. */
	private const DROPPED = [
		'script', 'style', 'template', 'noscript', 'iframe', 'noframes', 'noembed', 'object', 'embed', 'applet',
		'svg', 'math', 'title', 'head'
	];

	/**
	 * @param string $stored the comment as Db::f(..., true) returns it, after any host rewrite
	 * @return list<array{k:string,v?:string,href?:string}>
	 */
	public static function parse( string $stored ): array
	{
		$text = html_entity_decode($stored, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
		// valid UTF-8, and none of the characters XML 1.0 cannot hold: toXML would otherwise give the stylesheet a
		// document that does not parse, and the whole page fails
		$text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]|\xEF\xBF[\xBE\xBF]/', '', mb_scrub($text, 'UTF-8'));
		if ($text === '')
		{
			return array();
		}

		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $text . '</body>', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$body = $loaded ? $doc->getElementsByTagName('body')->item(0) : null;
		if (!$body)
		{
			// not parseable at all: show the text as it is, escaped by the stylesheet
			return array(array('k' => 't', 'v' => $text));
		}

		$parts = array();
		self::collect($body, $parts);
		return $parts;
	}

	private static function collect( DOMNode $node, array &$parts ): void
	{
		foreach ($node->childNodes as $child)
		{
			if ($child instanceof DOMText)
			{
				self::addText($parts, (string)$child->nodeValue);
				continue;
			}
			if (!($child instanceof DOMElement))
			{
				// comments, processing instructions
				continue;
			}

			$name = strtolower($child->nodeName);
			if (in_array($name, self::DROPPED, true))
			{
				continue;
			}
			if ($name === 'br')
			{
				$parts[] = array('k' => 'br');
				continue;
			}
			if ($name === 'a')
			{
				$href = self::httpHref($child->getAttribute('href'));
				if ($href !== null)
				{
					$parts[] = array('k' => 'a', 'href' => $href, 'v' => self::plainText($child));
					continue;
				}
				// no usable link: the words stay
				self::collect($child, $parts);
				continue;
			}

			$before = count($parts);
			self::collect($child, $parts);
			if ($name === 'p' && count($parts) > $before)
			{
				$parts[] = array('k' => 'br');
			}
		}
	}

	private static function addText( array &$parts, string $value ): void
	{
		if ($value === '')
		{
			return;
		}
		$last = count($parts) - 1;
		if ($last >= 0 && $parts[$last]['k'] === 't')
		{
			$parts[$last]['v'] .= $value;
			return;
		}
		$parts[] = array('k' => 't', 'v' => $value);
	}

	/** The text under an element, without what the page never showed (script, style, ...). */
	private static function plainText( DOMNode $node ): string
	{
		$text = '';
		foreach ($node->childNodes as $child)
		{
			if ($child instanceof DOMText)
			{
				$text .= $child->nodeValue;
			}
			elseif ($child instanceof DOMElement && !in_array(strtolower($child->nodeName), self::DROPPED, true))
			{
				$text .= self::plainText($child);
			}
		}
		return $text;
	}

	/**
	 * The href as a browser would read it (control characters dropped, surrounding blanks trimmed) when it is a
	 * plain http(s) URL, else null. No quote, angle bracket or blank is allowed inside it.
	 */
	private static function httpHref( string $raw ): ?string
	{
		$href = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', '', $raw));
		return preg_match('#^https?://[^\s"<>]+$#i', $href) === 1 ? $href : null;
	}
}
