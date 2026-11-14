$(function () {
  var $root = $('#ezs-performance-panel');
  if (!$root.length || window.ezsGpuMonitorBooted) { return; }
  window.ezsGpuMonitorBooted = true;

  var url = $root.attr('data-telemetry-url');
  var history = [], MAX = 60, timer = null, stopped = false;

  function fmt(v, d) {
    return (v === null || v === undefined || v === '' || !Number.isFinite(Number(v))) ? '—' : Number(v).toFixed(d || 0);
  }
  function mark(s, ok) {
    $root.find('[data-perf-status]').text(s).toggleClass('perf-live-on', !!ok);
  }
  function plot(key, w, h, lo, hi) {
    var $line = $root.find('[data-perf-line="' + key + '"]'), $area = $root.find('[data-perf-area="' + key + '"]');
    if (!$line.length || !$area.length) { return; }
    if (history.length < 2) { $line.attr('points', ''); $area.attr('points', ''); return; }
    var span = Math.max(0.0001, hi - lo);
    var pts = $.map(history, function (r, i) {
      var val = Math.min(hi, Math.max(lo, Number(r[key]) || 0));
      return (i / (MAX - 1) * w).toFixed(1) + ',' + (h - 2 - ((val - lo) / span) * (h - 4)).toFixed(1);
    }).join(' ');
    $line.attr('points', pts);
    $area.attr('points', '0,' + h + ' ' + pts + ' ' + ((history.length - 1) / (MAX - 1) * w).toFixed(1) + ',' + h);
  }
  function render(data) {
    if (!data || data.available !== true || !data.gpu) { mark('Télémétrie indisponible · réponse invalide', false); return; }
    var g = data.gpu, mem = fmt(g.memory_used_mib) + ' / ' + fmt(g.memory_total_mib) + ' MiB';
    $root.find('[data-perf-name]').text(g.name || 'NVIDIA');
    $root.find('[data-perf-util]').text(fmt(g.utilization_percent) + ' %');
    $root.find('[data-perf-memory]').text(fmt(g.memory_percent) + ' %');
    $root.find('[data-perf-memory-detail]').text(mem);
    $root.find('[data-perf-power]').text(fmt(g.power_w, 1) + ' W');
    $root.find('[data-perf-power-detail]').text('limite ' + fmt(g.power_limit_w, 1) + ' W');
    $root.find('[data-perf-temp]').text(fmt(g.temperature_c) + ' °C');
    $root.find('[data-perf-temp-detail]').text('20–100 °C');
    $root.find('[data-perf-clock]').text(fmt(g.sm_clock_mhz) + ' MHz');
    $root.find('[data-perf-clock-detail]').text(g.pstate || '—');
    $root.find('[data-stat-util]').text(fmt(g.utilization_percent) + ' %');
    $root.find('[data-stat-memory]').text(mem);
    $root.find('[data-stat-power]').text(fmt(g.power_w, 1) + ' / ' + fmt(g.power_limit_w, 1) + ' W');
    $root.find('[data-stat-temp]').text(fmt(g.temperature_c) + ' °C');
    $root.find('[data-stat-clock]').text(fmt(g.sm_clock_mhz) + ' MHz');
    $root.find('[data-stat-state]').text(g.pstate || '—');

    if (Array.isArray(data.history) && data.history.length) {
      history = data.history.slice(-MAX);
    } else {
      history.push({ util: g.utilization_percent, memory: g.memory_percent, power: g.power_w, temp: g.temperature_c, clock: g.sm_clock_mhz });
      history = history.slice(-MAX);
    }
    var maxClock = 300;
    $.each(history, function (_, x) { maxClock = Math.max(maxClock, Number(x.clock) || 0); });
    plot('util', 360, 120, 0, 100);
    plot('memory', 174, 62, 0, 100);
    plot('power', 174, 62, 0, Math.max(1, Number(g.power_limit_w) || Number(g.power_w) || 1));
    plot('temp', 174, 62, 20, 100);
    plot('clock', 174, 62, 0, maxClock * 1.05);
    mark('Télémétrie active', true);
  }
  function tick() {
    if (stopped) { return; }
    if (document.hidden) { timer = setTimeout(tick, 2000); return; }
    $.ajax({ url: url, method: 'GET', dataType: 'json', cache: false })
      .done(render)
      .fail(function (xhr) { mark('Télémétrie indisponible · HTTP ' + (xhr.status || 'ERR'), false); })
      .always(function () { if (!stopped) { timer = setTimeout(tick, 2000); } });
  }
  $(window).on('pagehide', function () { stopped = true; clearTimeout(timer); });
  $(document).on('visibilitychange', function () { if (!document.hidden && !stopped) { clearTimeout(timer); tick(); } });
  if (!url) { mark('Route télémétrie absente', false); return; }
  mark('Connexion télémétrie…', false);
  tick();
});