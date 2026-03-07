document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("menuQuickSearch");
  const categoryLabel = document.getElementById("menuActiveCategoryLabel");
  const categoryMeta = document.getElementById("menuActiveCategoryMeta");
  const globalEmpty = document.getElementById("menuGlobalNoResults");
  const quickButtons = Array.from(document.querySelectorAll(".menu-quickcat-btn"));
  const tabButtons = Array.from(document.querySelectorAll(".menu-tabs-container .tab-btn"));
  const panes = Array.from(document.querySelectorAll(".menu-content .tab-pane"));
  const itemSelectors = [".cart-item", ".menu-item"];

  if (!searchInput || !categoryLabel || !categoryMeta || !panes.length || !tabButtons.length) {
    return;
  }

  function getPaneItems(pane) {
    return Array.from(pane.querySelectorAll(itemSelectors.join(", ")));
  }

  function getItemText(item) {
    const category = item.closest(".tab-pane")?.id || "";
    const title =
      item.querySelector(".cart-item-title")?.textContent ||
      item.querySelector("h3")?.textContent ||
      "";
    const description =
      item.querySelector("p")?.textContent ||
      item.querySelector(".cart-item-price")?.textContent ||
      "";

    return `${category} ${title} ${description}`.trim().toLowerCase();
  }

  function ensurePaneEmptyNode(pane) {
    let emptyState = pane.querySelector(".menu-no-results");
    if (!emptyState) {
      emptyState = document.createElement("div");
      emptyState.className = "menu-no-results";
      emptyState.hidden = true;
      emptyState.textContent = "В этом разделе сейчас нет позиций по вашему запросу.";
      pane.appendChild(emptyState);
    }

    return emptyState;
  }

  function getActiveTabButton() {
    return document.querySelector(".menu-tabs-container .tab-btn.active") || tabButtons[0];
  }

  function updateQuickButtons(activeCategory, matchedCategories) {
    quickButtons.forEach((button) => {
      const category = button.dataset.tabTarget || "";
      button.classList.toggle("is-active", activeCategory !== "" && category === activeCategory);
      button.classList.toggle("is-match", matchedCategories.has(category));
    });
  }

  function resetSearchMode() {
    document.body.classList.remove("menu-search-mode");

    panes.forEach((pane) => {
      pane.classList.remove("menu-search-pane-active");
      pane.removeAttribute("data-search-title");
      getPaneItems(pane).forEach((item) => {
        item.style.display = "";
      });
      const emptyState = ensurePaneEmptyNode(pane);
      emptyState.hidden = true;
    });

    if (globalEmpty) {
      globalEmpty.hidden = true;
    }

    const activeButton = getActiveTabButton();
    const activePane = document.querySelector(".menu-content .tab-pane.active") || panes[0];
    const visibleCount = getPaneItems(activePane).length;
    const activeCategory = activeButton?.dataset.tab || activeButton?.textContent?.trim() || "Категория";

    categoryLabel.textContent = activeCategory;
    categoryMeta.textContent = `${visibleCount} позиций в текущем разделе`;
    updateQuickButtons(activeCategory, new Set());
  }

  function applyGlobalSearch() {
    const query = searchInput.value.trim().toLowerCase();
    if (query === "") {
      resetSearchMode();
      return;
    }

    document.body.classList.add("menu-search-mode");

    let totalMatches = 0;
    let matchedPaneCount = 0;
    const matchedCategories = new Set();

    panes.forEach((pane) => {
      const items = getPaneItems(pane);
      const paneTitle = pane.id || "Раздел";
      let paneMatches = 0;

      items.forEach((item) => {
        const matches = getItemText(item).includes(query);
        item.style.display = matches ? "" : "none";
        if (matches) {
          paneMatches += 1;
          totalMatches += 1;
        }
      });

      const emptyState = ensurePaneEmptyNode(pane);
      const paneHasMatches = paneMatches > 0;
      pane.classList.toggle("menu-search-pane-active", paneHasMatches);
      pane.dataset.searchTitle = paneTitle;
      emptyState.hidden = paneHasMatches;

      if (paneHasMatches) {
        matchedPaneCount += 1;
        matchedCategories.add(paneTitle);
      }
    });

    categoryLabel.textContent = totalMatches > 0 ? "Результаты поиска" : "Ничего не найдено";
    categoryMeta.textContent =
      totalMatches > 0
        ? `${totalMatches} позиций в ${matchedPaneCount} разделах`
        : "Попробуйте другое название блюда, напитка или раздела";

    if (globalEmpty) {
      globalEmpty.hidden = totalMatches !== 0;
    }

    updateQuickButtons("", matchedCategories);
  }

  function clearSearchAndSync() {
    if (searchInput.value.trim() !== "") {
      searchInput.value = "";
    }
    window.requestAnimationFrame(resetSearchMode);
  }

  quickButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const targetTab = button.dataset.tabTarget;
      const actualTabButton = tabButtons.find((tabButton) => tabButton.dataset.tab === targetTab);

      if (actualTabButton) {
        clearSearchAndSync();
        actualTabButton.click();
      }
    });
  });

  tabButtons.forEach((button) => {
    button.addEventListener("click", () => {
      if (searchInput.value.trim() !== "") {
        clearSearchAndSync();
      } else {
        window.requestAnimationFrame(resetSearchMode);
      }
    });
  });

  searchInput.addEventListener("input", applyGlobalSearch);

  resetSearchMode();
});
