document.addEventListener("DOMContentLoaded", function () {
  function toggleVisibility(toggleEl) {
    const targetId = toggleEl.getAttribute("data-target");
    const input = targetId
      ? document.getElementById(targetId)
      : toggleEl.closest(".form-group")?.querySelector(".password-input");

    if (!input) {
      return;
    }

    const isVisible = input.type === "text";
    input.type = isVisible ? "password" : "text";
    toggleEl.classList.toggle("is-visible", !isVisible);
    toggleEl.setAttribute("aria-label", isVisible ? "Показать пароль" : "Скрыть пароль");
  }

  document.querySelectorAll(".password-visibility").forEach(function (toggleEl) {
    toggleEl.addEventListener("click", function () {
      toggleVisibility(toggleEl);
    });

    toggleEl.addEventListener("keydown", function (event) {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        toggleVisibility(toggleEl);
      }
    });
  });
});
