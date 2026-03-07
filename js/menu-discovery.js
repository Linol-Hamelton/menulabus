document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("menuQuickSearch");
  const globalEmpty = document.getElementById("menuGlobalNoResults");
  const tabButtons = Array.from(document.querySelectorAll(".menu-tabs-container .tab-btn"));
  const panes = Array.from(document.querySelectorAll(".menu-content .tab-pane"));
  const itemSelectors = [".cart-item", ".menu-item"];
  const searchState = {
    targetCategory: null,
    matchedCategories: new Set(),
    matchedItemsByCategory: new Map(),
  };

  if (!searchInput || !panes.length || !tabButtons.length) {
    return;
  }

  function getPaneItems(pane) {
    return Array.from(pane.querySelectorAll(itemSelectors.join(", ")));
  }

  function getItemText(item) {
    const category = item.closest(".tab-pane")?.id || "";
    const title = item.querySelector(".cart-item-title")?.textContent || item.querySelector("h3")?.textContent || "";
    const description = item.querySelector("p")?.textContent || item.querySelector(".cart-item-price")?.textContent || "";
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
      const isActive = pane.id === category;
      pane.classList.toggle("active", isActive);
      pane.classList.toggle("menu-search-pane-active", isActive && document.body.classList.contains("menu-search-mode"));
    });

    if (persist) {
      persistActiveCategory(category);
    }
  }

  function updateTabMatches(activeCategory, matchedCategories) {
    tabButtons.forEach((button) => {
      const category = button.dataset.tab || "";
      button.classList.toggle("is-match", matchedCategories.has(category) && category !== activeCategory);
    });
  }

  function clearAllItemVisibility() {
    panes.forEach((pane) => {
      getPaneItems(pane).forEach((item) => {
        item.style.display = "";
      });
      ensurePaneEmptyNode(pane).hidden = true;
      pane.classList.remove("menu-search-pane-active");
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

    const activeCategory = getActiveTabButton()?.dataset.tab || panes[0].id;
    updateTabMatches(activeCategory, new Set());
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

    panes.forEach((pane) => {
      const items = getPaneItems(pane);
      const matchedItems = items.filter((item) => getItemText(item).includes(query));
      if (matchedItems.length > 0) {
        searchState.matchedCategories.add(pane.id);
        searchState.matchedItemsByCategory.set(pane.id, matchedItems);
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

      updateTabMatches("", new Set());
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

      paneItems.forEach((item) => {
        if (!isActivePane) {
          item.style.display = "";
          return;
        }

        item.style.display = matchedItems.includes(item) ? "" : "none";
      });

      ensurePaneEmptyNode(pane).hidden = !isActivePane || matchedItems.length !== 0;
    });

    if (globalEmpty) {
      globalEmpty.hidden = true;
    }

    updateTabMatches(activeCategory, searchState.matchedCategories);
  }

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
