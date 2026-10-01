<?php
require_once "includes/inc_all_admin.php";


$sql = mysqli_query($mysqli,"SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");

$row = mysqli_fetch_assoc($sql);
$company_locale = nullable_htmlentities($row['company_locale']);
$company_currency = nullable_htmlentities($row['company_currency']);
$config_phone_default_country_code = nullable_htmlentities($row['config_phone_default_country_code']);
$config_whatsapp_enabled = intval($row['config_whatsapp_enabled']);

// Get a list of all available timezones
$timezones = DateTimeZone::listIdentifiers();

// Every calling code functions.php's formatPhoneNumber() knows how to format -
// kept in that same order so "supports live formatting" stays in sync with it.
$phone_country_codes_array = [
    '1' => 'USA / Canada',
    '44' => 'United Kingdom',
    '61' => 'Australia',
    '91' => 'India',
    '81' => 'Japan',
    '49' => 'Germany',
    '33' => 'France',
    '34' => 'Spain',
    '39' => 'Italy',
    '55' => 'Brazil',
    '7' => 'Russia',
    '86' => 'China',
    '82' => 'South Korea',
    '62' => 'Indonesia',
    '63' => 'Philippines',
    '234' => 'Nigeria',
    '27' => 'South Africa',
    '971' => 'United Arab Emirates',
];

?>

    <div class="card">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-globe me-2"></i>Localization</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label>Language <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-language"></i></span>
                        </div>
                        <select class="form-control select2" name="locale" required>
                            <option value="">Select a Locale</option>
                            <?php foreach($locales_array as $locale_code => $locale_name) { ?>
                                <option <?php if ($company_locale == $locale_code) { echo "selected"; } ?> value="<?php echo $locale_code; ?>"><?php echo $locale_name; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Currency <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-money-bill"></i></span>
                        </div>
                        <select class="form-control select2" name="currency_code" required>
                            <option value="">Currency</option>
                            <?php foreach($currencies_array as $currency_code => $currency_name) { ?>
                                <option <?php if ($company_currency == $currency_code) { echo "selected"; } ?> value="<?php echo $currency_code; ?>"><?php echo "$currency_code - $currency_name"; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Timezone <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-business-time"></i></span>
                        </div>
                        <select class="form-control select2" name="timezone" required>
                            <option value="">Select a Timezone</option>
                            <?php foreach ($timezones as $tz) { ?>
                                <option <?php if ($config_timezone == $tz) { echo "selected"; } ?> value="<?php echo $tz; ?>"><?php echo $tz; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <hr>

                <button type="submit" name="edit_localization" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-phone me-2"></i>Phone Numbers</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label>Default Country Code</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-globe-americas"></i></span>
                        </div>
                        <select class="form-control select2" name="phone_default_country_code">
                            <?php foreach ($phone_country_codes_array as $phone_cc_code => $phone_cc_name) { ?>
                                <option <?php if ($config_phone_default_country_code == $phone_cc_code) { echo "selected"; } ?> value="<?php echo $phone_cc_code; ?>">+<?php echo "$phone_cc_code - $phone_cc_name"; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <small class="text-secondary">Used to prefill new phone/mobile number fields, and to pick how a number is grouped while typing (e.g. USA/Canada formats as (479) 555-5656) whenever a record's own country code box is still empty.</small>
                </div>

                <div class="form-group">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="whatsapp_enabled" name="whatsapp_enabled" value="1" <?php if ($config_whatsapp_enabled) { echo "checked"; } ?>>
                        <label class="form-check-label" for="whatsapp_enabled">Enable WhatsApp click-to-chat links</label>
                    </div>
                    <small class="text-secondary">Shows a WhatsApp icon next to mobile numbers (contacts, etc.) that opens a chat with that number.</small>
                </div>

                <hr>

                <button type="submit" name="edit_phone_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

            </form>
        </div>
    </div>

<?php
require_once "../includes/footer.php";
