/* reviews.js — wires the review form on order-track.php
 *
 * No framework; no dependencies. Reads CSRF from <meta name="csrf-token">,
 * posts JSON to /save-review.php, handles three states:
 *   - idle: waiting for a star selection
 *   - selected: at least one star picked, submit becomes enabled
 *   - submitted: form hidden, thank-you panel shown, optional Google link
 */

(function () {
    'use strict';

    const section = document.getElementById('reviewSection');
    if (!section) {
        return;
    }

    const form = document.getElementById('reviewForm');
    const submitBtn = form ? form.querySelector('.review-submit') : null;
    const statusEl = document.getElementById('reviewStatus');
    const thanksEl = document.getElementById('reviewThanks');
    const thanksText = document.getElementById('reviewThanksText');
    const googleLink = document.getElementById('reviewGoogleLink');

    if (!form || !submitBtn) {
        return;
    }

    const orderId = parseInt(section.getAttribute('data-order-id') || '0', 10);
    const endpoint = section.getAttribute('data-endpoint') || '/save-review.php';

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) {
            return meta.content;
        }
        const hidden = document.querySelector('input[name="csrf_token"]');
        return hidden ? hidden.value : '';
    }

    function getSelectedRating() {
        const checked = form.querySelector('input[name="rating"]:checked');
        return checked ? parseInt(checked.value, 10) : 0;
    }

    function setStatus(text, kind) {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = text || '';
        statusEl.classList.remove('review-status--error', 'review-status--success');
        if (kind === 'error') {
            statusEl.classList.add('review-status--error');
        } else if (kind === 'success') {
            statusEl.classList.add('review-status--success');
        }
    }

    form.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'rating') {
            submitBtn.disabled = getSelectedRating() < 1;
            setStatus('', null);
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const rating = getSelectedRating();
        if (rating < 1) {
            setStatus('Выберите оценку', 'error');
            return;
        }
        if (orderId <= 0) {
            setStatus('Не удалось определить номер заказа', 'error');
            return;
        }

        const csrf = getCsrfToken();
        if (!csrf) {
            setStatus('Сессия устарела, обновите страницу', 'error');
            return;
        }

        const commentEl = form.querySelector('textarea[name="comment"]');
        const comment = commentEl ? commentEl.value : '';

        submitBtn.disabled = true;
        setStatus('Отправка…', null);

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                order_id: orderId,
                rating: rating,
                comment: comment,
                csrf_token: csrf
            })
        })
            .then(function (r) {
                return r.json().then(function (body) {
                    return { ok: r.ok, status: r.status, body: body };
                });
            })
            .then(function (res) {
                if (!res.ok || !res.body || res.body.success !== true) {
                    const msg = (res.body && res.body.error) || 'Не удалось сохранить отзыв';
                    setStatus(msg, 'error');
                    submitBtn.disabled = getSelectedRating() < 1;
                    return;
                }

                // Success: hide form, show thank-you panel.
                form.style.display = 'none';
                if (thanksEl) {
                    thanksEl.classList.remove('review-thanks--hidden');
                }
                if (thanksText) {
                    thanksText.textContent = rating >= 5
                        ? 'Спасибо за 5 звёзд! Помогите другим гостям — оставьте отзыв в Google.'
                        : 'Ваша оценка сохранена. Мы обязательно её прочитаем.';
                }
                if (googleLink && res.body.google_review_url) {
                    googleLink.setAttribute('href', res.body.google_review_url);
                    googleLink.classList.remove('review-google-link--hidden');
                }
            })
            .catch(function () {
                setStatus('Ошибка сети, попробуйте ещё раз', 'error');
                submitBtn.disabled = getSelectedRating() < 1;
            });
    });
})();
