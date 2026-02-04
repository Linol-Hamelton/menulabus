"<?php
// Файл для включения прелоадера в PHP страницы
?>
<div id="global-preloader" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 9999; background: linear-gradient(135deg, #ffffff 0%, #f5f5f5 100%); display: flex; justify-content: center; align-items: center; opacity: 1; transition: opacity 0.5s ease;">
    <?php include 'preloader.html'; ?>
</div>
<script>
// JavaScript для управления прелоадером
(function() {
    'use strict';
    
    const preloader = document.getElementById('global-preloader');
    
    if (!preloader) return;
    
    // Функция для скрытия прелоадера
    function hidePreloader() {
        if (preloader.style.display !== 'none') {
            preloader.style.opacity = '0';
            setTimeout(() => {
                preloader.style.display = 'none';
            }, 500);
        }
    }
    
    // Скрываем при полной загрузке страницы
    if (document.readyState === 'complete') {
        hidePreloader();
    } else {
        window.addEventListener('load', hidePreloader);
    }

    // Fallback: скрыть через 3 секунды
    setTimeout(hidePreloader, 3000);
    
})();
</script>"