<?php
require_once "includes/inc_all_admin.php";


$sql = mysqli_query($mysqli,"SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");

$row = mysqli_fetch_assoc($sql);
$company_id = intval($row['company_id']);
$company_name = nullable_htmlentities($row['company_name']);
$company_country = nullable_htmlentities($row['company_country']);
$company_address = nullable_htmlentities($row['company_address']);
$company_city = nullable_htmlentities($row['company_city']);
$company_state = nullable_htmlentities($row['company_state']);
$company_zip = nullable_htmlentities($row['company_zip']);
$company_phone_country_code = formatPhoneNumber($row['company_phone_country_code']);
$company_phone = nullable_htmlentities(formatPhoneNumber($row['company_phone'], $company_phone_country_code));
$company_email = nullable_htmlentities($row['company_email']);
$company_website = nullable_htmlentities($row['company_website']);
$company_logo = nullable_htmlentities($row['company_logo']);
$company_locale = nullable_htmlentities($row['company_locale']);
$company_currency = nullable_htmlentities($row['company_currency']);
$company_tax_id = nullable_htmlentities($row['company_tax_id']);
$company_ms_tenant_id = nullable_htmlentities($row['company_ms_tenant_id']);
$company_default_email_domain = nullable_htmlentities($row['company_default_email_domain']);
$company_security_contact_email = nullable_htmlentities($row['company_security_contact_email']);
$company_hr_contact_email = nullable_htmlentities($row['company_hr_contact_email']);

$company_initials = nullable_htmlentities(initials($company_name));

?>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-fw fa-briefcase me-2"></i>Company Details</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                    <div class="row">
                        <div class="col-md-3 text-center company-logo-settings">
                            <?php if ($company_logo) { ?>
                                <img class="img-thumbnail company-logo-preview" src="<?php echo "../uploads/settings/$company_logo"; ?>" alt="Current company logo">
                                <a href="post.php?remove_company_logo&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-outline-danger btn-block mt-3 mb-3 confirm-link">Remove Logo</a>
                            <?php } ?>
                            <div class="form-group">
                                <label for="company_logo_file">Upload company logo</label>
                                <input type="file" class="form-control" id="company_logo_file" name="file" accept=".jpg, .jpeg, .png,image/jpeg,image/png">
                                <small class="form-text text-muted">JPG or PNG. A wide, transparent logo works best. Choose a file, then save below.</small>
                            </div>
                        </div>

                        <div class="col-md-9">
                            <div class="form-group">
                                <label>Name <strong class="text-danger">*</strong></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-building"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="name" placeholder="Company Name" value="<?php echo $company_name; ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Address</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-map-marker-alt"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="address" placeholder="Street Address" value="<?php echo $company_address; ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>City</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-city"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="city" placeholder="City" value="<?php echo $company_city; ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>State / Province</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-flag"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="state" placeholder="State or Province" value="<?php echo $company_state; ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Postal Code</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fab fa-fw fa-usps"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="zip" placeholder="Zip or Postal Code" value="<?php echo $company_zip; ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Country</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-globe-americas"></i></span>
                                    </div>
                                    <select class="form-control select2" name="country">
                                        <option value="">Country</option>
                                        <?php foreach($countries_array as $country_name) { ?>
                                            <option <?php if ($company_country == $country_name) { echo "selected"; } ?>><?php echo $country_name; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>

                            <label>Phone</label>
                            <div class="form-row">
                                <div class="col-md-9">
                                    <div class="form-group">
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-phone"></i></span>
                                            </div>
                                            <input type="tel" class="form-control phone-country-code" name="phone_country_code" value="<?php echo $company_phone_country_code; ?>" placeholder="+" maxlength="4">
                                            <input type="tel" class="form-control phone-number-format" name="phone" value="<?php echo $company_phone; ?>" placeholder="Phone Number" maxlength="200">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                                    </div>
                                    <input type="email" class="form-control" name="email" placeholder="Email address" value="<?php echo $company_email; ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Website</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-globe"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="website" placeholder="Website address" value="<?php echo $company_website; ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Tax ID</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-balance-scale"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="tax_id" value="<?php echo $company_tax_id; ?>" placeholder="Tax ID" maxlength="200">
                                </div>
                            </div>

                            <hr>
                            <h5>IT &amp; Directory</h5>
                            <small class="text-muted d-block mb-3">Used by Microsoft/Entra sync and employee-lifecycle notifications once those are configured.</small>

                            <div class="form-group">
                                <label>Microsoft/Entra Tenant ID</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-cloud"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="ms_tenant_id" value="<?php echo $company_ms_tenant_id; ?>" placeholder="e.g. 72f988bf-86f1-41af-91ab-2d7cd011db47" maxlength="100">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Default Email Domain</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-at"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="default_email_domain" value="<?php echo $company_default_email_domain; ?>" placeholder="e.g. mwautomation.com" maxlength="200">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Security Contact Email</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-shield-alt"></i></span>
                                    </div>
                                    <input type="email" class="form-control" name="security_contact_email" value="<?php echo $company_security_contact_email; ?>" placeholder="e.g. security@yourcompany.com" maxlength="200">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>HR Contact Email</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-user-tie"></i></span>
                                    </div>
                                    <input type="email" class="form-control" name="hr_contact_email" value="<?php echo $company_hr_contact_email; ?>" placeholder="e.g. hr@yourcompany.com" maxlength="200">
                                </div>
                            </div>

                            <hr>

                            <button type="submit" name="edit_company" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save company details</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php
require_once "../includes/footer.php";
