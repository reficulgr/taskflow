/* TaskFlow skill spider — clickable radar over the skill tree.
   Usage: render_skill_spider(containerId, data)
   data = {
     skills: [{id, name, parentId, archived}],
     minutes: {skillId: minutes},           // rolled-up totals for the selected range
     prevMinutes: {skillId: minutes}|null,  // preceding equal-length period (ghost)
     rings: [m1, m2, m3, m4]|null,          // fixed hour rings; null = All time (stars)
     rootColors: {skillName: '#hex'},       // color per root node (archetypes)
     initialZoom: skillId|null              // optional
   } */
(function () {
  'use strict';

  // Log mastery ladder for All time: 5★ = 1000h, each star a ×5.6 multiplier.
  // 1★=0h 2★≈4.6h 3★≈31h 4★≈177h 5★=1000h.
  function starsOf(minutes) {
    const h = (minutes || 0) / 60;
    return Math.min(5, 1 + 4 * Math.log(1 + h) / Math.log(1001));
  }

  // Log-scale radius for the fixed hour rings: each ring = double the previous,
  // equally spaced (rings[i] at (i+1)/4 of R). Center = rings[0]/2; values clamp
  // at the center below and at the rim above.
  function ladderRadius(min, rings, R) {
    const base = rings[0] / 2;
    const frac = Math.log2(Math.max(base, min) / base) / 4;
    return R * Math.max(0, Math.min(1, frac));
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function fmt(m) {
    const h = Math.floor(m / 60), r = m % 60;
    if (h > 0 && r > 0) return h + 'h ' + r + 'm';
    if (h > 0) return h + 'h';
    return r + 'm';
  }

  window.render_skill_spider = function (containerId, data) {
    const vp = document.getElementById(containerId);
    if (!vp) return null;

    const skills = data.skills || [];
    const minutes = data.minutes || {};
    let prevMinutes = data.prevMinutes || null;
    let rings = Array.isArray(data.rings) && data.rings.length === 4 ? data.rings.slice() : null;
    const rootColors = data.rootColors || {};
    const byId = new Map(), parentOf = new Map(), childrenOf = new Map();
    const roots = [];
    skills.forEach(s => {
      byId.set(s.id, s);
      // Multi-parent model: parentIds array (legacy single parentId supported).
      const pids = Array.isArray(s.parentIds) ? s.parentIds : (s.parentId !== null && s.parentId !== undefined ? [s.parentId] : []);
      parentOf.set(s.id, pids.length === 1 ? pids[0] : (pids.length === 0 ? null : pids[0]));
      if (pids.length === 0) { roots.push(s); return; }
      for (const p of pids) {
        if (!childrenOf.has(p)) childrenOf.set(p, []);
        childrenOf.get(p).push(s);
      }
    });

    // Resolve a root node's color for any descendant.
    function colorOf(id) {
      let cur = id, guard = 0, root = null;
      while (cur !== null && guard++ < 100) {
        const p = parentOf.get(cur);
        if (p === null || p === undefined) { root = cur; break; }
        cur = p;
      }
      const rootName = root !== null && byId.has(root) ? byId.get(root).name : null;
      return rootColors[rootName] || '#7a7a76';
    }

    // State per instance.
    let zoom = data.initialZoom != null && byId.has(data.initialZoom) ? data.initialZoom : null;

    const wrapper = document.createElement('div');
    wrapper.className = 'skill-spider';
    wrapper.innerHTML =
      '<div class="ss-crumbs flex items-center flex-wrap gap-1 text-sm mb-2"></div>' +
      '<div class="ss-stage relative bg-slate-900/60 border border-slate-700 rounded-lg" style="min-height: 380px;">' +
      '<svg class="w-full block" style="height: 380px;"></svg>' +
      '<p class="ss-empty text-center text-slate-400 pt-8 hidden">No skill-tagged time in this range.</p>' +
      '</div>' +
      '<p class="text-xs text-slate-500 mt-2">Solid = hours in the selected range, dashed ghost = the preceding equal period. Rings double inward-to-outward. On All time the chart shows lifetime mastery stars (5★ = 1000h). Click an axis to zoom into that branch · Esc or breadcrumb to zoom out.</p>';
    vp.appendChild(wrapper);

    const svg = wrapper.querySelector('svg');
    const crumbsEl = wrapper.querySelector('.ss-crumbs');
    const emptyEl = wrapper.querySelector('.ss-empty');

    const NS = 'http://www.w3.org/2000/svg';
    function el(tag, attrs) {
      const e = document.createElementNS(NS, tag);
      for (const k in attrs) e.setAttribute(k, attrs[k]);
      return e;
    }

    function axesFor(zoomId) {
      if (zoomId === null) {
        return roots.slice();
      }
      return (childrenOf.get(zoomId) || []).slice();
    }

    function render() {
      // Assemble the axis list: children of the zoomed node (or roots).
      let axes = axesFor(zoom);
      // Include the zoomed node itself as a stub when zoomed (context).
      if (zoom === null) axes = axes.filter(s => !s.archived);
      // Draw.
      const W = svg.clientWidth || 600;
      const H = 380;
      svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
      svg.innerHTML = '';

      const visible = axes.filter(s => !s.archived);
      const shown = visible.length ? visible : axes;
      const n = shown.length;
      if (!n) {
        emptyEl.classList.remove('hidden');
        return;
      }
      emptyEl.classList.add('hidden');

      const cx = W / 2, cy = H / 2 + 6;
      const R = Math.min(W, H) / 2 - 52;

      // Grid rings: fixed hour ladder (each ring = double the last), or
      // 2★/3★/4★/5★ mastery boundaries on All time.
      const ringLabels = rings ? rings.map(r => fmt(r)) : ['2\u2605', '3\u2605', '4\u2605', '5\u2605'];
      [0.25, 0.5, 0.75, 1].forEach((f, ri) => {
        const pts = [];
        for (let i = 0; i <= n; i++) {
          const a = (i / n) * Math.PI * 2 - Math.PI / 2;
          const r = R * f;
          pts.push((cx + r * Math.cos(a)).toFixed(1) + ',' + (cy + r * Math.sin(a)).toFixed(1));
        }
        svg.appendChild(el('polygon', { points: pts.join(' '), fill: 'none', stroke: '#3a3a38', 'stroke-width': 1, 'stroke-dasharray': f === 1 ? 'none' : '3 3' }));
        const lbl = el('text', { x: cx + 5, y: cy - R * f + 4, fill: '#7a7a76', 'font-size': 9 });
        lbl.textContent = ringLabels[ri];
        svg.appendChild(lbl);
      });

      // Axes + value polygon.
      const valPts = [];
      const ghostPts = [];
      shown.forEach((s, i) => {
        const a = (i / n) * Math.PI * 2 - Math.PI / 2;
        const x1 = cx, y1 = cy, x2 = cx + R * Math.cos(a), y2 = cy + R * Math.sin(a);
        const axis = el('line', { x1: x1, y1: y1, x2: x2, y2: y2, stroke: '#3a3a38', 'stroke-width': 1 });
        const g = el('g', { 'data-sid': s.id });
        const zoomable = !s.archived && (childrenOf.get(s.id) || []).some(c => !c.archived);
        if (zoomable) {
          axis.setAttribute('stroke', '#575753');
          g.setAttribute('style', 'cursor:pointer');
          const hit = el('line', { x1: x1, y1: y1, x2: x2, y2: y2, stroke: 'transparent', 'stroke-width': 14 });
          g.appendChild(hit);
          g.addEventListener('click', () => { zoom = s.id; render(); renderCrumbs(); });
          g.addEventListener('mouseenter', () => { axis.setAttribute('stroke', '#d9b861'); });
          g.addEventListener('mouseleave', () => { axis.setAttribute('stroke', '#575753'); });
        }
        g.appendChild(axis);
        svg.appendChild(g);

        const v = minutes[s.id] || 0;
        const vr = rings ? ladderRadius(v, rings, R) : R * (starsOf(v) - 1) / 4;
        valPts.push((cx + vr * Math.cos(a)).toFixed(1) + ',' + (cy + vr * Math.sin(a)).toFixed(1));

        // Ghost: the preceding equal-length period, drawn on the same rings.
        if (rings && prevMinutes) {
          const pv = prevMinutes[s.id] || 0;
          const gvr = ladderRadius(pv, rings, R);
          ghostPts.push((cx + gvr * Math.cos(a)).toFixed(1) + ',' + (cy + gvr * Math.sin(a)).toFixed(1));
        }

        // Label.
        const lx = cx + (R + 16) * Math.cos(a), ly = cy + (R + 16) * Math.sin(a);
        const anchor = Math.abs(Math.cos(a)) < 0.3 ? 'middle' : (Math.cos(a) > 0 ? 'start' : 'end');
        const dy = Math.sin(a) < -0.3 ? -4 : (Math.sin(a) > 0.3 ? 12 : 4);
        const txt = el('text', {
          x: lx, y: ly + dy, 'text-anchor': anchor,
          fill: s.archived ? '#7a7a76' : '#c6c6c2', 'font-size': 11, 'font-weight': 700
        });
        const nameSpan = rings
          ? '<tspan>' + esc(s.name) + ' \u00b7 ' + fmt(v) + '</tspan>'
          : '<tspan>' + esc(s.name) + ' \u00b7 ' + starsOf(v).toFixed(1) + '\u2605 \u00b7 ' + fmt(v) + '</tspan>';
        txt.innerHTML = nameSpan;
        if (zoomable) {
          txt.setAttribute('style', 'cursor:pointer');
          txt.addEventListener('click', () => { zoom = s.id; render(); renderCrumbs(); });
          txt.addEventListener('mouseenter', () => {
            axis.setAttribute('stroke', '#d9b861');
            txt.setAttribute('fill', '#d9b861');
          });
          txt.addEventListener('mouseleave', () => {
            axis.setAttribute('stroke', '#575753');
            txt.setAttribute('fill', s.archived ? '#7a7a76' : '#c6c6c2');
          });
        }
        svg.appendChild(txt);
      });

      // Ghost polygon (under the solid one): the preceding equal-length period.
      // Hidden when it coincides with the solid polygon.
      if (ghostPts.length === n) {
        let differs = false;
        for (let i = 0; i < n; i++) {
          const [gx, gy] = ghostPts[i].split(',').map(Number);
          const [vx, vy] = valPts[i].split(',').map(Number);
          if (Math.abs(gx - vx) > 0.25 || Math.abs(gy - vy) > 0.25) { differs = true; break; }
        }
        if (differs) {
          svg.appendChild(el('polygon', {
            points: ghostPts.join(' '),
            fill: 'none', stroke: '#9a9a96', 'stroke-width': 1,
            'stroke-dasharray': '5 4', opacity: 0.7
          }));
        }
      }

      const poly = el('polygon', {
        points: valPts.join(' '),
        fill: 'rgba(201,164,74,.18)', stroke: '#d9b861', 'stroke-width': 1.5
      });
      svg.appendChild(poly);

      // Center marker: zoomed node name or "Roots".
      const center = el('text', { x: cx, y: cy + 4, 'text-anchor': 'middle', fill: '#9a9a96', 'font-size': 10 });
      center.textContent = zoom === null ? 'Archetypes' : (byId.get(zoom) ? byId.get(zoom).name : '');
      svg.appendChild(center);
    }

    function renderCrumbs() {
      const parts = ['<span class="ss-crumb cursor-pointer px-1.5 py-0.5 rounded text-slate-400 hover:text-white hover:bg-slate-700" data-z="">All</span>'];
      if (zoom !== null) {
        const chain = [];
        let cur = zoom, guard = 0;
        while (cur !== null && guard++ < 50) { chain.unshift(cur); cur = parentOf.get(cur) || null; }
        chain.forEach((id, i) => {
          const n = byId.get(id);
          if (!n) return;
          parts.push('<span class="text-slate-600">\u203a</span><span class="ss-crumb cursor-pointer px-1.5 py-0.5 rounded ' +
            (i === chain.length - 1 ? 'text-accent-400 font-bold' : 'text-slate-400 hover:text-white hover:bg-slate-700') +
            '" data-z="' + esc(id) + '">' + esc(n.name) + '</span>');
        });
      }
      crumbsEl.innerHTML = parts.join('');
      crumbsEl.querySelectorAll('.ss-crumb').forEach(c => {
        c.addEventListener('click', () => {
          const z = c.getAttribute('data-z');
          zoom = z === '' ? null : parseInt(z, 10);
          render();
          renderCrumbs();
        });
      });
    }

    function onKey(e) {
      if (e.key === 'Escape' && zoom !== null) {
        zoom = parentOf.get(zoom) || null;
        render();
        renderCrumbs();
      }
    }
    document.addEventListener('keydown', onKey);

    render();
    renderCrumbs();

    return {
      refresh: function (newData) {
        if (newData && newData.minutes) {
          for (const k in minutes) delete minutes[k];
          Object.assign(minutes, newData.minutes);
        }
        if (newData && 'rings' in newData) {
          rings = Array.isArray(newData.rings) && newData.rings.length === 4 ? newData.rings.slice() : null;
        }
        if (newData && 'prevMinutes' in newData) {
          const pm = newData.prevMinutes || null;
          if (pm) {
            if (!prevMinutes) prevMinutes = {};
            for (const k in prevMinutes) delete prevMinutes[k];
            Object.assign(prevMinutes, pm);
          } else {
            prevMinutes = null;
          }
        }
        render();
      }
    };
  };
})();