<?php

namespace ITFlow\Training\Kiosk\Core;

/** The kiosk cannot run on this install ($config_settings_enc_key is empty): 503 "Training kiosk is not configured". */
final class KioskConfigException extends \RuntimeException
{
}
