(() => {
  const root = document.getElementById("monitorPage");
  if (!root) {
    return;
  }

  const metricsTemplate = document.getElementById("monitorMetricsData");
  const resultBox = document.getElementById("result");
  const apiSection = document.getElementById("apiSection");
  const apiExample = document.getElementById("apiExample");
  const lastUpdate = document.getElementById("lastUpdate");
  const refreshSelect = document.getElementById("refreshInterval");
  const autoRefreshCheckbox = document.getElementById("autoRefresh");

  const messages = {
    metricsUpdated: "Метрики обновлены",
    refreshError: "Ошибка обновления метрик: ",
    clearOpcacheConfirm: "Вы уверены? Это сбросит весь кэш OPcache.",
    genericError: "Ошибка: ",
    csrfMissing: "CSRF token не найден. Обновите страницу.",
    serverCacheConfirm: "Вы уверены? Это сбросит весь серверный кэш.",
    serverCacheDefaultSuccess: "Server cache cleared",
    serverCacheDefaultError: "Cache clear failed",
    exportSuccess: "Метрики экспортированы",
    autoRefreshOn: "Автообновление включено",
    autoRefreshOff: "Автообновление выключено",
    healthExcellent: "Отличное",
    healthGood: "Хорошее",
    healthAttention: "Требует внимания",
    disabledOpcache: "Отключен",
  };

  const statusLabels = {
    good: "Хорошо",
    warning: "Предупреждение",
    critical: "Критично",
  };

  let currentMetrics = parseMetrics(metricsTemplate);
  let autoRefreshTimer = null;
  let resultTimer = null;
  let refreshInterval = parseInt(root.dataset.refreshIntervalMs || "30000", 10);

  function parseMetrics(template) {
    if (!template) {
      return null;
    }

    try {
      const payload = (template.textContent || template.innerHTML || "").trim();
      return JSON.parse(payload || "{}");
    } catch (error) {
      console.error("Failed to parse monitor metrics payload", error);
      return null;
    }
  }

  function showResult(message, type = "success") {
    if (!resultBox) {
      return;
    }

    if (resultTimer) {
      clearTimeout(resultTimer);
    }

    resultBox.className = `result ${type}`;
    resultBox.textContent = message;
    resultBox.hidden = false;

    resultTimer = window.setTimeout(() => {
      resultBox.hidden = true;
    }, 5000);
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, options);
    const payload = await response.json();
    return { response, payload };
  }

  function setLastUpdateNow() {
    if (!lastUpdate) {
      return;
    }

    lastUpdate.textContent = `Обновлено: ${new Date().toLocaleTimeString()}`;
  }

  async function refreshMetrics() {
    try {
      const { payload } = await fetchJson(root.dataset.getMetricsUrl || "?action=get_metrics");
      currentMetrics = payload;
      updateMetricsDisplay(payload);
      setLastUpdateNow();
      showResult(messages.metricsUpdated, "success");
    } catch (error) {
      showResult(messages.refreshError + error.message, "error");
    }
  }

  async function clearOpcache() {
    if (!window.confirm(messages.clearOpcacheConfirm)) {
      return;
    }

    try {
      const { payload } = await fetchJson(root.dataset.clearOpcacheUrl || "?action=clear_opcache");
      showResult(payload.message, payload.success ? "success" : "error");

      if (payload.success) {
        window.setTimeout(refreshMetrics, 2000);
      }
    } catch (error) {
      showResult(messages.genericError + error.message, "error");
    }
  }

  async function clearServerCache() {
    if (!window.confirm(messages.serverCacheConfirm)) {
      return;
    }

    const csrfToken = root.dataset.csrfToken || "";
    if (!csrfToken) {
      showResult(messages.csrfMissing, "error");
      return;
    }

    try {
      const { response, payload } = await fetchJson(root.dataset.clearServerCacheUrl || "/clear-cache.php?scope=server", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "X-CSRF-Token": csrfToken,
        },
      });

      const success = response.ok && payload.status === "success";
      let message = payload.message || (success ? messages.serverCacheDefaultSuccess : messages.serverCacheDefaultError);

      if (success && payload.details && Object.prototype.hasOwnProperty.call(payload.details, "redis_cache_cleared")) {
        message += ` (Redis: ${payload.details.redis_cache_cleared ? "ok" : "skip"})`;
      }

      showResult(message, success ? "success" : "error");

      if (success) {
        window.setTimeout(refreshMetrics, 2000);
      }
    } catch (error) {
      showResult(messages.genericError + error.message, "error");
    }
  }

  function exportMetrics() {
    const data = currentMetrics || {};
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");

    link.href = url;
    link.download = `metrics-${new Date().toISOString().split("T")[0]}.json`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);

    showResult(messages.exportSuccess, "success");
  }

  function showApi() {
    if (!apiSection || !apiExample) {
      return;
    }

    const isHidden = apiSection.hidden;
    apiSection.hidden = !isHidden;
    apiExample.textContent = JSON.stringify(currentMetrics || {}, null, 2);
  }

  function updateMetricsDisplay(metrics) {
    if (!metrics) {
      return;
    }

    updateSystemHealth(metrics);
    updateDetailedInfo(metrics);
  }

  function updateSystemHealth(metrics) {
    const healthDiv = document.getElementById("systemHealth");
    const healthBar = document.getElementById("healthBar");

    if (!healthDiv || !healthBar) {
      return;
    }

    let healthScore = 0;
    let totalMetrics = 0;

    if (metrics.php.opcache.enabled) {
      const opcacheRate = metrics.php.opcache.hit_rate;
      healthScore += opcacheRate >= 90 ? 100 : opcacheRate >= 70 ? 70 : 30;
      totalMetrics += 1;
    }

    const dbRate = metrics.database.buffer_pool_hit_rate;
    healthScore += dbRate >= 99 ? 100 : dbRate >= 95 ? 70 : 30;
    totalMetrics += 1;

    const cpuLoad = metrics.server.load_average?.["1min"] || 0;
    healthScore += cpuLoad < 1 ? 100 : cpuLoad < 2 ? 70 : 30;
    totalMetrics += 1;

    const averageHealth = Math.round(healthScore / totalMetrics);
    let statusClass = "status-critical";
    let statusText = messages.healthAttention;
    let meterClass = "progress-meter progress-danger";

    if (averageHealth >= 90) {
      statusClass = "status-good";
      statusText = messages.healthExcellent;
      meterClass = "progress-meter progress-success";
    } else if (averageHealth >= 70) {
      statusClass = "status-warning";
      statusText = messages.healthGood;
      meterClass = "progress-meter progress-warning";
    }

    healthDiv.className = `metric-value ${statusClass}`;
    healthDiv.textContent = `${statusText} (${averageHealth}%)`;
    healthBar.className = meterClass;
    healthBar.value = averageHealth;

    updateHealthIndicators(metrics);
  }

  function updateHealthIndicators(metrics) {
    const indicatorsRoot = document.getElementById("healthIndicators");
    if (!indicatorsRoot) {
      return;
    }

    indicatorsRoot.textContent = "";

    const indicators = [
      {
        label: "OPcache",
        value: metrics.php.opcache.enabled ? `${metrics.php.opcache.hit_rate}%` : messages.disabledOpcache,
        status: metrics.php.opcache.enabled
          ? (metrics.php.opcache.hit_rate >= 90 ? "good" : metrics.php.opcache.hit_rate >= 70 ? "warning" : "critical")
          : "warning",
      },
      {
        label: "БД Buffer Pool",
        value: `${metrics.database.buffer_pool_hit_rate}%`,
        status: metrics.database.buffer_pool_hit_rate >= 99 ? "good" : metrics.database.buffer_pool_hit_rate >= 95 ? "warning" : "critical",
      },
      {
        label: "Загрузка CPU",
        value: `${metrics.server.load_average?.["1min"] || "N/A"}`,
        status: (metrics.server.load_average?.["1min"] || 0) < 1 ? "good" : (metrics.server.load_average?.["1min"] || 0) < 2 ? "warning" : "critical",
      },
    ];

    indicators.forEach((indicator) => {
      const item = document.createElement("div");
      item.className = "stat-item";

      const label = document.createElement("div");
      label.className = "stat-label";

      const dot = document.createElement("span");
      dot.className = `health-indicator health-${indicator.status}`;

      const labelText = document.createElement("span");
      labelText.textContent = indicator.label;

      label.appendChild(dot);
      label.appendChild(labelText);

      const value = document.createElement("div");
      value.className = "stat-value";
      value.textContent = indicator.value;

      item.appendChild(label);
      item.appendChild(value);
      indicatorsRoot.appendChild(item);
    });
  }

  function updateDetailedInfo(metrics) {
    const tbody = document.getElementById("detailedInfo");
    if (!tbody) {
      return;
    }

    tbody.textContent = "";

    const rows = [
      ["PHP", "Версия", metrics.php.version, "good"],
      ["PHP", "OPcache", metrics.php.opcache.enabled ? "Включен" : "Выключен", metrics.php.opcache.enabled ? "good" : "warning"],
      ["PHP", "OPcache Hit Rate", `${metrics.php.opcache.hit_rate}%`, metrics.php.opcache.hit_rate >= 90 ? "good" : metrics.php.opcache.hit_rate >= 70 ? "warning" : "critical"],
      ["БД", "Buffer Pool Hit Rate", `${metrics.database.buffer_pool_hit_rate}%`, metrics.database.buffer_pool_hit_rate >= 99 ? "good" : metrics.database.buffer_pool_hit_rate >= 95 ? "warning" : "critical"],
      ["БД", "Соединения", `${metrics.database.connections} / ${metrics.database.max_connections}`, metrics.database.connections_percentage < 80 ? "good" : metrics.database.connections_percentage < 90 ? "warning" : "critical"],
      ["БД", "Медленные запросы", `${metrics.database.slow_queries}`, metrics.database.slow_queries < 5 ? "good" : metrics.database.slow_queries < 20 ? "warning" : "critical"],
      ["Сервер", "Загрузка (1min)", `${metrics.server.load_average?.["1min"] || "N/A"}`, (metrics.server.load_average?.["1min"] || 0) < 1 ? "good" : (metrics.server.load_average?.["1min"] || 0) < 2 ? "warning" : "critical"],
      ["Сервер", "Память", `${formatBytes(metrics.server.memory.used)} / ${metrics.server.memory.limit}`, "good"],
      ["Сервер", "Пик памяти", formatBytes(metrics.server.memory.peak), "good"],
      ["Приложение", "Время выполнения", metrics.performance.execution.time, "good"],
      ["Приложение", "Использование памяти", metrics.performance.execution.memory, "good"],
      ["Приложение", "Запросы к БД", `${metrics.performance.database_queries.total} (${metrics.performance.database_queries.cached} кэшировано)`, "good"],
    ];

    rows.forEach((row) => {
      const tr = document.createElement("tr");

      row.slice(0, 3).forEach((cellText) => {
        const td = document.createElement("td");
        td.textContent = String(cellText);
        tr.appendChild(td);
      });

      const statusCell = document.createElement("td");
      statusCell.className = `status-${row[3]}`;
      statusCell.textContent = statusLabels[row[3]] || row[3];
      tr.appendChild(statusCell);

      tbody.appendChild(tr);
    });
  }

  function formatBytes(bytes) {
    if (bytes === 0) {
      return "0 B";
    }

    const units = ["B", "KB", "MB", "GB", "TB"];
    const power = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${parseFloat((bytes / Math.pow(1024, power)).toFixed(2))} ${units[power]}`;
  }

  function startAutoRefresh() {
    if (autoRefreshTimer) {
      window.clearInterval(autoRefreshTimer);
    }

    autoRefreshTimer = window.setInterval(refreshMetrics, refreshInterval);
    showResult(`${messages.autoRefreshOn} (${refreshInterval / 1000} сек)`, "success");
  }

  function stopAutoRefresh() {
    if (!autoRefreshTimer) {
      return;
    }

    window.clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
    showResult(messages.autoRefreshOff, "warning");
  }

  function toggleAutoRefresh() {
    if (autoRefreshCheckbox?.checked) {
      startAutoRefresh();
    } else {
      stopAutoRefresh();
    }
  }

  function updateRefreshInterval() {
    if (!refreshSelect) {
      return;
    }

    refreshInterval = parseInt(refreshSelect.value, 10) * 1000;
    if (autoRefreshCheckbox?.checked) {
      startAutoRefresh();
    }
  }

  function bindActions() {
    const actions = {
      refreshMetrics,
      clearOpcache,
      clearServerCache,
      exportMetrics,
      showApi,
    };

    document.querySelectorAll("[data-action]").forEach((button) => {
      button.addEventListener("click", () => {
        button.disabled = true;
        window.setTimeout(() => {
          button.disabled = false;
        }, 2000);

        const action = actions[button.dataset.action];
        if (action) {
          action();
        }
      });
    });
  }

  function bindVisibilityRefresh() {
    const visibilityChange =
      typeof document.hidden !== "undefined"
        ? "visibilitychange"
        : typeof document.msHidden !== "undefined"
          ? "msvisibilitychange"
          : typeof document.webkitHidden !== "undefined"
            ? "webkitvisibilitychange"
            : null;

    if (!visibilityChange) {
      return;
    }

    document.addEventListener(visibilityChange, () => {
      if (!document.hidden && autoRefreshTimer) {
        refreshMetrics();
      }
    });
  }

  function init() {
    updateMetricsDisplay(currentMetrics);

    if (refreshSelect) {
      refreshSelect.value = String(refreshInterval / 1000);
      refreshSelect.addEventListener("change", updateRefreshInterval);
    }

    autoRefreshCheckbox?.addEventListener("change", toggleAutoRefresh);

    bindActions();
    bindVisibilityRefresh();

    if (autoRefreshCheckbox?.checked) {
      startAutoRefresh();
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();
