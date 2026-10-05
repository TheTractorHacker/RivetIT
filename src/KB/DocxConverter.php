<?php

namespace ITFlow\KB;

/**
 * Compatibility alias: this class now lives in RivetCore (RivetCore\KB\DocxConverter). The alias keeps every
 * existing reference and static call working unchanged, including Training's.
 *
 * PHP does not autoload a class named in a catch clause, so the matching exception alias is loaded here, with
 * the converter: any code that can call convert() can also `catch (\ITFlow\KB\DocxConversionException $e)`.
 */
class_alias(\RivetCore\KB\DocxConverter::class, __NAMESPACE__ . '\DocxConverter');
class_exists(__NAMESPACE__ . '\DocxConversionException');
