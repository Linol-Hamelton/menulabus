(function () {
    'use strict';

    var pane = document.getElementById('analytics-v2');
    if (!pane) return;

    var fromEl      = document.getElementById('anFrom');
    var toEl        = document.getElementById('anTo');
    var heatDaysEl  = document.getElementById('anHeatDays');
    var heatLabel   = document.getElementById('anHeatDaysLabel');
    var applyBtn    = document.getElementById('anApply');
    var marginsTbl  = document.getElementById('anMarginsTable');
    var cohortsHead = document.getElementById('anCohortsHead');
    var cohortsBody = document.getElementById('anCohortsBody');
    var heatmapEl   = document.getElementById('anHeatmap');
    var forecastVal = document.getElementById('anForecastValue');

    var dows = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtRub(n) {
        return Number(n || 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
    }

    function renderMargins(rows) {
        if (!marginsTbl) return;
        marginsTbl.innerHTML = '';
        if (!rows || rows.length === 0) {
            marginsTbl.innerHTML = '<tr><td colspan="4" class="an-empty">Нет продаж в выбранном окне.</td></tr>';
            return;
        }
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.innerHTML = ''
                + '<td>' + escHtml(r.name) + '<div class="an-cat">' + escHtml(r.category) + '</div></td>'
                + '<td class="num">' + (r.units_sold || 0) + '</td>'
                + '<td class="num">' + fmtRub(r.revenue) + ' ₽</td>'
                + '<td class="num"><span class="an-margin-pill ' + (Number(r.gross_margin_pct) >= 50 ? 'ok' : 'warn') + '">' + Number(r.gross_margin_pct).toFixed(1) + '%</span></td>';
            marginsTbl.appendChild(tr);
        });

        // Draw a simple horizontal bar chart via canvas (no external Chart.js dep
        // — keeps CSP strict and avoids loading ~200 kb for four bars).
        var canvas = document.getElementById('anMarginsChart');
        if (canvas && canvas.getContext) {
            var ctx = canvas.getContext('2d');
            var w = canvas.width = canvas.clientWidth;
            var h = canvas.height = Math.max(140, Math.min(rows.length, 10) * 22 + 20);
            ctx.clearRect(0, 0, w, h);
            var top = rows.slice(0, 10);
            var maxRev = top.reduce(function (m, r) { return Math.max(m, Number(r.revenue) || 0); }, 0) || 1;
            top.forEach(function (r, i) {
                var y = 10 + i * 22;
                var barW = Math.max(2, Math.round(Number(r.revenue) / maxRev * (w - 150)));
                ctx.fillStyle = '#6366f1';
                ctx.fillRect(140, y, barW, 14);
                ctx.fillStyle = '#111';
                ctx.font = '12px sans-serif';
                ctx.textBaseline = 'middle';
                ctx.textAlign = 'right';
                ctx.fillText((r.name || '').slice(0, 18), 135, y + 7);
                ctx.textAlign = 'left';
                ctx.fillText(fmtRub(r.revenue) + ' ₽', 145 + barW, y + 7);
            });
        }
    }

    function renderCohorts(cohorts) {
        if (!cohortsHead || !cohortsBody) return;
        cohortsHead.innerHTML = '';
        cohortsBody.innerHTML = '';
        if (!cohorts || cohorts.length === 0) {
            cohortsBody.innerHTML = '<tr><td class="an-empty" colspan="14">Нет данных по когортам.</td></tr>';
            return;
        }
        var head = '<tr><th>Когорта</th><th>Размер</th>';
        for (var m = 0; m <= 12; m++) head += '<th>+' + m + '</th>';
        head += '</tr>';
        cohortsHead.innerHTML = head;

        cohorts.forEach(function (c) {
            var size = c.size || 0;
            var tr = '<tr><td>' + escHtml(c.cohort) + '</td><td class="num">' + size + '</td>';
            (c.retention || []).forEach(function (count, idx) {
                if (idx > 12) return;
                if (count === 0 && idx !== 0) { tr += '<td class="num an-cell-empty">—</td>'; return; }
                var pct = size > 0 ? Math.round((count / size) * 100) : 0;
                // CSP-safe: bucket 0..5 instead of inline `style="background:hsl(...)"`.
                // Real colors live in css/owner-analytics-v2.css under [data-heat="N"].
                var bucket = bucketFromPct(pct / 100);
                tr += '<td class="num" data-heat="' + bucket + '" title="' + count + ' активных">' + pct + '%</td>';
            });
            tr += '</tr>';
            cohortsBody.innerHTML += tr;
        });
    }

    function renderHeatmap(heat) {
        if (!heatmapEl) return;
        heatmapEl.innerHTML = '';
        var grid = (heat && heat.grid) || [];
        var max = (heat && heat.max) || 0;
        if (heatLabel) heatLabel.textContent = String((heat && heat.days) || 30);

        var head = '<thead><tr><th></th>';
        for (var h = 0; h < 24; h++) head += '<th>' + h + '</th>';
        head += '</tr></thead><tbody>';
        heatmapEl.innerHTML = head;

        var body = '';
        for (var d = 0; d < 7; d++) {
            body += '<tr><th>' + dows[d] + '</th>';
            for (var hh = 0; hh < 24; hh++) {
                var v = (grid[d] && grid[d][hh]) || 0;
                var pct = max > 0 ? v / max : 0;
                // CSP-safe heat bucket — see [data-heat="N"] rules in
                // css/owner-analytics-v2.css. v=0 → bucket 0 (empty).
                var bucket = v === 0 ? 0 : bucketFromPct(pct);
                body += '<td data-heat="' + bucket + '" title="' + v + ' заказов">' + (v > 0 ? v : '') + '</td>';
            }
            body += '</tr>';
        }
        heatmapEl.innerHTML += body + '</tbody>';
    }

    /**
     * Maps a 0..1 ratio into one of 6 heat buckets (0=empty, 1=lowest, 5=hottest).
     * The associated background colors live in CSS via [data-heat="N"] selectors,
     * so the JS layer never needs `el.style.background = ...` (which strict CSP
     * `style-src 'self' 'nonce-…'` blocks for synthetic-event/JS-set inline styles).
     */
    function bucketFromPct(pct) {
        if (pct <= 0)    return 0;
        if (pct < 0.20)  return 1;
        if (pct < 0.40)  return 2;
        if (pct < 0.60)  return 3;
        if (pct < 0.80)  return 4;
        return 5;
    }

    function renderForecast(f) {
        if (!forecastVal) return;
        forecastVal.textContent = fmtRub((f && f.forecast) || 0);
        var canvas = document.getElementById('anForecastChart');
        if (!canvas || !canvas.getContext) return;
        var ctx = canvas.getContext('2d');
        var w = canvas.width = canvas.clientWidth;
        var h = canvas.height = 160;
        ctx.clearRect(0, 0, w, h);
        var weekly = (f && f.weekly) || [];
        if (weekly.length === 0) return;
        var max = weekly.reduce(function (m, w) { return Math.max(m, w.revenue); }, 0) || 1;
        var dx = (w - 20) / Math.max(1, weekly.length - 1);
        ctx.strokeStyle = '#6366f1';
        ctx.lineWidth = 2;
        ctx.beginPath();
        weekly.forEach(function (p, i) {
            var x = 10 + i * dx;
            var y = h - 10 - (p.revenue / max) * (h - 20);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.stroke();
        // Forecast point (dashed extension).
        if (f && typeof f.forecast === 'number') {
            var lastX = 10 + (weekly.length - 1) * dx;
            var lastY = h - 10 - (weekly[weekly.length - 1].revenue / max) * (h - 20);
            var fx = Math.min(w - 10, lastX + dx);
            var fy = h - 10 - (f.forecast / max) * (h - 20);
            ctx.strokeStyle = '#f59e0b';
            ctx.setLineDash([4, 4]);
            ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(fx, fy); ctx.stroke();
            ctx.setLineDash([]);
            ctx.fillStyle = '#f59e0b';
            ctx.beginPath(); ctx.arc(fx, fy, 4, 0, Math.PI * 2); ctx.fill();
        }
    }

    function fetchAndRender() {
        var params = new URLSearchParams({
            action: 'bundle',
            from: fromEl.value,
            to: toEl.value,
            heat_days: heatDaysEl.value,
        });
        fetch('/api/analytics-v2.php?' + params.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                window.alert('Не удалось загрузить: ' + ((data && data.error) || 'unknown'));
                return;
            }
            renderMargins(data.margins || []);
            renderCohorts(data.cohorts || []);
            renderHeatmap(data.heatmap || { grid: [], max: 0, days: parseInt(heatDaysEl.value, 10) || 30 });
            renderForecast(data.forecast || { weekly: [], forecast: 0 });
        })
        .catch(function () { /* noop */ });
    }

    if (applyBtn) applyBtn.addEventListener('click', fetchAndRender);

    // Initial load when the tab is opened; tabs are CSS-only (no SPA), so we
    // trigger on DOMContentLoaded for visible panes.
    if (pane.classList.contains('active')) {
        fetchAndRender();
    } else {
        // Observe DOM for class mutations so lazy-load fires when the tab becomes active.
        var mo = new MutationObserver(function () {
            if (pane.classList.contains('active') && !pane.dataset.anLoaded) {
                pane.dataset.anLoaded = '1';
                fetchAndRender();
            }
        });
        mo.observe(pane, { attributes: true, attributeFilter: ['class'] });
    }
})();
