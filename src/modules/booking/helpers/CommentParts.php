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
 *
 * What the helper returns is entity-decoded once more by toXML() and then loaded by the page as XML, which fails,
 * and shows «Systemfeil» instead of the event, on U+FFFE and U+FFFF. Those two characters, and the references to
 * them, are therefore replaced (by U+FFFD) in every v and href here, after the HTML step: only then do they have
 * their final shape. (The strip before the HTML step is not enough: the HTML step can make the characters itself,
 * as a <meta charset="utf-7"> makes it read «+//4-» as U+FFFE.)
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
		// document that does not parse, and the whole page fails (the HTML step below can make U+FFFE and U+FFFF
		// again: replaceNonCharacterReferences() deals with them on the finished parts)
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
			return self::withoutNonCharacterReferences(array(array('k' => 't', 'v' => $text)));
		}

		$parts = array();
		self::collect($body, $parts);
		return self::withoutNonCharacterReferences($parts);
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
	 * The href as a browser would read it (control characters dropped, surrounding blanks trimmed, U+FFFE and
	 * U+FFFF and the references to them replaced) when it is a plain http(s) URL, else null. No quote, angle bracket
	 * or blank is allowed inside it. What is checked is what is returned.
	 */
	private static function httpHref( string $raw ): ?string
	{
		$href = trim(self::replaceNonCharacterReferences((string)preg_replace('/[\x00-\x1F\x7F]+/', '', $raw)));
		return preg_match('#^https?://[^\s"<>]+$#i', $href) === 1 ? $href : null;
	}

	/**
	 * Replaces U+FFFE and U+FFFF, and the references to them, in every v. The page cannot load a document that
	 * holds either (class.xslttemplates.inc.php:436-438), toXML() turns a reference into the character, and the
	 * references that reach this point are the ones the HTML step left as text, so this is the last place to see
	 * them. It is done on the finished parts, where adjacent text has been joined: «&#x», a comment and «FFFE;» are
	 * one reference only now. (An href is cleaned where it is read, before it is checked.)
	 *
	 * @param list<array{k:string,v?:string,href?:string}> $parts
	 * @return list<array{k:string,v?:string,href?:string}>
	 */
	private static function withoutNonCharacterReferences( array $parts ): array
	{
		foreach ($parts as &$part)
		{
			if (isset($part['v']))
			{
				$part['v'] = self::replaceNonCharacterReferences($part['v']);
			}
		}
		return $parts;
	}

	/**
	 * First the characters U+FFFE and U+FFFF themselves, each replaced by U+FFFD, the replacement character. The strip
	 * in parse() ran before the HTML step, and that step can make them: a <meta charset="utf-7"> makes loadHTML read
	 * the rest of the comment as UTF-7, where «+//4-» is U+FFFE and «+//8-» is U+FFFF, and libxml2 2.9 keeps the
	 * character inside an attribute value, which is how an href comes to hold one. It drops it from text, so no v is
	 * known to hold one; newer libxml2 versions were not tried, and v and href take the same step so that neither
	 * depends on that. It comes first, so that the references are judged on the string that is returned, whatever
	 * this step did to the pieces around a character; replaced, a character cannot be part of a reference or join
	 * one, so the result is the same in either order.
	 *
	 * Then every reference that html_entity_decode turns into U+FFFE or U+FFFF, each replaced by U+FFFD too. A
	 * reference is judged by that function, called as toXML() calls it (class.xmlhelper.inc.php:94, no flags:
	 * ENT_HTML5 would refuse these two characters and so hide them), and not by a pattern for how it is
	 * spelled: PHP reads a hexadecimal reference with strtol, which also takes a «0x» after the «x» («&#x0xFFFE;»,
	 * «&#X0Xffff;», «&#x0x0000FFFE;»), and a pattern for the spelling is outrun by the next quirk like it.
	 *
	 * The candidates are the stretches «&#» … «;» that hold no «&» and no «;»: that is all a reference the decoder
	 * reads can be, and no candidate can start inside another one. («&#x&#xFFFE;» holds one, the second «&#».) A
	 * stretch is one pattern match with no backtracking, so a comment of any size is read in a single linear pass.
	 *
	 * Each stretch is judged by html_entity_decode, exactly as toXML() calls it, so every spelling the decoder accepts
	 * is caught. Do not replace that with a pattern for the spelling (it misses the strtol forms, «&#x0xFFFE;»), and do
	 * not add a pattern prefilter in front of it: one with a backtracking tail, /&#[^&;]*(?:fff[ef]|6553[45]);/i, hits
	 * PREG_BACKTRACK_LIMIT on «&#» followed by a run of «a» (1 MB is enough), preg returns NULL, and the (string) cast
	 * turns the whole comment into an empty string (fact 36455).
	 *
	 * Not removed: that can join the pieces around a reference into a new one («&#x&#xFFFE;FFFE;»), one pass would
	 * then not be enough, and a loop that goes on until none is left is quadratic on a comment built for it (40,000
	 * levels, 640 KB: 6 s). A character cannot be joined into a reference, so one pass is enough.
	 *
	 * What can still be a reference after this is text for the one decode that follows: toXML() decodes once and
	 * escapes the result, so «&amp;#xFFFE;», which two decodes would turn into the character, reaches the page as
	 * the eight characters «&#xFFFE;» it says.
	 */
	private static function replaceNonCharacterReferences( string $value ): string
	{
		$value = str_replace(array("\u{FFFE}", "\u{FFFF}"), "\u{FFFD}", $value);
		return (string)preg_replace_callback('/&#[^&;]*;/', static function ( array $reference ): string
		{
			return in_array(html_entity_decode($reference[0]), array("\u{FFFE}", "\u{FFFF}"), true) ? "\u{FFFD}" : $reference[0];
		}, $value);
	}
}
