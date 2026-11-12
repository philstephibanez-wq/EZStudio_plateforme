(() => {
  "use strict";

  const audio = document.getElementById("master-audio");
  const slider = document.getElementById("mp3-volume");
  const value = document.getElementById("mp3-volume-value");
  const enabled = document.getElementById("mp3-enabled");

  if (!audio || !slider) return;

  function applyVolume() {
    const raw = Number(slider.value);
    const volume = Number.isFinite(raw) ? Math.max(0, Math.min(1, raw / 100)) : 1;
    audio.volume = volume;
    if (value) value.textContent = String(slider.value) + "%";
  }

  slider.addEventListener("input", applyVolume);
  slider.addEventListener("change", applyVolume);

  if (enabled) {
    enabled.addEventListener("change", () => {
      audio.muted = !enabled.checked;
    });
    audio.muted = !enabled.checked;
  }

  applyVolume();
})();
