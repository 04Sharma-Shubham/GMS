(() => {
  const base = (document.body.dataset.base || "/").replace(/\/$/, "");
  const path = (part) => `${base}/${part.replace(/^\//, "")}`;
  const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  document.body.classList.add("js");
  document.querySelector("[data-sidebar]")?.addEventListener("click", () => document.body.classList.toggle("nav-open"));
  document.body.addEventListener("click", (event) => {
    if (event.target === document.body && document.body.classList.contains("nav-open")) {
      document.body.classList.remove("nav-open");
    }
  });

  if (window.lucide) lucide.createIcons();
  if (!reduce && window.gsap) {
    gsap.from(".stat-card", { y: 14, opacity: 0, duration: 0.45, stagger: 0.05, ease: "power2.out", clearProps: "opacity,transform" });
  }
  if (!reduce && window.AOS) {
    document.body.classList.add("aos-on");
    AOS.init({ once: true, duration: 550, offset: 24 });
  }
  setTimeout(() => {
    document.querySelectorAll("[data-aos]").forEach((el) => {
      el.style.opacity = "1";
      el.style.transform = "none";
    });
  }, 1200);

  const stack = document.getElementById("toasts");
  document.querySelectorAll(".flash").forEach((flash) => {
    if (!stack) return;
    const toast = document.createElement("div");
    toast.className = "toast";
    toast.textContent = flash.textContent.trim();
    stack.appendChild(toast);
    setTimeout(() => toast.remove(), 4200);
  });

  document.querySelectorAll("[data-count]").forEach((node) => {
    const target = Number(node.dataset.count || 0);
    if (reduce || Number.isNaN(target)) return;
    const started = performance.now();
    const tick = (now) => {
      const progress = Math.min(1, (now - started) / 700);
      node.textContent = new Intl.NumberFormat().format(Math.round(target * progress));
      if (progress < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });

  const search = document.querySelector("[data-member-search]");
  const box = search?.parentElement.querySelector(".search-results");
  let timer;
  search?.addEventListener("input", () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const q = search.value.trim();
      if (!box || q.length < 2) {
        if (box) box.hidden = true;
        return;
      }
      const response = await fetch(`${path("api/members")}?q=${encodeURIComponent(q)}`);
      const data = await response.json();
      box.innerHTML = (data.results || []).map((row) => `<a href="${row.url}"><strong>${escapeHtml(row.name)}</strong><span>${escapeHtml(row.code)} · ${escapeHtml(row.phone)}</span></a>`).join("") || "<a>No matching members</a>";
      box.hidden = false;
    }, 180);
  });
  document.addEventListener("click", (event) => {
    if (box && !search?.parentElement.contains(event.target)) box.hidden = true;
  });

  document.querySelectorAll("[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      if (!confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  document.querySelectorAll("[data-open]").forEach((button) => {
    button.addEventListener("click", () => {
      const layer = document.querySelector(button.dataset.open);
      layer?.classList.add("is-open");
    });
  });
  document.querySelectorAll("[data-close]").forEach((button) => {
    button.addEventListener("click", () => button.closest(".modal-layer")?.classList.remove("is-open"));
  });

  window.gms = {
    path,
    csrf: document.querySelector('meta[name="csrf"]')?.content || "",
    async post(url, body) {
      const response = await fetch(path(url), {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": this.csrf },
        body: JSON.stringify(body),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || "Request failed");
      return data;
    },
  };

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char]));
  }
})();
