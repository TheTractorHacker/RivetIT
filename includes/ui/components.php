<?php
/*
 * Reusable UI component helpers (modernization plan §8). Additive/opt-in —
 * required once from functions.php so every portal (agent/admin/client) can
 * call these without each page adding its own require. Nothing here is
 * wired into any existing page yet.
 */

require_once __DIR__ . '/page_header.php';
require_once __DIR__ . '/card.php';
require_once __DIR__ . '/stat_card.php';
require_once __DIR__ . '/status_badge.php';
require_once __DIR__ . '/priority_badge.php';
require_once __DIR__ . '/empty_state.php';
