<?php

namespace ITFlow\KB;

/**
 * Thrown by HtmlImporter when an uploaded page is malformed, oversized, or
 * hostile in a way that means there is nothing safe to import.
 *
 * Same contract as DocxConversionException and PdfConversionException: the
 * message is written to be shown to the person who uploaded the file, so a
 * caller can put it straight into flash_alert(). It never contains a filesystem
 * path, a libxml error dump, or any other server internal - and it never quotes
 * the uploaded document back, because the one thing we know about that document
 * is that an attacker may have written it. Detail for an administrator goes to
 * error_log() at the call site instead.
 */
class HtmlImportException extends \RuntimeException
{
}
