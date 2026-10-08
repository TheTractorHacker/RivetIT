<?php

namespace ITFlow\KB;

/**
 * Compatibility alias: this class now lives in RivetCore (RivetCore\KB\PdfConverter). The alias keeps every
 * existing reference and static call working unchanged, including Training's.
 *
 * PHP does not autoload a class named in a catch clause, so the matching exception alias is loaded here, with
 * the converter: any code that can call convert() can also `catch (\ITFlow\KB\PdfConversionException $e)`.
 *
 * @deprecated since 26.10.26 use \RivetCore\KB\PdfConverter. Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
 */
class_alias(\RivetCore\KB\PdfConverter::class, __NAMESPACE__ . '\PdfConverter');
class_exists(__NAMESPACE__ . '\PdfConversionException');
