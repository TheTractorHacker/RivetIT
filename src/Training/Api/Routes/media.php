<?php

/*
 * Training JSON routes owned by the media lane (spec §6.2). Merged by Router::routes().
 *
 * Uploads themselves do not go through here: multipart bodies go to agent/training_upload.php,
 * and file bytes are served by agent/training_media.php (PHP authorization, then X-Accel-Redirect).
 */

use ITFlow\Training\Api\MediaActions;

return [
    'media_get'        => ['handler' => MediaActions::class . '::mediaGet',       'method' => 'GET',  'level' => 2],
    'pdf_render_next'  => ['handler' => MediaActions::class . '::pdfRenderNext',  'method' => 'POST', 'level' => 2],
    'video_link_check' => ['handler' => MediaActions::class . '::videoLinkCheck', 'method' => 'POST', 'level' => 2],
    'video_verify'     => ['handler' => MediaActions::class . '::videoVerify',    'method' => 'POST', 'level' => 2],
    'kb_search'        => ['handler' => MediaActions::class . '::kbSearch',       'method' => 'GET',  'level' => 2, 'kb' => true],
    'kb_import'        => ['handler' => MediaActions::class . '::kbImport',       'method' => 'POST', 'level' => 2, 'kb' => true],
];
