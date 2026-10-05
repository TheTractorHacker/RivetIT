<?php

namespace ITFlow\KB;

/**
 * Compatibility alias: this class now lives in RivetCore (RivetCore\KB\PdfConversionException). The alias keeps every existing
 * reference, `catch` clause and static call working unchanged, including Training's.
 */
class_alias(\RivetCore\KB\PdfConversionException::class, __NAMESPACE__ . '\PdfConversionException');
