(() => {
  document.querySelectorAll("[data-toggle-password]").forEach((button) => {
    button.addEventListener("click", () => {
      const input = button.parentElement?.querySelector("input");
      if (!input) return;
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      button.textContent = show ? "Hide" : "Show";
    });
  });

  const password = document.querySelector("[data-password]");
  const meter = document.querySelector("[data-strength]");
  if (password && meter) {
    const score = () => {
      const value = password.value;
      const strong = value.length >= 8 && /[A-Za-z]/.test(value) && /\d/.test(value);
      meter.classList.toggle("is-strong", strong);
      meter.classList.toggle("is-ok", !strong && value.length >= 4);
    };
    password.addEventListener("input", score);
    score();
  }

  const stage = document.querySelector(".auth-stage");
  if (!stage || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  const orbs = stage.querySelectorAll(".auth-orb");
  window.addEventListener("pointermove", (event) => {
    const x = event.clientX / window.innerWidth - 0.5;
    const y = event.clientY / window.innerHeight - 0.5;
    orbs.forEach((orb, index) => {
      const depth = (index + 1) * 14;
      orb.style.translate = `${x * depth}px ${y * depth}px`;
    });
  }, { passive: true });
})();
