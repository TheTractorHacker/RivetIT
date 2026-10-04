-- Training kiosk knowledge base: an article shows on the kiosk only while "Show on Training Portal" is on
-- (off by default). A few company-wide articles are switched on so the kiosk's Knowledge base page has content
-- for the guide's pictures.
--
-- Runs late on purpose: most of these articles are created by 70-portal.php, which sorts after
-- 55-training-delivery.php (that seed makes the same change for any of them that already exist).
-- Idempotent: matched by title, and setting the flag twice changes nothing.
UPDATE kb_articles
   SET kb_article_training_visible = 1
 WHERE kb_article_client_id = 0
   AND kb_article_archived_at IS NULL
   AND kb_article_title IN (
        'Connect to the staff Wi-Fi',
        'How to spot a phishing email',
        'Lock your screen and choose a strong password',
        'Use the VPN from home',
        'What to do if your laptop or phone is lost or stolen'
   );
