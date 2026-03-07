document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("menuQuickSearch");
  const categoryLabel = document.getElementById("menuActiveCategoryLabel");
  const categoryMeta = document.getElementById("menuActiveCategoryMeta");
  const globalEmpty = document.getElementById("menuGlobalNoResults");
  const quickButtons = Array.from(document.querySelectorAll(".menu-quickcat-btn"));
  const tabButtons = Array.from(document.querySelectorAll(".menu-tabs-container .tab-btn"));
  const panes = Array.from(document.querySelectorAll(".menu-content .tab-pane"));
  const itemSelectors = [".cart-item", ".menu-item"];
  const searchState = {
    targetCategory: null,
    matchedCategories: new Set(),
    matchedItemsByCategory: new Map(),
  };

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

  function persistActiveCategory(category) {
    try {
      localStorage.setItem("activeMenuCategory", category);
    } catch (error) {
      // ignore storage issues
    }
    document.cookie = `activeMenuCategory=${encodeURIComponent(category)}; path=/; max-age=31536000`;
  }

  function getActiveTabButton() {
    return document.querySelector(".menu-tabs-container .tab-btn.active") || tabButtons[0];
  }

  function activateCategory(category, persist = true) {
    tabButtons.forEach((button) => {
      button.classList.toggle("active", button.dataset.tab === category);
    });

    panes.forEach((pane) => {
      pane.classList.toggle("active", pane.id === category);
      pane.classList.toggle("menu-search-pane-active", pane.id === category && document.body.classList.contains("menu-search-mode"));
      pane.dataset.searchTitle = pane.id || "";
    });

    if (persist) {
      persistActiveCategory(category);
    }
  }

  function updateQuickButtons(activeCategory, matchedCategories) {
    quickButtons.forEach((button) => {
      const category = button.dataset.tabTarget || "";
      button.classList.toggle("is-active", activeCategory !== "" && category === activeCategory);
      button.classList.toggle("is-match", matchedCategories.has(category));
    });
  }

  function clearAllItemVisibility() {
    panes.forEach((pane) => {
      getPaneItems(pane).forEach((item) => {
        item.style.display = "";
      });
      ensurePaneEmptyNode(pane).hidden = true;
      pane.classList.remove("menu-search-pane-active");
      pane.removeAttribute("data-search-title");
    });
  }

  function resetSearchMode() {
    document.body.classList.remove("menu-search-mode");
    searchState.targetCategory = null;
    searchState.matchedCategories = new Set();
    searchState.matchedItemsByCategory = new Map();

    clearAllItemVisibility();

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

  function formatResultMeta(activeCount, matchedCount) {
    if (matchedCount <= 1) {
      return `${activeCount} позиций по запросу`;
    }

    const extra = matchedCount - 1;
    const suffix = extra === 1 ? "разделе" : (extra >= 2 && extra <= 4 ? "разделах" : "разделах");
    return `${activeCount} позиций в разделе · ещё ${extra} в других ${suffix}`;
  }

  function applyGlobalSearch(preferredCategory = null) {
    const query = searchInput.value.trim().toLowerCase();
    if (query === "") {
      resetSearchMode();
      return;
    }

    document.body.classList.add("menu-search-mode");
    searchState.matchedCategories = new Set();
    searchState.matchedItemsByCategory = new Map();

    let totalMatches = 0;

    panes.forEach((pane) => {
      const items = getPaneItems(pane);
      const matchedItems = items.filter((item) => getItemText(item).includes(query));
      if (matchedItems.length > 0) {
        searchState.matchedCategories.add(pane.id);
        searchState.matchedItemsByCategory.set(pane.id, matchedItems);
        totalMatches += matchedItems.length;
      }
      ensurePaneEmptyNode(pane).hidden = true;
    });

    const matchedCategories = Array.from(searchState.matchedCategories);
    const currentActiveCategory = getActiveTabButton()?.dataset.tab || panes[0].id;

    if (matchedCategories.length === 0) {
      panes.forEach((pane) => {
        getPaneItems(pane).forEach((item) => {
          item.style.display = "none";
        });
        pane.classList.remove("menu-search-pane-active");
      });

      if (globalEmpty) {
        globalEmpty.hidden = false;
      }

      categoryLabel.textContent = "Ничего не найдено";
      categoryMeta.textContent = "Попробуйте другое название блюда, напитка или раздела";
      updateQuickButtons("", new Set());
      return;
    }

    const activeCategory =
      (preferredCategory && searchState.matchedCategories.has(preferredCategory) && preferredCategory) ||
      (searchState.targetCategory && searchState.matchedCategories.has(searchState.targetCategory) && searchState.targetCategory) ||
      (searchState.matchedCategories.has(currentActiveCategory) ? currentActiveCategory : matchedCategories[0]);

    searchState.targetCategory = activeCategory;
    activateCategory(activeCategory, false);

    panes.forEach((pane) => {
      const paneItems = getPaneItems(pane);
      const isActivePane = pane.id === activeCategory;
      const matchedItems = searchState.matchedItemsByCategory.get(pane.id) || [];

      pane.classList.toggle("menu-search-pane-active", isActivePane);
      pane.dataset.searchTitle = pane.id || "";

      paneItems.forEach((item) => {
        if (!isActivePane) {
          item.style.display = "";
          return;
        }

        item.style.display = matchedItems.includes(item) ? "" : "none";
      });

      const emptyState = ensurePaneEmptyNode(pane);
      emptyState.hidden = !isActivePane || matchedItems.length !== 0;
    });

    if (globalEmpty) {
      globalEmpty.hidden = true;
    }

    categoryLabel.textContent = activeCategory;
    categoryMeta.textContent = formatResultMeta(
      searchState.matchedItemsByCategory.get(activeCategory)?.length || 0,
      matchedCategories.length
    );
    updateQuickButtons(activeCategory, searchState.matchedCategories);
  }

  quickButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const targetTab = button.dataset.tabTarget;
      if (searchInput.value.trim() !== "") {
        if (searchState.matchedCategories.has(targetTab)) {
          searchState.targetCategory = targetTab;
          applyGlobalSearch(targetTab);
          return;
        }

        searchInput.value = "";
        resetSearchMode();
      }

      activateCategory(targetTab, true);
      resetSearchMode();
    });
  });

  tabButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const targetTab = button.dataset.tab || "";

      if (searchInput.value.trim() !== "") {
        if (searchState.matchedCategories.has(targetTab)) {
          searchState.targetCategory = targetTab;
          applyGlobalSearch(targetTab);
          return;
        }

        searchInput.value = "";
        activateCategory(targetTab, true);
        resetSearchMode();
        return;
      }

      activateCategory(targetTab, true);
      window.requestAnimationFrame(resetSearchMode);
    });
  });

  searchInput.addEventListener("input", () => {
    searchState.targetCategory = null;
    applyGlobalSearch();
  });

  resetSearchMode();
});
