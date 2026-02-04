<?php
/**
 * Универсальный preloader для всех страниц
 * Использование: include('preloader-universal.php'); в начале <body>
 * Показывается мгновенно, скрывается после полной загрузки страницы
 */
?>
<style nonce="<?= $styleNonce ?? '' ?>">
    /* Критические стили preloader - inline для мгновенной загрузки */
    #global-preloader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 99999;
        background: linear-gradient(135deg, #ffffff 0%, #f5f5f5 100%);
        display: flex;
        justify-content: center;
        align-items: center;
        opacity: 1;
        transition: opacity 0.5s ease;
        pointer-events: none; /* Не блокирует взаимодействие после скрытия */
    }

    #global-preloader.preloader-hidden {
        opacity: 0;
        visibility: hidden;
    }

    .preloader-container {
        position: relative;
        width: 60vmin;
        height: 60vmin;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .preloader-logo-wrapper {
        position: relative;
        width: 100%;
        height: 100%;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .preloader-logo-svg {
        width: 100%;
        height: 100%;
        filter: drop-shadow(0 10px 30px rgba(226, 13, 19, 0.2));
        animation: preloader-pulse 2s ease-in-out infinite;
    }

    @keyframes preloader-pulse {
        0%, 100% {
            opacity: 0.7;
        }
        50% {
            opacity: 1;
        }
    }

    .preloader-pulse-ring {
        position: absolute;
        width: 100%;
        height: 100%;
        border: 3px solid transparent;
        border-top-color: #E20D13;
        border-right-color: #E20D13;
        border-radius: 50%;
        animation: preloader-spin 3s linear infinite;
        opacity: 0.6;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }

    .preloader-pulse-ring:nth-child(2) {
        width: 85%;
        height: 85%;
        animation: preloader-spin 2.5s linear infinite reverse;
        border-top-color: #E20D13;
        border-left-color: #E20D13;
        opacity: 0.4;
    }

    .preloader-pulse-ring:nth-child(3) {
        width: 70%;
        height: 70%;
        animation: preloader-spin 3s linear infinite;
        border-bottom-color: #E20D13;
        border-right-color: #E20D13;
        opacity: 0.2;
    }

    @keyframes preloader-spin {
        0% {
            transform: translate(-50%, -50%) rotate(0deg);
        }
        100% {
            transform: translate(-50%, -50%) rotate(360deg);
        }
    }

    .preloader-bubble {
        position: absolute;
        width: 6px;
        height: 6px;
        background-color: #E20D13;
        border-radius: 50%;
        animation: preloader-flicker 1.2s ease-in-out infinite;
    }

    .preloader-bubble:nth-child(1) {
        top: 8px;
        left: 16px;
        animation-delay: 0s;
    }

    .preloader-bubble:nth-child(2) {
        top: 5px;
        left: 19px;
        animation-delay: 0.3s;
    }

    .preloader-bubble:nth-child(3) {
        top: 10px;
        left: 22px;
        animation-delay: 0.6s;
    }

    @keyframes preloader-flicker {
        0%, 100% {
            opacity: 0;
        }
        50% {
            opacity: 1;
        }
    }

    .preloader-loading-text {
        position: absolute;
        bottom: -60px;
        font-size: 14px;
        color: #666;
        letter-spacing: 3px;
        text-transform: uppercase;
        animation: preloader-fadeInOut 1.5s ease-in-out infinite;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    @keyframes preloader-fadeInOut {
        0%, 100% {
            opacity: 0.5;
        }
        50% {
            opacity: 1;
        }
    }

    @media (max-width: 768px) {
        .preloader-loading-text {
            font-size: 12px;
        }
    }
</style>

<div id="global-preloader">
    <div class="preloader-container">
        <div class="preloader-logo-wrapper">
            <svg class="preloader-logo-svg" viewBox="-15 -13 68 78" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path stroke="none" fill-rule="evenodd" clip-rule="evenodd" d="M2.01119 39.3728H2V39.0743C2 38.1305 2.37215 36.8751 2.69508 35.9924C2.7003 35.9779 2.70552 35.9634 2.71149 35.9489L13.6545 7.93136C13.6657 7.90158 13.6784 7.8718 13.6918 7.84355C13.8484 7.46646 13.9136 7.05623 13.882 6.64777C13.7999 5.85746 13.2443 5.44435 12.5448 5.23131C11.705 4.9778 11.2471 4.05768 11.4753 3.20094C11.7035 2.34419 12.559 1.80739 13.404 2.06472C15.3497 2.65726 16.8233 3.89809 17.0322 6.06744C17.1307 7.07767 16.9233 8.26505 16.5288 9.19815L5.59696 37.1852C5.41723 37.6777 5.11891 38.5566 5.11891 39.0704V39.2644C5.18454 40.5564 5.89155 41.7407 7.02143 42.334C7.24039 42.4494 7.47128 42.5393 7.70979 42.602C7.95826 42.6658 8.21292 42.7009 8.46901 42.7066L29.575 42.6998C30.8846 42.6601 32.0219 41.8316 32.5581 40.6198C32.6631 40.3838 32.7434 40.137 32.7975 39.8837C32.8409 39.6827 32.868 39.4784 32.8788 39.2728V39.0727C32.8788 38.5672 32.6021 37.741 32.4314 37.2562L32.4281 37.2486L32.4241 37.239L32.4202 37.2294L21.3078 8.90035C20.9879 8.08179 21.3466 7.10668 22.1476 6.75085C22.9486 6.39502 23.8651 6.8211 24.1919 7.64119L35.3042 35.9703C35.3198 36.0115 35.334 36.0527 35.3467 36.0955C35.6509 36.966 36 38.1519 36 39.0735V39.3789H35.9805V39.4057C35.9642 39.8096 35.9142 40.2115 35.8314 40.6068C35.7288 41.0936 35.5761 41.5679 35.3758 42.0217C34.3481 44.3407 32.1651 45.916 29.6607 45.9924H29.5861L8.44216 46H8.41382C7.92667 45.9929 7.44206 45.927 6.96997 45.8038C6.50725 45.6835 6.05921 45.5103 5.63425 45.2876C3.46847 44.1498 2.12007 41.8736 2.01119 39.3996V39.3728ZM20.4755 16.6317C20.6711 16.3319 20.7758 15.9795 20.7761 15.619C20.7745 15.134 20.5852 14.6694 20.2497 14.3271C19.9142 13.9847 19.4598 13.7925 18.9861 13.7925C18.634 13.794 18.2902 13.9023 17.998 14.1036C17.706 14.305 17.4786 14.5905 17.3449 14.924C17.211 15.2575 17.1767 15.6241 17.2462 15.9776C17.3157 16.3311 17.4859 16.6555 17.7354 16.91C17.9847 17.1646 18.3022 17.3377 18.6477 17.4077C18.9931 17.4777 19.3511 17.4413 19.6764 17.3032C20.0017 17.1651 20.2797 16.9314 20.4755 16.6317ZM17.6767 21.9524C17.6766 22.2299 17.596 22.5012 17.4454 22.732C17.2947 22.9628 17.0806 23.1426 16.8302 23.2489C16.5797 23.3551 16.3042 23.383 16.0382 23.329C15.7723 23.275 15.528 23.1415 15.3361 22.9454C15.1443 22.7493 15.0135 22.4993 14.9603 22.2271C14.9071 21.955 14.9339 21.6728 15.0372 21.4162C15.1406 21.1596 15.316 20.9401 15.5411 20.7855C15.7662 20.6308 16.0311 20.548 16.3022 20.5474C16.6663 20.5474 17.0156 20.6953 17.2733 20.9587C17.531 21.2222 17.6761 21.5795 17.6767 21.9524ZM8.3139 33.981C8.79917 32.6967 9.29463 31.4169 9.80027 30.1417C11.6953 28.8734 13.785 28.3313 16.0373 28.6787C17.2187 28.8609 18.0946 29.3051 18.9569 29.7422C19.7386 30.1386 20.509 30.5292 21.4853 30.7137C23.5385 31.1016 25.8795 30.8297 27.8559 29.4461C27.9861 29.7752 28.1134 30.0958 28.239 30.4121L28.2409 30.4168C28.6371 31.4143 29.0165 32.3691 29.4124 33.4114C29.7182 34.2117 30.0195 35.0142 30.3073 35.8205L30.3775 36.0162C30.5531 36.5055 30.7352 37.0126 30.8666 37.5088C31.0859 38.3198 31.1061 39.2506 30.7064 40.0088C30.1537 41.0595 29.0156 41.3756 27.9237 41.3756H10.0248C8.93291 41.3756 7.79409 41.0603 7.24146 40.0103C6.84171 39.2521 6.86259 38.3198 7.08186 37.5088C7.21286 37.0205 7.38531 36.5314 7.55285 36.0562L7.56811 36.013C7.80975 35.3326 8.06034 34.6561 8.3139 33.981ZM24.9377 24.4685C24.9372 24.9678 24.7924 25.4558 24.5212 25.8709C24.2501 26.2859 23.8648 26.6094 23.4143 26.8005C22.9637 26.9916 22.4679 27.0417 21.9895 26.9445C21.5111 26.8473 21.0716 26.6072 20.7264 26.2544C20.3812 25.9017 20.1459 25.4521 20.0501 24.9625C19.9543 24.4728 20.0024 23.9651 20.1882 23.5035C20.374 23.0418 20.6893 22.6468 21.0941 22.3685C21.4991 22.0901 21.9755 21.9408 22.4632 21.9395C23.1188 21.939 23.7478 22.2052 24.2118 22.6795C24.6758 23.1537 24.9369 23.7972 24.9377 24.4685Z" fill="#E20D13"></path>
                <g class="preloader-bubbles">
                    <circle class="preloader-bubble" r="3" cx="16" cy="5"/>
                    <circle class="preloader-bubble" r="3" cx="19" cy="3"/>
                    <circle class="preloader-bubble" r="3" cx="22" cy="6"/>
                </g>
            </svg>
            <div class="preloader-pulse-ring"></div>
            <div class="preloader-pulse-ring"></div>
            <div class="preloader-pulse-ring"></div>
        </div>
        <div class="preloader-loading-text">Загрузка</div>
    </div>
</div>

<script nonce="<?= $scriptNonce ?? '' ?>">
(function() {
    'use strict';

    const preloader = document.getElementById('global-preloader');

    if (!preloader) return;

    let isHidden = false;

    // Функция для скрытия preloader
    function hidePreloader() {
        if (isHidden) return;
        isHidden = true;

        preloader.classList.add('preloader-hidden');

        // Полностью удаляем из DOM через 500мс (после анимации)
        setTimeout(function() {
            if (preloader.parentNode) {
                preloader.parentNode.removeChild(preloader);
            }
        }, 500);
    }

    // Скрываем при полной загрузке страницы
    if (document.readyState === 'complete') {
        // Если страница уже загружена (кэш), скрываем сразу
        hidePreloader();
    } else {
        // Ждем события window.load (все ресурсы загружены)
        window.addEventListener('load', hidePreloader);

        // Fallback: скрыть через 5 секунд если load не сработал
        setTimeout(hidePreloader, 5000);
    }

    // Дополнительная проверка: скрыть если DOM интерактивен и прошло 2 секунды
    if (document.readyState === 'interactive') {
        setTimeout(hidePreloader, 2000);
    }
})();
</script>
