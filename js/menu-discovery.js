document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("menuQuickSearch");
  const categoryLabel = document.getElementById("menuActiveCategoryLabel");
  const categoryMeta = document.getElementById("menuActiveCategoryMeta");
  const quickButtons = Array.from(document.querySelectorAll(".menu-quickcat-btn"));

  if (!searchInput || !categoryLabel || !categoryMeta) {
    return;
  }

  const itemSelectors = [".cart-item", ".menu-item"];

  function getActiveTabButton() {
    return document.querySelector(".menu-tabs-container .tab-btn.active");
  }

  function getActivePane() {
    return document.querySelector(".menu-content .tab-pane.active");
  }

  function getPaneItems(pane) {
    if (!pane) {
      return [];
    }

    return Array.from(
      pane.querySelectorAll(itemSelectors.join(", "))
    );
  }

  function getItemText(item) {
    const title =
      item.querySelector(".cart-item-title")?.textContent ||
      item.querySelector("h3")?.textContent ||
      "";
    const description =
      item.querySelector("p")?.textContent ||
      item.querySelector(".cart-item-price")?.textContent ||
      "";

    return `${title} ${description}`.trim().toLowerCase();
  }

  function ensureNoResultsNode(pane) {
    if (!pane) {
      return null;
    }

    let emptyState = pane.querySelector(".menu-no-results");
    if (!emptyState) {
      emptyState = document.createElement("div");
      emptyState.className = "menu-no-results";
      emptyState.textContent = "По этому запросу в выбранной категории пока ничего не найдено.";
      emptyState.hidden = true;
      pane.appendChild(emptyState);
    }

    return emptyState;
  }

  function updateQuickButtons(activeCategory) {
    quickButtons.forEach((button) => {
      button.classList.toggle(
        "is-active",
        button.dataset.tabTarget === activeCategory
      );
    });
  }

  function applyFilter() {
    const pane = getActivePane();
    const activeButton = getActiveTabButton();
    const query = searchInput.value.trim().toLowerCase();
    const items = getPaneItems(pane);
    const emptyState = ensureNoResultsNode(pane);
    let visibleCount = 0;

    items.forEach((item) => {
      const matches = query === "" || getItemText(item).includes(query);
      item.style.display = matches ? "" : "none";
      if (matches) {
        visibleCount += 1;
      }
    });

    const activeCategory = activeButton?.textContent?.trim() || "Категория";
    categoryLabel.textContent = activeCategory;
    categoryMeta.textContent =
      query === ""
        ? `${visibleCount} позиций в текущем разделе`
        : `${visibleCount} позиций по запросу`;

    if (emptyState) {
      emptyState.hidden = visibleCount !== 0;
    }

    updateQuickButtons(activeButton?.dataset.tab || "");
  }

  quickButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const targetTab = button.dataset.tabTarget;
      const actualTabButton = Array.from(
        document.querySelectorAll(".menu-tabs-container .tab-btn")
      ).find((tabButton) => tabButton.dataset.tab === targetTab);

      if (actualTabButton) {
        actualTabButton.click();
      }
    });
  });

  searchInput.addEventListener("input", applyFilter);
  document.querySelectorAll(".menu-tabs-container .tab-btn").forEach((button) => {
    button.addEventListener("click", () => {
      window.setTimeout(applyFilter, 0);
    });
  });

  applyFilter();
});
