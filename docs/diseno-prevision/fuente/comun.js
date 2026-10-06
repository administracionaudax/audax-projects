/* Piezas comunes de las maquetas: iconos, formatos, semáforo, tooltip, shell y gráficas SVG. */
const UI = (() => {
    // ── Iconos (trazos de Lucide, MIT) ─────────────────────────────────────────
    const P = {
        check: '<path d="M20 6 9 17l-5-5"/>',
        circleCheck: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        triangle: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        octagon: '<path d="M12 16h.01"/><path d="M12 8v4"/><path d="M15.31 2a2 2 0 0 1 1.42.59l4.68 4.68A2 2 0 0 1 22 8.69v6.62a2 2 0 0 1-.59 1.42l-4.68 4.68a2 2 0 0 1-1.42.59H8.69a2 2 0 0 1-1.42-.59l-4.68-4.68A2 2 0 0 1 2 15.31V8.69a2 2 0 0 1 .59-1.42l4.68-4.68A2 2 0 0 1 8.69 2z"/>',
        gauge: '<path d="M15.6 2.7a10 10 0 1 0 5.7 5.7"/><circle cx="12" cy="12" r="2"/><path d="M13.4 10.6 19 5"/>',
        calOff: '<path d="M4.2 4.2A2 2 0 0 0 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 1.82-1.18"/><path d="M21 15.5V6a2 2 0 0 0-2-2H9.5"/><path d="M16 2v4"/><path d="M3 10h7"/><path d="M21 10h-5.5"/><path d="m2 2 20 20"/>',
        calMinus: '<path d="M16 19h6"/><path d="M16 2v4"/><path d="M21 15V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8.5"/><path d="M3 10h18"/><path d="M8 2v4"/>',
        chevRight: '<path d="m9 18 6-6-6-6"/>',
        chevDown: '<path d="m6 9 6 6 6-6"/>',
        gap: '<circle cx="12" cy="8" r="4" stroke-dasharray="3 2.4"/><path d="M4 21a8 8 0 0 1 16 0" stroke-dasharray="3 2.4"/>',
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        plus: '<path d="M5 12h14M12 5v14"/>',
        table: '<path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/>',
        chart: '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        arrowRight: '<path d="M5 12h14m-7-7 7 7-7 7"/>',
        search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        dots: '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        trendUp: '<path d="M16 7h6v6"/><path d="m22 7-8.5 8.5-5-5L2 17"/>',
        trendDown: '<path d="M16 17h6v-6"/><path d="m22 17-8.5-8.5-5 5L2 7"/>',
        equal: '<path d="M5 9h14M5 15h14"/>',
        folder: '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        list: '<path d="M3 12h.01M3 18h.01M3 6h.01M8 12h13M8 18h13M8 6h13"/>',
        calendar: '<path d="M8 2v4M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        gantt: '<path d="M8 6h10M6 12h9M11 18h7"/>',
        users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        msg: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        menu: '<path d="M4 12h16M4 6h16M4 18h16"/>',
        filter: '<path d="M10 20a1 1 0 0 0 .55.89l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .52-1.34L21.74 4.67A1 1 0 0 0 21 3H3a1 1 0 0 0-.74 1.67l7.22 7.99A2 2 0 0 1 10 14z"/>',
        sun2: '<circle cx="12" cy="12" r="4"/>',
        x: '<path d="M18 6 6 18M6 6l12 12"/>',
        link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        userPlus: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        grip: '<circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/>',
        home: '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .71-1.53l7-6a2 2 0 0 1 2.58 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    };
    const icon = (name, cls = '') => `<svg class="icon ${cls}" viewBox="0 0 24 24" aria-hidden="true">${P[name]}</svg>`;

    // ── Formatos (es-ES, como lib/format.ts) ──────────────────────────────────
    const nf0 = new Intl.NumberFormat('es-ES', { maximumFractionDigits: 0, useGrouping: 'always' });
    const nf1 = new Intl.NumberFormat('es-ES', { maximumFractionDigits: 1, useGrouping: 'always' });
    const h = (v) => `${(Math.abs(v) < 10 && Math.round(v) !== v ? nf1 : nf0).format(v)}\u00a0h`;
    const H = (v) => `${nf0.format(Math.round(v))}\u00a0h`;
    const pct = (v) => (v === null ? '—' : `${nf0.format(v)}\u00a0%`);
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

    // ── Semáforo (thresholds.ts, D-052) ───────────────────────────────────────
    const LEVELS = {
        none: { label: 'Sin capacidad', icon: 'calOff', range: 'festivo, ausencia o día no laborable' },
        under: { label: 'Holgada', icon: 'gauge', range: 'menos del 70 %' },
        balanced: { label: 'Equilibrada', icon: 'circleCheck', range: 'del 70 % al 100 %' },
        high: { label: 'Alta', icon: 'triangle', range: 'del 100 % al 120 %' },
        over: { label: 'Sobrecarga', icon: 'octagon', range: 'más del 120 %' },
    };
    const loadPct = (load, cap) => (cap > 0 ? Math.round((Math.max(load, 0) * 100) / cap) : null);
    function level(load, cap) {
        const p = loadPct(load, cap);
        if (p === null) return 'none';
        if (p < 70) return 'under';
        if (p <= 100) return 'balanced';
        if (p <= 120) return 'high';
        return 'over';
    }
    const levelIcon = (lv, cls = 's14') => `<span class="lv-${lv} lv-icon" style="display:inline-flex">${icon(LEVELS[lv].icon, cls)}</span>`;

    /** Desviación frente a un plan o una estimación: flecha + signo + texto, aviso a partir de ±10 %. */
    function devHTML(dv) {
        const big = Math.abs(dv) > 10;
        const ic = Math.abs(dv) < 1 ? 'equal' : dv > 0 ? 'trendUp' : 'trendDown';
        const label = Math.abs(dv) < 1 ? 'Igual que lo previsto' : `${dv > 0 ? '+' : '−'}${Math.abs(dv)}\u00a0%`;
        return `<span class="dev tabular" style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap"><span style="display:inline-flex;color:${big ? 'var(--warning)' : 'var(--muted-foreground)'}">${icon(ic, 's12')}</span>${label}${big ? '<span class="sr-only"> (desviación alta)</span>' : ''}</span>`;
    }
    const KIND = {
        real: { label: 'Real', long: 'Asignado en proyectos reales' },
        seguro: { label: 'Previsto seguro', long: 'Previsto seguro' },
        posible: { label: 'Previsto posible', long: 'Previsto posible' },
    };
    const kindBadge = (k) => `<span class="kind"><span class="sw ${k}"></span>${k === 'real' ? 'Real' : k === 'seguro' ? 'Seguro' : 'Posible'}</span>`;

    // ── Capas activas (filtro) ────────────────────────────────────────────────
    const layers = { real: true, seguro: true, posible: true };
    const loadOf = (c) => (layers.real ? c.real : 0) + (layers.seguro ? c.seguro : 0) + (layers.posible ? c.posible : 0);

    // ── Tooltip ───────────────────────────────────────────────────────────────
    let tipEl;
    const tips = new Map();
    let tipSeq = 0;
    const tip = (html) => {
        const id = `t${++tipSeq}`;
        tips.set(id, html);
        return id;
    };
    function showTip(target) {
        const html = tips.get(target.dataset.tip);
        if (!html) return;
        tipEl.innerHTML = html;
        tipEl.classList.add('on');
        const r = target.getBoundingClientRect();
        const t = tipEl.getBoundingClientRect();
        let x = r.left + r.width / 2 - t.width / 2;
        let y = r.bottom + 8;
        if (y + t.height > innerHeight - 8) y = r.top - t.height - 8;
        x = Math.max(8, Math.min(x, innerWidth - t.width - 8));
        y = Math.max(8, y);
        tipEl.style.left = `${x}px`;
        tipEl.style.top = `${y}px`;
    }
    function hideTip() { tipEl?.classList.remove('on'); }
    function bindTips(root = document) {
        root.addEventListener('pointerover', (e) => { const t = e.target.closest('[data-tip]'); if (t) showTip(t); });
        root.addEventListener('pointerout', (e) => { if (e.target.closest('[data-tip]')) hideTip(); });
        root.addEventListener('focusin', (e) => { const t = e.target.closest('[data-tip]'); if (t) showTip(t); });
        root.addEventListener('focusout', hideTip);
        addEventListener('scroll', hideTip, true);
        addEventListener('keydown', (e) => { if (e.key === 'Escape') hideTip(); });
    }

    /** Contenido del tooltip de una celda: el valor manda; las filas, en orden real → seguro → posible. */
    function tipHTML({ title, load, cap, items, foot = [], gap = false }) {
        const lv = gap ? null : level(load, cap);
        const p = loadPct(load, cap);
        let s = `<div class="tt">${esc(title)}</div>`;
        if (gap) {
            s += `<div class="head"><span class="big tabular">${H(load)}</span><span class="muted">sin persona asignada</span></div>`;
        } else if (lv === 'none') {
            s += `<div class="head">${levelIcon('none')}<span>Sin capacidad</span></div>`;
        } else {
            s += `<div class="head">${levelIcon(lv, '')}<span class="big tabular">${pct(p)}</span><span class="muted">${LEVELS[lv].label}</span></div>`;
        }
        if (items.length) {
            s += '<ul>';
            for (const it of items) {
                const k = it.project.kind;
                const on = layers[k];
                s += `<li style="${on ? '' : 'opacity:.45'}"><span class="key ${k}"></span><span class="val">${h(Math.round(it.h * 10) / 10)}</span><span class="lab">${esc(it.project.name)}${k === 'real' ? '' : ` · ${k === 'seguro' ? 'seguro' : 'posible'}`}</span></li>`;
            }
            s += '</ul>';
        } else {
            s += '<div class="muted">Nada asignado</div>';
        }
        const f = [];
        if (!gap && cap > 0) f.push(`<span><b class="tabular">${H(load)}</b> asignadas de <b class="tabular">${H(cap)}</b> de capacidad</span>`);
        if (!gap && cap > 0 && load > cap) f.push(`<span>Se pasa en <b class="tabular">${H(load - cap)}</b></span>`);
        if (!gap && cap > 0 && load < cap) f.push(`<span>Libre: <b class="tabular">${H(cap - load)}</b></span>`);
        f.push(...foot);
        if (f.length) s += `<div class="foot">${f.join('')}</div>`;
        return s;
    }

    // ── Shell de la app ───────────────────────────────────────────────────────
    const NAV = [
        ['Trabajo', [['home', 'Inicio', 'inicio'], ['list', 'Mis tareas'], ['sun2', 'Mi día'], ['clock', 'Horas'], ['gauge', 'Carga', 'carga']]],
        ['Proyectos', [['folder', 'Proyectos', 'proyectos'], ['gantt', 'Gantt'], ['calendar', 'Calendario'], ['trendUp', 'Previsión', 'prevision']]],
        ['Agencia', [['users', 'Clientes'], ['chart', 'Informes'], ['msg', 'Chat']]],
    ];
    const LOGO = '<svg viewBox="0 0 200 176" aria-hidden="true"><path d="M100 176H0L50.13 87.87 100 0l50.13 88.13L200 176H100Z" style="fill:var(--foreground)"/></svg>';
    function mountShell({ active, crumbs }) {
        const qs = new URLSearchParams(location.search);
        if (qs.get('tema') === 'oscuro') document.documentElement.dataset.theme = 'dark';
        if (qs.get('tema') === 'claro') document.documentElement.dataset.theme = 'light';
        const page = document.querySelector('main.page');
        const app = document.createElement('div');
        app.className = 'app';
        const nav = NAV.map(([g, items]) => `<div class="group">${g}</div>` + items.map(([ic, label, key]) => `<a class="nav" href="#"${key === active ? ' aria-current="page"' : ''}>${icon(ic)}${label}</a>`).join('')).join('');
        app.innerHTML = `<nav class="sidebar" aria-label="Menú principal"><div class="logo">${LOGO}Audax Proyectos</div>${nav}</nav><div class="main"><header class="topbar"><button class="btn ghost icon menu-btn" type="button" aria-label="Abrir el menú">${icon('menu')}</button><div class="crumbs">${crumbs.map((c) => `<span>${esc(c)}</span>`).join(icon('chevRight', 's14'))}</div><div class="spacer"></div><button class="btn ghost icon" type="button" id="theme-btn"></button></header></div>`;
        document.body.prepend(app);
        app.querySelector('.main').append(page);
        const btn = app.querySelector('#theme-btn');
        const isDark = () => document.documentElement.dataset.theme === 'dark' || (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches);
        const paint = () => {
            btn.innerHTML = icon(isDark() ? 'sun' : 'moon');
            btn.setAttribute('aria-label', isDark() ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro');
        };
        btn.addEventListener('click', () => {
            document.documentElement.dataset.theme = isDark() ? 'light' : 'dark';
            paint();
        });
        paint();
        tipEl = document.createElement('div');
        tipEl.className = 'tip';
        tipEl.setAttribute('role', 'tooltip');
        document.body.append(tipEl);
        bindTips();
        // Trama de los previstos posibles para los SVG (una sola definición por página).
        const defs = document.createElement('div');
        defs.innerHTML = '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><pattern id="trama" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="5" height="5" style="fill:var(--posible-bg)"/><rect width="2" height="5" style="fill:var(--layer-previsto)"/></pattern></defs></svg>';
        document.body.append(defs.firstChild);
    }

    // ── Columna apilada frente a capacidad (SVG) ──────────────────────────────
    const FILL = { real: 'fill:var(--layer-real)', seguro: 'fill:var(--layer-previsto)', posible: 'fill:url(#trama)' };

    /**
     * Columnas apiladas real → seguro → posible frente a la línea de capacidad (escalonada).
     * data: [{real, seguro, posible, cap, tip?, label?}] — horas por periodo.
     */
    function columns({ data, width, height, yMax, padTop = 6, axis = null, barMax = 24, showPct = false, pctH = 18, gapStroke = 'var(--card)', grid = [] }) {
        const n = data.length;
        const slot = width / n;
        const bw = Math.min(barMax, Math.max(4, slot * 0.62));
        const plotH = height - (showPct ? pctH : 0) - (axis ? 18 : 0);
        const y = (v) => padTop + (plotH - padTop) * (1 - Math.min(v, yMax) / yMax);
        let s = `<svg viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" style="display:block;overflow:visible" aria-hidden="true">`;
        for (const g of grid) s += `<line x1="0" x2="${width}" y1="${y(g)}" y2="${y(g)}" style="stroke:var(--border)" stroke-width="1" vector-effect="non-scaling-stroke"/>`;
        data.forEach((d, i) => {
            const cx = i * slot + slot / 2;
            let base = 0;
            const segs = ['real', 'seguro', 'posible'].filter((k) => layers[k] && d[k] > 0.01);
            segs.forEach((k, j) => {
                const top = base + d[k];
                const y0 = y(base), y1 = y(top);
                const gapPx = j > 0 ? 2 : 0;
                const hgt = Math.max(0, y0 - y1 - gapPx);
                if (hgt > 0.2) s += `<rect x="${cx - bw / 2}" y="${y1}" width="${bw}" height="${hgt}" style="${FILL[k]}"/>`;
                base = top;
            });
        });
        // Capacidad: línea escalonada de 2 px en tinta (no es una serie, es la referencia).
        let path = '';
        data.forEach((d, i) => {
            const yy = y(d.cap);
            path += `${i === 0 ? 'M' : 'L'}${i * slot},${yy} L${(i + 1) * slot},${yy} `;
        });
        s += `<path d="${path}" style="fill:none;stroke:var(--capacity)" stroke-width="2" vector-effect="non-scaling-stroke"/>`;
        s += `<line x1="0" x2="${width}" y1="${y(0)}" y2="${y(0)}" style="stroke:var(--muted-foreground)" stroke-width="1" vector-effect="non-scaling-stroke"/>`;
        s += '</svg>';
        return { svg: s, slot, bw, y };
    }

    return { devHTML, icon, h, H, pct, esc, level, loadPct, LEVELS, levelIcon, KIND, kindBadge, layers, loadOf, tip, tipHTML, mountShell, columns, FILL, P };
})();
