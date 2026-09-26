// BitsAndBytesMenuPlanner — mockup interactivity only (Phase 2, no backend).
// Everything here is client-side state that resets on reload; it exists to
// let look-and-feel/interactions be reviewed before any real logic is built.

(function () {
  const root = document.documentElement;
  const savedTheme = localStorage.getItem("bnb-mock-theme");
  if (savedTheme) root.setAttribute("data-theme", savedTheme);

  const savedHue = localStorage.getItem("bnb-mock-hue");
  if (savedHue) root.style.setProperty("--accent-h", savedHue);
  const hueSlider = document.getElementById("hue-slider");
  if (hueSlider) {
    hueSlider.value = savedHue || getComputedStyle(root).getPropertyValue("--accent-h").trim();
    hueSlider.addEventListener("input", () => {
      root.style.setProperty("--accent-h", hueSlider.value);
      localStorage.setItem("bnb-mock-hue", hueSlider.value);
    });
  }

  // Role preview: "parent" sees edit/reorder controls, "guest" is read-only.
  // Set by the Log In / Continue as Guest buttons on login.html.
  const role = localStorage.getItem("bnb-mock-role") || "parent";
  document.body.classList.toggle("is-guest", role === "guest");
  document.querySelectorAll(".parent-only").forEach((el) => {
    el.style.display = role === "parent" ? "" : "none";
  });
  if (role === "guest") {
    document.querySelectorAll(".member-only").forEach((el) => {
      el.style.display = "none";
    });
  }
  document.querySelectorAll("[data-login]").forEach((btn) => {
    btn.addEventListener("click", () => {
      localStorage.setItem("bnb-mock-role", btn.getAttribute("data-login"));
    });
  });
  document.querySelectorAll("[data-logout]").forEach((btn) => {
    btn.addEventListener("click", () => {
      localStorage.removeItem("bnb-mock-role");
    });
  });

  document.querySelectorAll("[data-theme-choice]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const choice = btn.getAttribute("data-theme-choice");
      root.setAttribute("data-theme", choice);
      localStorage.setItem("bnb-mock-theme", choice);
      document.querySelectorAll("[data-theme-choice]").forEach((b) =>
        b.classList.toggle("active", b === btn)
      );
    });
  });

  document.querySelectorAll(".fav-btn").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      btn.classList.toggle("active");
      const filled = btn.classList.contains("active");
      btn.querySelector("svg").setAttribute("fill", filled ? "currentColor" : "none");
    });
  });

  document.querySelectorAll("[data-cooked-toggle]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const cooked = btn.getAttribute("data-cooked-toggle") !== "cooked";
      btn.setAttribute("data-cooked-toggle", cooked ? "cooked" : "not-cooked");
      btn.classList.toggle("badge-cooked", cooked);
      btn.classList.toggle("badge-not-cooked", !cooked);
      btn.querySelector(".label").textContent = cooked ? "Cooked" : "Not cooked";
    });
  });

  document.querySelectorAll(".check-item input[type=checkbox]").forEach((box) => {
    box.addEventListener("change", () => {
      box.closest(".check-item").classList.toggle("checked", box.checked);
    });
  });

  // Drag-and-drop reorder preview for "not cooked" meals in the week view.
  let dragged = null;
  document.querySelectorAll(".slot-line[draggable='true']").forEach((row) => {
    row.addEventListener("dragstart", () => {
      dragged = row;
      row.style.opacity = "0.4";
    });
    row.addEventListener("dragend", () => {
      row.style.opacity = "1";
    });
    row.addEventListener("dragover", (e) => e.preventDefault());
    row.addEventListener("drop", (e) => {
      e.preventDefault();
      if (!dragged || dragged === row) return;
      const parent = row.parentElement;
      const rows = Array.from(parent.querySelectorAll(".slot-line[draggable='true']"));
      if (rows.indexOf(dragged) < rows.indexOf(row)) {
        row.after(dragged);
      } else {
        row.before(dragged);
      }
    });
  });
})();
