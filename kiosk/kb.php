<?php

/*
 * Knowledge Base for learners: GET /kiosk/kb.php[?q=<search>]. Learner session only. Lists the articles the
 * learner's department may read (the client portal's rule, KbAccess::scopeSql) grouped by category, with a search
 * box. Server-rendered and escaped at the point of output; no page script beyond the kiosk shell's.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Learn\KbAccess;

kiosk_require_session(['learner']);
if (intval($config_module_enable_kb ?? 0) !== 1) {
    kiosk_redirect('/kiosk/me.php');
}

$db = $kctx->db();
$client = KbAccess::clientId($db, $kctx->contactId());
$q = isset($_GET['q']) && is_string($_GET['q']) ? trim(mb_substr($_GET['q'], 0, 100)) : '';
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static fn(string $key, array $vars = []): string => KioskStrings::t($kctx->lang, $key, $vars);

$groups = [];
if ($client !== null) {
    $where = KbAccess::scopeSql($client);
    $types = '';
    $params = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $where .= ' AND (kb_article_title LIKE ? OR kb_article_content_raw LIKE ?)';
        $types = 'ss';
        $params = [$like, $like];
    }
    $rows = Db::all($db, "SELECT kb_article_id, kb_article_title, kb_article_client_id, kb_article_updated_at, kb_article_created_at,
            LEFT(kb_article_content_raw, 600) AS preview, kb_categories.kb_category_name
        FROM kb_articles
        LEFT JOIN kb_categories ON kb_categories.kb_category_id = kb_articles.kb_article_category_id
        WHERE $where
        ORDER BY kb_category_name IS NULL, kb_category_name ASC, kb_article_title ASC
        LIMIT 300", $types, $params);
    foreach ($rows as $r) {
        $groups[(string) ($r['kb_category_name'] ?? '')][] = $r;
    }
}
$total = 0;
foreach ($groups as $g) {
    $total += count($g);
}

$k_page = [
    'title' => $t('kb.title'),
    'css' => ['/css/itflow_training_kiosk_learn.css', '/css/itflow_training_kiosk_kb.css'],
    'js' => [],
    'body_class' => 'kx-learn kx-kb',
    'data' => new \stdClass(),
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kl-page kb-page">
  <a class="kx-btn kb-back" href="/kiosk/me.php"><i class="fas fa-arrow-left" aria-hidden="true"></i><span><?= $h($t('kb.back_home')) ?></span></a>
  <header>
    <h1 class="kl-h1"><?= $h($t('kb.title')) ?></h1>
    <p class="kl-sub"><?= $h($t('kb.sub')) ?></p>
  </header>
  <form class="kb-search" method="get" action="/kiosk/kb.php" role="search" autocomplete="off">
    <input class="kb-search__input" type="search" name="q" value="<?= $h($q) ?>" maxlength="100" placeholder="<?= $h($t('kb.search_ph')) ?>" aria-label="<?= $h($t('kb.search_ph')) ?>" enterkeyhint="search">
    <button class="kx-btn kx-btn--primary kb-search__go" type="submit"><i class="fas fa-search" aria-hidden="true"></i><span><?= $h($t('kb.search')) ?></span></button>
    <?php if ($q !== '') { ?><a class="kx-btn" href="/kiosk/kb.php"><span><?= $h($t('kb.clear')) ?></span></a><?php } ?>
  </form>
<?php if ($total === 0) { ?>
  <div class="kl-panel kl-panel--empty"><p class="kl-muted"><?= $h($q !== '' ? $t('kb.none_search', ['q' => $q]) : $t('kb.none')) ?></p></div>
<?php } ?>
<?php foreach ($groups as $cat => $articles) { ?>
  <section class="kl-sec">
    <h2 class="kl-h2"><i class="fas fa-folder" aria-hidden="true"></i><span><?= $h($cat !== '' ? $cat : $t('kb.uncategorized')) ?></span><span class="kl-count"><?= count($articles) ?></span></h2>
    <div class="kb-list">
<?php foreach ($articles as $a) {
    $prev = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $a['preview'])));
    if (mb_strlen($prev) > 140) {
        $prev = rtrim(mb_substr($prev, 0, 140)) . '…';
    } ?>
      <a class="kb-item" href="/kiosk/kb_article.php?id=<?= (int) $a['kb_article_id'] ?>">
        <span class="kb-item__icon" aria-hidden="true"><i class="fas fa-file-alt"></i></span>
        <span class="kb-item__body">
          <strong class="kb-item__title"><?= $h($a['kb_article_title']) ?></strong>
<?php if ($prev !== '') { ?>          <span class="kb-item__prev"><?= $h($prev) ?></span>
<?php } ?>
        </span>
        <i class="fas fa-chevron-right kb-item__go" aria-hidden="true"></i>
      </a>
<?php } ?>
    </div>
  </section>
<?php } ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php';
