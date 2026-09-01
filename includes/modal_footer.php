<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">window.csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;</script>
<script src="/js/app.js"></script>
<script src="/plugins/Show-Hide-Passwords-Bootstrap-4/bootstrap-show-password.min.js"></script>

<?php
    $content = ob_get_clean();

    // Return the title and content as a JSON response
    echo json_encode(['content' => $content]);
    exit();
?>