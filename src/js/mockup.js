// BitsAndBytesMenuPlanner — mockup interactivity only (Phase 2, no backend).
// Everything here is client-side state that resets on reload; it exists to
// let look-and-feel/interactions be reviewed before any real logic is built.

if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("service-worker.js").catch(() => {
    // Insecure context (e.g. plain HTTP on a non-localhost host) or
    // unsupported browser — the app works the same either way, it just
    // won't be installable to a home screen.
  });
}

(function () {
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

  document.querySelectorAll(".check-item input[type=checkbox]").forEach((box) => {
    box.addEventListener("change", () => {
      box.closest(".check-item").classList.toggle("checked", box.checked);
    });
  });
})();
