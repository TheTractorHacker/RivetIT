<?php
require_once "includes/inc_all_user.php";

// Company contact details for the signature template - logo/name are already in
// session (see includes/load_company_settings.php), phone/website are not.
$company_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_phone, company_phone_country_code, company_website FROM companies WHERE company_id = 1"));
$company_phone_formatted = $company_row ? formatPhoneNumber($company_row['company_phone'], $company_row['company_phone_country_code']) : '';
$company_website = $company_row ? $company_row['company_website'] : '';

$session_title = getFieldById('users', $session_user_id, 'user_title', 'html');
$session_phone = getFieldById('users', $session_user_id, 'user_phone', 'html');

$base_url = 'https://' . rtrim((string) $config_base_url, '/');

$company_logo_url = '';
if (!empty($session_company_logo) && file_exists(__DIR__ . "/../../uploads/settings/$session_company_logo")) {
    $company_logo_url = $base_url . '/uploads/settings/' . rawurlencode($session_company_logo);
}

// Prefer the tech's own photo for the signature template over the company logo, if uploaded.
$avatar_url = '';
if (!empty($session_avatar) && file_exists(__DIR__ . "/../../uploads/users/$session_user_id/$session_avatar")) {
    $avatar_url = $base_url . '/uploads/users/' . $session_user_id . '/' . rawurlencode($session_avatar);
}
?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-user me-2"></i>User Details</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="row">
                <!-- Avatar column -->
                <div class="col-auto text-center me-4">
                    <?php if ($session_avatar) { ?>
                        <img src="../../uploads/users/<?= $session_user_id ?>/<?= nullable_htmlentities($session_avatar) ?>"
                             style="width:80px;height:80px;object-fit:cover;border-radius:50%;border:3px solid #dee2e6;">
                        <div class="mt-2">
                            <a href="post.php?clear_your_user_avatar&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                               class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-times me-1"></i>Remove
                            </a>
                        </div>
                    <?php } else { ?>
                        <div style="width:80px;height:80px;border-radius:50%;background:#4a4a4a;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-user fa-2x text-secondary"></i>
                        </div>
                    <?php } ?>
                    <div class="mt-3">
                        <label class="btn btn-outline-secondary btn-sm mb-0" style="cursor:pointer;">
                            <i class="fas fa-upload me-1"></i>Upload
                            <input type="file" accept="image/*" name="avatar" class="d-none js-preview-avatar">
                        </label>
                    </div>
                </div>

                <!-- Fields column -->
                <div class="col">
                    <div class="form-group">
                        <label>Name <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                            </div>
                            <input type="text" class="form-control" name="name"
                                   placeholder="Full Name"
                                   value="<?= stripslashes(nullable_htmlentities($session_name)) ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                            </div>
                            <input type="email" class="form-control" name="email"
                                   placeholder="Email Address"
                                   value="<?= nullable_htmlentities($session_email) ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Role</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-user-shield"></i></span>
                            </div>
                            <input type="text" class="form-control"
                                   value="<?= nullable_htmlentities($session_user_role_display) ?>" disabled>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Job Title</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-id-badge"></i></span>
                            </div>
                            <input type="text" class="form-control" id="user_title_input" name="title"
                                   placeholder="e.g. Senior Technician" value="<?= $session_title ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Direct Phone</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-phone"></i></span>
                            </div>
                            <input type="text" class="form-control" id="user_phone_input" name="phone"
                                   placeholder="e.g. (555) 123-4567" value="<?= $session_phone ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="d-flex align-items-center justify-content-between">
                            <span>Email Signature</span>
                            <span>
                                <div class="btn-group" role="group" aria-label="Resize whole signature">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="signature_shrink_btn" title="Shrink everything">
                                        <i class="fas fa-compress-alt me-1"></i>Shrink
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="signature_grow_btn" title="Grow everything">
                                        <i class="fas fa-expand-alt me-1"></i>Grow
                                    </button>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="signature_template_btn">
                                    <i class="fas fa-magic me-1"></i>Use Template
                                </button>
                            </span>
                        </label>
                        <textarea class="form-control tinymceSignature" id="signature_editor" name="signature" rows="4"
                                  placeholder="Signature appended to ticket replies and emails"><?= getFieldById('user_settings', $session_user_id, 'user_config_signature', 'html') ?></textarea>
                        <small class="form-text text-muted">"Use Template" builds a signature from your photo (above), Name/Job Title/Phone/Email, and the company info in Settings &gt; Company. Too big or too small? Click <strong>Shrink</strong>/<strong>Grow</strong> as many times as you like &mdash; no need to select anything, it scales the whole signature at once instead of resizing piece by piece. For stray empty rows/columns from a paste, click inside the table and use the table tools in the toolbar (or right-click).</small>
                    </div>

                    <button type="submit" name="edit_your_user_details" class="btn btn-primary">
                        <i class="fas fa-check me-2"></i>Save Changes
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const existing = document.querySelector('img[style*="border-radius:50%"]');
        const placeholder = document.querySelector('div[style*="border-radius:50%"]');
        if (existing) existing.src = e.target.result;
        else if (placeholder) {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.style = 'width:80px;height:80px;object-fit:cover;border-radius:50%;border:3px solid #dee2e6;';
            placeholder.replaceWith(img);
        }
    };
    reader.readAsDataURL(input.files[0]);
}
document.addEventListener('change', function (e) {
    if (e.target.closest('.js-preview-avatar')) { previewAvatar(e.target); }
});

// "Use Template" builds a simple, email-safe (inline-styled, table-based) signature
// from the Name/Job Title/Phone fields above and the company info from Settings > Company,
// then replaces whatever is currently in the signature editor.
(function () {
    var companyData = {
        name: <?= json_encode((string) $session_company_name) ?>,
        logoUrl: <?= json_encode($company_logo_url) ?>,
        phone: <?= json_encode((string) $company_phone_formatted) ?>,
        website: <?= json_encode((string) $company_website) ?>
    };
    var avatarUrl = <?= json_encode($avatar_url) ?>;

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function buildSignatureHtml() {
        var name = document.querySelector('input[name="name"]').value;
        var email = document.querySelector('input[name="email"]').value;
        var title = document.getElementById('user_title_input').value;
        var phone = document.getElementById('user_phone_input').value;

        // Prefer the tech's own photo (uploaded above) over the company logo - a headshot
        // reads better as a round photo, a logo reads better as a plain rectangle.
        var photoCell = '';
        if (avatarUrl) {
            photoCell = '<td style="vertical-align:top;padding-right:14px;"><img src="' + escapeHtml(avatarUrl) + '" alt="' + escapeHtml(name) + '" style="display:block;width:64px;height:64px;object-fit:cover;border-radius:50%;"></td>';
        } else if (companyData.logoUrl) {
            photoCell = '<td style="vertical-align:top;padding-right:14px;"><img src="' + escapeHtml(companyData.logoUrl) + '" alt="' + escapeHtml(companyData.name) + '" style="display:block;max-width:70px;max-height:70px;"></td>';
        }

        var lines = [];
        lines.push('<div style="font-weight:bold;font-size:14px;color:#111111;">' + escapeHtml(name) + '</div>');
        if (title) {
            lines.push('<div style="color:#666666;margin-top:2px;">' + escapeHtml(title) + '</div>');
        }
        if (companyData.name) {
            lines.push('<div style="margin-top:8px;font-weight:bold;color:#333333;">' + escapeHtml(companyData.name) + '</div>');
        }
        if (phone) {
            lines.push('<div style="margin-top:2px;color:#333333;">Direct: ' + escapeHtml(phone) + '</div>');
        }
        if (companyData.phone) {
            lines.push('<div style="margin-top:2px;color:#333333;">Office: ' + escapeHtml(companyData.phone) + '</div>');
        }
        if (email) {
            lines.push('<div style="margin-top:2px;"><a href="mailto:' + escapeHtml(email) + '" style="color:#2c6e63;text-decoration:none;">' + escapeHtml(email) + '</a></div>');
        }
        if (companyData.website) {
            var href = /^https?:\/\//i.test(companyData.website) ? companyData.website : 'https://' + companyData.website;
            lines.push('<div style="margin-top:2px;"><a href="' + escapeHtml(href) + '" style="color:#2c6e63;text-decoration:none;">' + escapeHtml(companyData.website) + '</a></div>');
        }

        return '<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#333333;">'
            + '<tr>' + photoCell
            + '<td style="vertical-align:top;border-left:3px solid #2c6e63;padding-left:14px;">' + lines.join('') + '</td>'
            + '</tr></table>';
    }

    var btn = document.getElementById('signature_template_btn');
    if (btn) {
        btn.addEventListener('click', function () {
            var editor = window.tinymce && window.tinymce.get('signature_editor');
            var html = buildSignatureHtml();
            if (editor) {
                editor.setContent(html);
            } else {
                document.getElementById('signature_editor').value = html;
            }
        });
    }

    // Shrink/Grow: scales EVERY sized thing in the whole signature at once (font sizes on
    // any element, plus image width/height/max-width/max-height) rather than making you
    // select and resize piece by piece. Works on pasted content and template output alike -
    // it just scales whatever inline sizes it finds, and gives the body itself a baseline
    // size first if nothing has one yet so the very first click has something to act on.
    function scaleSignature(factor) {
        var editor = window.tinymce && window.tinymce.get('signature_editor');
        if (!editor) return;
        var body = editor.getBody();

        if (!body.style.fontSize) {
            body.style.fontSize = '13px';
        }

        var els = [body].concat(Array.prototype.slice.call(body.querySelectorAll('*')));

        // Force a small explicit margin onto paragraphs/divs that have NONE set at all (relying
        // on the browser's default spacing, invisible to inline-style scanning below) - without
        // this, "height" made of nothing but default <p> margins would never budge no matter
        // how many times Shrink is clicked.
        els.forEach(function (el) {
            var tag = el.tagName;
            if (tag !== 'P' && tag !== 'DIV') return;
            if (!el.style.marginTop && !el.style.marginBottom && !el.style.margin) {
                el.style.marginTop = '2px';
                el.style.marginBottom = '2px';
            }
        });

        // Fonts/images AND vertical rhythm (margins/padding/line-height) all scale together -
        // shrinking text but leaving the gaps between lines untouched was the previous bug.
        var scaleProps = [
            'fontSize', 'width', 'height', 'maxWidth', 'maxHeight', 'lineHeight',
            'marginTop', 'marginBottom', 'marginLeft', 'marginRight',
            'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight'
        ];

        els.forEach(function (el) {
            scaleProps.forEach(function (prop) {
                var current = el.style[prop];
                if (!current) return;
                var match = /^([\d.]+)(px|pt|em|rem|in)$/.exec(current.trim());
                if (!match) return;
                var unit = match[2];
                var floor;
                if (prop === 'fontSize' || prop === 'lineHeight') {
                    floor = (unit === 'px') ? 8 : (unit === 'in' ? 0.1 : 6);
                } else if (prop.indexOf('margin') === 0 || prop.indexOf('padding') === 0) {
                    floor = 0;
                } else {
                    floor = 10; // image width/height/maxWidth/maxHeight
                }
                var value = Math.max(floor, parseFloat(match[1]) * factor);
                el.style[prop] = (Math.round(value * 100) / 100) + unit;
            });
        });

        editor.setDirty(true);
    }

    var shrinkBtn = document.getElementById('signature_shrink_btn');
    var growBtn = document.getElementById('signature_grow_btn');
    if (shrinkBtn) shrinkBtn.addEventListener('click', function () { scaleSignature(0.9); });
    if (growBtn) growBtn.addEventListener('click', function () { scaleSignature(1.1); });
})();
</script>

<?php require_once "../../includes/footer.php"; ?>
