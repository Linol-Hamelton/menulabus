<?php
/**
 * partials/published_reviews_section.php — public carousel of the best
 * published reviews for the tenant homepage (Phase 8.5).
 *
 * Include this in tenant index.php only. The partial self-checks for data
 * and renders nothing if the owner has not published any review yet, so
 * include sites can drop it in without a brand-new-tenant empty state.
 *
 * Expects a $db Database instance in scope (or will create one).
 */

if (!isset($db) || !$db instanceof Database) {
    require_once __DIR__ . '/../db.php';
    $db = Database::getInstance();
}

$publishedReviews = $db->getPublishedReviews(4, 6);
if (empty($publishedReviews)) return;
?>
<section class="published-reviews-section">
    <div class="container">
        <div class="section-heading">
            <h2>Отзывы гостей</h2>
            <p class="section-subtitle">Лучшие отзывы, отобранные рестораном.</p>
        </div>
        <ul class="published-reviews-list">
            <?php foreach ($publishedReviews as $review): ?>
                <?php
                $r = max(0, min(5, (int)($review['rating'] ?? 0)));
                $stars = str_repeat('★', $r) . str_repeat('☆', 5 - $r);
                $comment = trim((string)($review['comment'] ?? ''));
                $reply = trim((string)($review['reply_text'] ?? ''));
                ?>
                <li class="published-review-card">
                    <div class="published-review-stars" aria-label="<?= $r ?>/5"><?= $stars ?></div>
                    <?php if ($comment !== ''): ?>
                        <p class="published-review-comment"><?= nl2br(htmlspecialchars($comment)) ?></p>
                    <?php endif; ?>
                    <?php if ($reply !== ''): ?>
                        <blockquote class="published-review-reply">
                            <span class="published-review-reply-label">Ответ ресторана</span>
                            <p><?= nl2br(htmlspecialchars($reply)) ?></p>
                        </blockquote>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
