<?php

namespace ITFlow\KB;

/**
 * Compatibility alias: this class now lives in RivetCore (RivetCore\KB\DocxConversionException). The alias keeps every existing
 * reference, `catch` clause and static call working unchanged, including Training's.
 *
 * @deprecated since 26.10.26 use \RivetCore\KB\DocxConversionException. Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
 */
class_alias(\RivetCore\KB\DocxConversionException::class, __NAMESPACE__ . '\DocxConversionException');
