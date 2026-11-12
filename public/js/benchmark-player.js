(() => {
  "use strict";

  const audio = document.getElementById("master-audio");
  if (!audio) return;

  const audioAvailable = window.EZCHORDS_AUDIO_AVAILABLE === true;
  const playButton = document.getElementById("master-play");
  const stopButton = document.getElementById("master-stop");
  const testButton = document.getElementById("audio-test");
  const mp3Enabled = document.getElementById("mp3-enabled");
  const state = document.getElementById("player-state");
  const synthState = document.getElementById("synth-state");
  const displayLevel = document.getElementById("chord-display-level");
  const mp3Volume = document.getElementById("mp3-volume");
  const midiVolume = document.getElementById("midi-volume");
  const mp3VolumeValue = document.getElementById("mp3-volume-value");
  const midiVolumeValue = document.getElementById("midi-volume-value");
  const algoRadios = Array.from(document.querySelectorAll('input[name="listen_algo"]'));
  const rows = Array.from(document.querySelectorAll("[data-algo-row]"));

  let audioContext = null;
  let instrument = null;
  let instrumentPromise = null;
  let synthMode = "fallback";
  let selectedAlgo = 0;
  let lastBeatKey = null;
  let activeNodes = [];
  let raf = 0;
  let internalPlaying = false;
  let internalStartPerf = 0;
  let internalStartTime = 0;

  const NOTE_INDEX = {
    C: 0, "C#": 1, Db: 1, D: 2, "D#": 3, Eb: 3,
    E: 4, Fb: 4, "E#": 5, F: 5, "F#": 6, Gb: 6,
    G: 7, "G#": 8, Ab: 8, A: 9, "A#": 10, Bb: 10, B: 11, Cb: 11
  };
  const INDEX_NAME = ["C","C#","D","D#","E","F","F#","G","G#","A","A#","B"];

  function midiGain() {
    return midiVolume ? Math.max(0, Math.min(1, Number(midiVolume.value) / 100)) : 0.8;
  }

  function syncVolumeLabels() {
    if (mp3VolumeValue && mp3Volume) mp3VolumeValue.textContent = mp3Volume.value + "%";
    if (midiVolumeValue && midiVolume) midiVolumeValue.textContent = midiVolume.value + "%";
  }

  function setState(text) {
    if (state) state.textContent = text;
  }

  function setSynthState(text) {
    if (synthState) synthState.textContent = "Synthé : " + text;
  }

  async function ensureAudioContextRunning() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) throw new Error("WebAudio indisponible");
    audioContext = audioContext || new Ctx();
    if (audioContext.state === "suspended") {
      await audioContext.resume();
    }
    return audioContext;
  }

  function nowTime() {
    if (audioAvailable) return audio.currentTime || 0;
    if (!internalPlaying) return internalStartTime;
    return internalStartTime + ((performance.now() - internalStartPerf) / 1000);
  }

  function isPlaying() {
    return audioAvailable ? !audio.paused : internalPlaying;
  }

  async function playClock() {
    if (audioAvailable) {
      await audio.play();
      return;
    }
    if (!internalPlaying) {
      internalStartPerf = performance.now();
      internalPlaying = true;
    }
  }

  function pauseClock() {
    if (audioAvailable) {
      audio.pause();
      return;
    }
    if (internalPlaying) {
      internalStartTime = nowTime();
      internalPlaying = false;
    }
  }

  function seekClock(t) {
    const value = Math.max(0, Number(t) || 0);
    if (audioAvailable) {
      audio.currentTime = value;
    } else {
      internalStartTime = value;
      if (internalPlaying) internalStartPerf = performance.now();
    }
  }

  function stopClock() {
    pauseClock();
    seekClock(0);
  }

  function selectedRow() {
    return rows.find(row => Number(row.dataset.algoRow) === selectedAlgo) || rows[0];
  }

  function allNotesOff() {
    for (const node of activeNodes) {
      try {
        if (node && typeof node.stop === "function") node.stop();
      } catch (_) {}
    }
    activeNodes = [];
  }

  function normalizeChordBase(label) {
    if (!label) return "";
    return label
      .replace(/\/[A-G](?:#|b)?$/, "")
      .replace(/\/[357]$/, "");
  }

  function simplifyChord(label, level) {
    if (!label || label === "." || label === "N" || label === "-") return label;

    if (level === "advanced") return label;

    const clean = normalizeChordBase(label);
    const m = clean.match(/^([A-G](?:#|b)?)(.*)$/);
    if (!m) return label;

    const root = m[1];
    const quality = m[2] || "";
    const minor = /^m(?!aj)/.test(quality);

    if (level === "beginner") {
      return root + (minor ? "m" : "");
    }

    if (/dim/.test(quality)) return root + "dim";
    if (/sus2/.test(quality)) return root + "sus2";
    if (/sus4/.test(quality)) return root + "sus4";
    if (/maj7/.test(quality)) return root + "maj7";
    if (/m7/.test(quality)) return root + "m7";
    if (/7/.test(quality)) return root + (minor ? "m7" : "7");
    return root + (minor ? "m" : "");
  }

  function currentDisplayLevel() {
    return displayLevel ? displayLevel.value : "intermediate";
  }

  function refreshChordLabels() {
    const level = currentDisplayLevel();
    document.querySelectorAll(".beat").forEach(el => {
      const raw = (el.dataset.token || "").trim();
      const label = el.querySelector(".chord-label");
      if (label) label.textContent = simplifyChord(raw, level);
    });
  }

  function chordToNotes(label) {
    if (!label || label === "." || label === "N") return [];

    const inversionMatch = label.match(/\/([357])$/);
    const inversionDegree = inversionMatch ? Number(inversionMatch[1]) : null;
    const cleaned = normalizeChordBase(label);

    const m = cleaned.match(/^([A-G](?:#|b)?)(.*)$/);
    if (!m) return [];

    const rootName = m[1];
    const quality = m[2] || "";
    const root = NOTE_INDEX[rootName];
    if (root === undefined) return [];

    let intervals = [0, 4, 7];
    if (/^m(?!aj)/.test(quality)) intervals = [0, 3, 7];
    if (/dim/.test(quality)) intervals = [0, 3, 6];
    if (/sus2/.test(quality)) intervals = [0, 2, 7];
    if (/sus4/.test(quality)) intervals = [0, 5, 7];

    if (/maj7/.test(quality)) intervals.push(11);
    else if (/m7/.test(quality) || /7/.test(quality)) intervals.push(10);

    if (inversionDegree) {
      const degreeToInterval = {
        3: /^m(?!aj)/.test(quality) ? 3 : 4,
        5: /dim/.test(quality) ? 6 : 7,
        7: /maj7/.test(quality) ? 11 : 10
      };
      const bassInterval = degreeToInterval[inversionDegree];
      if (bassInterval !== undefined) {
        intervals = [bassInterval - 12, ...intervals.filter(v => v !== bassInterval)];
      }
    }

    return intervals.map((interval, i) => {
      let semitone = root + interval;
      let octave = 4;
      while (semitone < 0) {
        semitone += 12;
        octave -= 1;
      }
      octave += Math.floor(semitone / 12);
      semitone = ((semitone % 12) + 12) % 12;
      if (i > 0 && octave < 4) octave = 4;
      return INDEX_NAME[semitone] + octave;
    });
  }

  function timelineBeats(row) {
    const measures = Array.from(row.querySelectorAll(".measure"));
    const beats = [];
    let previousRawChord = null;
    let previousMeasureDuration = null;
    const level = currentDisplayLevel();

    for (let i = 0; i < measures.length; i++) {
      const measure = measures[i];
      const beatEls = Array.from(measure.querySelectorAll(".beat"));
      if (!beatEls.length) continue;

      let start = Number(measure.dataset.start);
      if (!Number.isFinite(start)) start = 0;

      let nextStart = null;
      if (i + 1 < measures.length) {
        const candidate = Number(measures[i + 1].dataset.start);
        if (Number.isFinite(candidate) && candidate > start) nextStart = candidate;
      }

      let duration;
      if (nextStart !== null) {
        duration = nextStart - start;
        previousMeasureDuration = duration;
      } else if (previousMeasureDuration !== null) {
        duration = previousMeasureDuration;
      } else {
        duration = beatEls.length * 0.5;
      }

      const beatDuration = duration / beatEls.length;

      beatEls.forEach((el, beatIndex) => {
        const token = (el.dataset.token || "").trim();
        let rawChord = token;

        if (token === "-") rawChord = previousRawChord;
        else if (token === "." || token === "N" || token === "") rawChord = null;
        else previousRawChord = token;

        const playedChord = rawChord ? simplifyChord(rawChord, level) : null;

        beats.push({
          key: `${measure.dataset.measure}:${beatIndex}`,
          start: start + beatIndex * beatDuration,
          end: start + (beatIndex + 1) * beatDuration,
          chord: playedChord,
          el,
          measure
        });
      });
    }

    return beats;
  }

  function currentBeat(beats, t) {
    let lo = 0, hi = beats.length - 1, found = null;
    while (lo <= hi) {
      const mid = Math.floor((lo + hi) / 2);
      const b = beats[mid];
      if (t < b.start) hi = mid - 1;
      else {
        found = b;
        lo = mid + 1;
      }
    }
    return found;
  }

  function clearHighlight() {
    document.querySelectorAll(".beat.current").forEach(el => el.classList.remove("current"));
    document.querySelectorAll(".measure.current-measure").forEach(el => el.classList.remove("current-measure"));
  }

  function highlightBeat(beat, autoScroll = true) {
    clearHighlight();
    if (!beat) return;

    beat.el.classList.add("current");
    beat.measure.classList.add("current-measure");

    const timeline = beat.el.closest(".timeline");
    if (!timeline) return;

    const left = beat.el.offsetLeft;
    const right = left + beat.el.offsetWidth;
    const visibleLeft = timeline.scrollLeft;
    const visibleRight = visibleLeft + timeline.clientWidth;

    if (autoScroll && (left < visibleLeft + 80 || right > visibleRight - 80)) {
      timeline.scrollTo({
        left: Math.max(0, left - Math.round(timeline.clientWidth * 0.30)),
        behavior: "smooth"
      });
    }
  }

  function noteNameToFrequency(note) {
    const m = String(note).match(/^([A-G]#?)(-?\d+)$/);
    if (!m) return null;
    const names = {"C":0,"C#":1,"D":2,"D#":3,"E":4,"F":5,"F#":6,"G":7,"G#":8,"A":9,"A#":10,"B":11};
    const semitone = names[m[1]];
    if (semitone === undefined) return null;
    const octave = Number(m[2]);
    const midi = (octave + 1) * 12 + semitone;
    return 440 * Math.pow(2, (midi - 69) / 12);
  }

  function playFallback(notes, duration) {
    allNotesOff();
    const now = audioContext.currentTime;
    const stopAt = now + Math.max(0.10, Math.min(duration * 0.78, 0.70));

    activeNodes = notes.map(note => {
      const frequency = noteNameToFrequency(note);
      if (!frequency) return null;

      const osc = audioContext.createOscillator();
      const gain = audioContext.createGain();
      osc.type = "triangle";
      osc.frequency.value = frequency;

      gain.gain.setValueAtTime(0.0001, now);
      gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, 0.22 * midiGain()), now + 0.01);
      gain.gain.exponentialRampToValueAtTime(0.0001, stopAt);

      osc.connect(gain);
      gain.connect(audioContext.destination);
      osc.start(now);
      osc.stop(stopAt + 0.03);

      return { stop: () => { try { osc.stop(); } catch (_) {} } };
    }).filter(Boolean);
  }

  function ensureSoundfontInBackground() {
    if (instrument || instrumentPromise) return;
    instrumentPromise = new Promise(resolve => {
      const script = document.createElement("script");
      script.src = "https://cdn.jsdelivr.net/npm/soundfont-player@0.12.0/dist/soundfont-player.min.js";
      script.async = true;

      script.onload = () => {
        if (!window.Soundfont) {
          setSynthState("WebAudio actif");
          resolve(null);
          return;
        }

        window.Soundfont.instrument(audioContext, "acoustic_grand_piano", { soundfont: "MusyngKite" })
          .then(inst => {
            instrument = inst;
            synthMode = "soundfont";
            setSynthState("SoundFont piano prêt");
            resolve(inst);
          })
          .catch(() => {
            setSynthState("WebAudio actif");
            resolve(null);
          });
      };

      script.onerror = () => {
        setSynthState("WebAudio actif");
        resolve(null);
      };

      document.head.appendChild(script);
    });
  }

  async function primeSynth() {
    await ensureAudioContextRunning();
    synthMode = instrument ? "soundfont" : "fallback";
    setSynthState(instrument ? "SoundFont piano prêt" : "WebAudio actif · chargement SoundFont…");
    ensureSoundfontInBackground();
  }

  async function strikeChord(chord, duration) {
    if (!chord) {
      allNotesOff();
      return;
    }

    const notes = chordToNotes(chord);
    if (!notes.length) return;

    await ensureAudioContextRunning();

    if (synthMode === "soundfont" && instrument) {
      allNotesOff();
      const d = Math.max(0.10, Math.min(duration * 0.78, 0.70));
      activeNodes = notes.map(note => instrument.play(
        note,
        audioContext.currentTime,
        { duration: d, gain: midiGain() }
      )).filter(Boolean);
    } else {
      playFallback(notes, duration);
    }
  }

  function tick() {
    const row = selectedRow();
    if (!row) return;

    const beats = timelineBeats(row);
    const currentTime = nowTime();
    const beat = currentBeat(beats, currentTime);

    if (beat && isPlaying()) {
      highlightBeat(beat, true);
      if (beat.key !== lastBeatKey) {
        lastBeatKey = beat.key;
        strikeChord(beat.chord, beat.end - beat.start).catch(err => setSynthState(err.message || String(err)));
        setState(`${row.dataset.algoName} · ${beat.chord || "silence"} · ${currentTime.toFixed(2)} s`);
      }
    }

    raf = requestAnimationFrame(tick);
  }

  function selectAlgo(index) {
    selectedAlgo = Number(index);
    lastBeatKey = null;
    allNotesOff();
    clearHighlight();

    const radio = algoRadios.find(r => Number(r.value) === selectedAlgo);
    if (radio) radio.checked = true;

    const row = selectedRow();
    setState(row ? `Algorithme : ${row.dataset.algoName}` : "Algorithme sélectionné.");
  }

  algoRadios.forEach(radio => {
    radio.addEventListener("change", () => {
      if (radio.checked) selectAlgo(radio.value);
    });
  });

  document.querySelectorAll("[data-play-algo]").forEach(button => {
    button.addEventListener("click", async () => {
      selectAlgo(button.dataset.playAlgo);
      await primeSynth().catch(err => setSynthState(err.message || String(err)));
      if (!isPlaying()) {
        try {
          await playClock();
          playButton.textContent = "❚❚ Pause";
        } catch (err) {
          setState("Erreur lecture MP3 : " + (err.message || String(err)));
        }
      }
    });
  });

  mp3Enabled.addEventListener("change", () => {
    if (audioAvailable) audio.muted = !mp3Enabled.checked;
  });

  playButton.addEventListener("click", async () => {
    await primeSynth().catch(err => setSynthState(err.message || String(err)));
    if (!isPlaying()) {
      try {
        await playClock();
        playButton.textContent = "❚❚ Pause";
      } catch (err) {
        setState("Erreur lecture MP3 : " + (err.message || String(err)));
      }
    } else {
      pauseClock();
      playButton.textContent = "▶ Lire";
      allNotesOff();
    }
  });

  stopButton.addEventListener("click", () => {
    stopClock();
    playButton.textContent = "▶ Lire";
    lastBeatKey = null;
    allNotesOff();
    clearHighlight();

    document.querySelectorAll(".timeline").forEach(timeline => {
      timeline.scrollLeft = 0;
    });

    document.querySelectorAll(".measure.current-measure").forEach(el => {
      el.classList.remove("current-measure");
    });
    document.querySelectorAll(".beat.current").forEach(el => {
      el.classList.remove("current");
    });

    setState("Arrêt · timelines remises à zéro.");
  });

  testButton.addEventListener("click", async () => {
    try {
      await primeSynth();
      playFallback(["A4"], 0.45);
      setSynthState("test WebAudio joué");
    } catch (err) {
      setSynthState("erreur test : " + (err.message || String(err)));
    }
  });

  if (audioAvailable) {
    audio.addEventListener("play", () => {
      playButton.textContent = "❚❚ Pause";
      lastBeatKey = null;
      primeSynth().catch(() => {});
    });
    audio.addEventListener("pause", () => {
      playButton.textContent = "▶ Lire";
      allNotesOff();
    });
    audio.addEventListener("seeked", () => {
      lastBeatKey = null;
      allNotesOff();

      const row = selectedRow();
      if (row) {
        const beat = currentBeat(timelineBeats(row), audio.currentTime);
        if (beat) {
          highlightBeat(beat, false);
          setState(`${row.dataset.algoName} · ${beat.chord || "silence"} · ${audio.currentTime.toFixed(2)} s`);
        }
      }
    });
    audio.addEventListener("ended", () => {
      lastBeatKey = null;
      allNotesOff();
      clearHighlight();
    });
    audio.addEventListener("loadedmetadata", () => {
      setState(`MP3 prêt · ${audio.duration.toFixed(2)} s`);
    });
    audio.addEventListener("error", () => {
      const code = audio.error ? audio.error.code : "?";
      setState(`Erreur MP3 (code ${code})`);
    });
  }

  document.querySelectorAll(".beat").forEach(beatEl => {
    beatEl.addEventListener("click", () => {
      const row = beatEl.closest("[data-algo-row]");
      if (!row) return;

      selectAlgo(row.dataset.algoRow);
      const beats = timelineBeats(row);
      const target = beats.find(b => b.el === beatEl);
      if (!target) return;

      seekClock(target.start);
      lastBeatKey = null;
      highlightBeat(target);
    });
  });

  displayLevel.addEventListener("change", () => {
    if (mp3Volume) {
    mp3Volume.addEventListener("input", () => {
      audio.volume = Number(mp3Volume.value) / 100;
      syncVolumeLabels();
    });
  }

  if (midiVolume) {
    midiVolume.addEventListener("input", syncVolumeLabels);
  }

  if (audioAvailable && mp3Volume) {
    audio.volume = Number(mp3Volume.value) / 100;
  }
  syncVolumeLabels();

  refreshChordLabels();
    lastBeatKey = null;
    allNotesOff();
    if (isPlaying()) {
      const row = selectedRow();
      const beat = currentBeat(timelineBeats(row), nowTime());
      if (beat) strikeChord(beat.chord, beat.end - beat.start).catch(() => {});
    }
  });

  refreshChordLabels();
  if (algoRadios.length) selectAlgo(algoRadios[0].value);
  if (audioAvailable) audio.muted = !mp3Enabled.checked;
  raf = requestAnimationFrame(tick);

  window.addEventListener("beforeunload", () => {
    cancelAnimationFrame(raf);
    allNotesOff();
  });
})();
