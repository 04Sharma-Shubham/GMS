(() => {
  const reader = document.getElementById("qr-reader");
  const field = document.querySelector("[name='token']");
  if (!reader || !window.Html5Qrcode) return;
  const scanner = new Html5Qrcode("qr-reader");
  const start = document.getElementById("start-scan");
  start?.addEventListener("click", async () => {
    start.disabled = true;
    try {
      await scanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 220 }, (text) => {
        if (field) field.value = text;
        scanner.stop().catch(() => {});
        field?.form?.requestSubmit();
      });
    } catch (error) {
      start.disabled = false;
      alert("Camera access was blocked. Type or paste the member code instead.");
    }
  });
})();
