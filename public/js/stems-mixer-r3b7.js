(() => {
  "use strict";

  const root = document.getElementById("stems-player");
  if (!root) return;

  const master = document.getElementById("stems-master");
  const playButton = document.getElementById("stems-play");
  const stopButton = document.getElementById("stems-stop");
  const seek = document.getElementById("stems-seek");
  const timeLabel = document.getElementById("stems-time");
  const masterEnabled = document.getElementById("master-enabled");
  const masterVolume = document.getElementById("master-volume");
  const masterVolumeValue = document.getElementById("master-volume-value");
  const summary = document.getElementById("selection-summary");
  const prepare = document.getElementById("prepare-chords");

  const tracks = [...root.querySelectorAll("[data-track]")].map(row => ({
    row,
    audio: row.querySelector("[data-audio]"),
    mute: row.querySelector("[data-mute]"),
    solo: row.querySelector("[data-solo]"),
    volume: row.querySelector("[data-volume]"),
    volumeValue: row.querySelector("[data-volume-value]"),
    chord: row.querySelector("[data-chord-input]"),
    noChord: row.querySelector("[data-no-chord-input]"),
    muted: false,
    soloed: false,
    artifactId: row.dataset.artifactId || "",
    role: row.dataset.role || "",
    group: row.dataset.group || "other",
  }));

  let playing = false;
  let syncing = false;
  let duration = 0;

  function fmt(value) {
    value = Number.isFinite(value) ? Math.max(0, value) : 0;
    const m = Math.floor(value / 60);
    const s = Math.floor(value % 60);
    const ms = Math.floor((value - Math.floor(value)) * 1000);
    return String(m).padStart(2, "0") + ":" +
      String(s).padStart(2, "0") + "." +
      String(ms).padStart(3, "0");
  }

  function anySolo() {
    return tracks.some(t => t.soloed);
  }

  function applyMix() {
    const soloMode = anySolo();
    for (const track of tracks) {
      const audible = !track.muted && (!soloMode || track.soloed);
      const gain = Number(track.volume.value || 0) / 100;
      track.audio.volume = audible ? gain : 0;
      track.mute.classList.toggle("active", track.muted);
      track.solo.classList.toggle("active", track.soloed);
      track.volumeValue.textContent = Math.round(gain * 100) + "%";
    }

    const masterGain = Number(masterVolume.value || 0) / 100;
    master.volume = masterEnabled.checked ? masterGain : 0;
    masterVolumeValue.textContent = Math.round(masterGain * 100) + "%";
  }

  function clock() {
    if (master && Number.isFinite(master.duration) && master.duration > 0) return master;
    return tracks.length ? tracks[0].audio : null;
  }

  function knownDuration() {
    const c = clock();
    if (c && Number.isFinite(c.duration) && c.duration > 0) return c.duration;
    for (const track of tracks) {
      if (Number.isFinite(track.audio.duration) && track.audio.duration > 0) {
        return track.audio.duration;
      }
    }
    return 0;
  }

  function updatePosition() {
    const c = clock();
    if (!c) return;
    duration = knownDuration();
    const current = Number.isFinite(c.currentTime) ? c.currentTime : 0;
    if (!syncing && duration > 0) {
      seek.value = String(Math.round((current / duration) * 1000));
    }
    timeLabel.textContent = fmt(current) + " / " + fmt(duration);
  }

  function setTime(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    if (master && Number.isFinite(master.duration)) master.currentTime = Math.min(value, master.duration || value);
    for (const track of tracks) {
      if (Number.isFinite(track.audio.duration)) {
        track.audio.currentTime = Math.min(value, track.audio.duration || value);
      }
    }
    updatePosition();
  }

  async function start() {
    if (!tracks.length) return;
    applyMix();

    const c = clock();
    const startAt = c ? c.currentTime : 0;
    setTime(startAt);

    const promises = [];
    if (master) promises.push(master.play().catch(() => null));
    for (const track of tracks) promises.push(track.audio.play().catch(() => null));
    await Promise.all(promises);

    playing = true;
    playButton.textContent = "❚❚ Pause";
  }

  function pause() {
    if (master) master.pause();
    for (const track of tracks) track.audio.pause();
    playing = false;
    playButton.textContent = "▶ Lire";
  }

  function stop() {
    pause();
    setTime(0);
  }

  function syncSlaves() {
    if (!playing) return;
    const c = clock();
    if (!c) return;
    const t = c.currentTime;

    for (const track of tracks) {
      if (track.audio === c || track.audio.paused) continue;
      const drift = track.audio.currentTime - t;
      if (Math.abs(drift) > 0.075) {
        track.audio.currentTime = t;
      }
    }

    if (master && master !== c && !master.paused) {
      const drift = master.currentTime - t;
      if (Math.abs(drift) > 0.075) master.currentTime = t;
    }
  }

  function updateSelectionSummary() {
    summary.innerHTML = tracks.map(track => {
      const chord = track.chord.checked ? "✓" : "—";
      const noChord = track.noChord.checked ? "✓" : "—";
      return `<div class="selection-row">
        <div><strong>${escapeHtml(track.role)}</strong><br><code>${escapeHtml(track.artifactId)}</code></div>
        <div>${chord}</div>
        <div>${noChord}</div>
      </div>`;
    }).join("");

    const params = new URLSearchParams();
    params.set("song", new URL(prepare.href, location.href).searchParams.get("song") || "");
    params.set("parent_stems_run_id", root.dataset.runId || "");
    for (const track of tracks) {
      if (track.chord.checked) params.append("chord_inputs[]", track.artifactId);
      if (track.noChord.checked) params.append("no_chord_inputs[]", track.artifactId);
    }
    prepare.href = root.dataset.chordsUrl + "&" + params.toString();
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, ch => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;"
    })[ch]);
  }

  playButton.addEventListener("click", () => playing ? pause() : start());
  stopButton.addEventListener("click", stop);

  seek.addEventListener("input", () => {
    syncing = true;
    const d = knownDuration();
    if (d > 0) timeLabel.textContent = fmt((Number(seek.value) / 1000) * d) + " / " + fmt(d);
  });
  seek.addEventListener("change", () => {
    const d = knownDuration();
    if (d > 0) setTime((Number(seek.value) / 1000) * d);
    syncing = false;
  });

  masterEnabled.addEventListener("change", applyMix);
  masterVolume.addEventListener("input", applyMix);

  for (const track of tracks) {
    track.mute.addEventListener("click", () => {
      track.muted = !track.muted;
      applyMix();
    });
    track.solo.addEventListener("click", () => {
      track.soloed = !track.soloed;
      applyMix();
    });
    track.volume.addEventListener("input", applyMix);
    track.chord.addEventListener("change", updateSelectionSummary);
    track.noChord.addEventListener("change", updateSelectionSummary);
    track.audio.addEventListener("loadedmetadata", updatePosition);
  }

  if (master) {
    master.addEventListener("loadedmetadata", updatePosition);
    master.addEventListener("ended", stop);
  }

  applyMix();
  updateSelectionSummary();
  updatePosition();

  setInterval(() => {
    syncSlaves();
    updatePosition();
  }, 100);
})();
