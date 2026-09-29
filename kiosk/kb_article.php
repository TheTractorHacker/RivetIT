<?php

/*
 * One Knowledge Base article for a learner: GET /kiosk/kb_article.php?id=<article>. Learner session only. The
 * article must pass the department rule (KbAccess::scopeSql), else 404 - a hidden or other-department article is
 * indistinguishable from a missing one. The body is HTMLPurifier output WITH the interactive-block vocabulary
 * (checklists, tabs, wizards, decision trees, copy buttons and the sandboxed HTML embed), rendered read-only by
 * js/kb_interactive.js (nothing is saved: the kiosk has no portal session), with every KB media URL repointed at
 * /kiosk/kb_media.php and embeds framed from /kiosk/kb_embed.php (the 'kb_article' CSP profile allows that frame).
 */

$KIOSK_CSP_PROFILE = 'kb_article';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Learn\KbAccess;

kiosk_require_session(['learner']);
if (intval($config_module_enable_kb ?? 0) !== 1) {
    kiosk_redirect('/kiosk/me.php');
}

$id = isset($_GET['id']) && is_string($_GET['id']) && preg_match('/^[1-9][0-9]{0,9}$/D', $_GET['id']) === 1 ? (int) $_GET['id'] : 0;
$db = $kctx->db();
$client = KbAccess::clientId($db, $kctx->contactId());
$article = ($id > 0 && $client !== null)
    ? Db::one($db, 'SELECT kb_article_id, kb_article_title, kb_article_content, kb_article_client_id, kb_article_updated_at, kb_article_created_at,
            (SELECT kb_category_name FROM kb_categories WHERE kb_category_id = kb_articles.kb_article_category_id) AS category
        FROM kb_articles WHERE kb_article_id = ? AND ' . KbAccess::scopeSql($client) . ' LIMIT 1', 'i', [$id])
    : null;
if ($article === null) {
    kiosk_plain(404, 'Not found');
}

require_once dirname(__DIR__) . '/plugins/htmlpurifier/HTMLPurifier.standalone.php';
$pc = HTMLPurifier_Config::createDefault();
$pc->set('Cache.DefinitionImpl', null);
$pc->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
$pc->set('Attr.DefaultImageAlt', '');
\ITFlow\KB\InteractiveBlocks::apply($pc);
$body = (new HTMLPurifier($pc))->purify((string) $article['kb_article_content']);
$body = \ITFlow\KB\MediaUrlRewriter::toKiosk($body);
$ikb_progress_json = \ITFlow\KB\InteractiveBlocks::progressAttribute([]);
$ikb_hashes_json = \ITFlow\KB\InteractiveBlocks::hashesAttribute($body);

$attachments = Db::all($db, 'SELECT kb_article_attachment_id, kb_article_attachment_name FROM kb_article_attachments
    WHERE kb_article_attachment_kb_article_id = ? ORDER BY kb_article_attachment_name', 'i', [$id]);

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static fn(string $key, array $vars = []): string => KioskStrings::t($kctx->lang, $key, $vars);
$updated = (string) ($article['kb_article_updated_at'] ?? $article['kb_article_created_at']);
$updated = $updated !== '' && strtotime($updated) !== false ? date('M j, Y', strtotime($updated)) : '';

$k_page = [
    'title' => (string) $article['kb_article_title'],
    'css' => ['/css/itflow_training_kiosk_learn.css', '/css/itflow_kb.css', '/css/itflow_training_kiosk_kb.css'],
    'js' => ['/js/kb_interactive.js'],
    'body_class' => 'kx-learn kx-kb',
    'data' => new \stdClass(),
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kl-page kb-page">
  <a class="kx-btn kb-back" href="/kiosk/kb.php"><i class="fas fa-arrow-left" aria-hidden="true"></i><span><?= $h($t('kb.back_list')) ?></span></a>
  <article class="kl-panel kb-article">
    <header class="kb-article__head">
<?php if (trim((string) ($article['category'] ?? '')) !== '') { ?>
      <span class="kl-chip kl-chip--info kb-article__cat"><i class="fas fa-folder" aria-hidden="true"></i><?= $h($article['category']) ?></span>
<?php } ?>
      <h1 class="kb-article__title"><?= $h($article['kb_article_title']) ?></h1>
      <?php if ($updated !== '') { ?><p class="kb-article__meta"><i class="far fa-clock" aria-hidden="true"></i><?= $h($t('kb.updated', ['date' => $updated])) ?></p><?php } ?>
    </header>
    <div class="trp-article kb-article__body"
         data-ikb-root
         data-ikb-version="<?= \ITFlow\KB\InteractiveBlocks::VERSION ?>"
         data-ikb-article="<?= (int) $article['kb_article_id'] ?>"
         data-ikb-readonly="1"
         data-ikb-endpoint=""
         data-ikb-csrf=""
         data-ikb-progress="<?= $h($ikb_progress_json) ?>"
         data-ikb-hashes="<?= $h($ikb_hashes_json) ?>"><?= $body ?></div>
<?php if ($attachments !== []) { ?>
    <footer class="kb-article__files">
      <h2 class="kb-article__files-title"><i class="fas fa-paperclip" aria-hidden="true"></i><?= $h($t('kb.attachments')) ?></h2>
      <ul class="kb-files">
<?php foreach ($attachments as $f) { ?>
        <li><a class="kb-file" href="/kiosk/kb_media.php?att=<?= (int) $f['kb_article_attachment_id'] ?>&amp;download=1"><i class="fas fa-file-download kb-file__icon" aria-hidden="true"></i><span class="kb-file__name"><?= $h($f['kb_article_attachment_name']) ?></span><i class="fas fa-download kb-file__go" aria-hidden="true"></i></a></li>
<?php } ?>
      </ul>
    </footer>
<?php } ?>
  </article>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php';
