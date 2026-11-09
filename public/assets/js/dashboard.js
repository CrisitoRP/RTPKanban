/* ============================================================
   Dashboard RTP · Anillos + Semáforo + Carga + Avance + Acceso
   + Descarga PDF + Info con colores + Modales en Leyendas
   + Estilo realista (gradientes, % en anillos, sombras)
   + Porcentajes que siempre suman 100 (método del resto mayor)
   + Skeleton loader (solo si el fetch tarda > 200 ms)
   + Descarga PDF del Top 5 de proyectos detenidos
   ============================================================ */

const COLORS = {
    sin_iniciar: '#94a3b8',
    en_curso:    '#266cb4',
    en_revision: '#FDC60A',
    finalizado:  '#027a35',
    programado:  '#F08217',
    bloqueado:   '#E5074C',
    verde:       '#027a35',
    azul:        '#266cb4',
    amarillo:    '#FDC60A',
    gris:        '#B1B1B1',
    rojo:        '#E5074C',
    naranja:     '#F08217',
    rtp:         '#78BE20'
};

const CHART_INSTANCES = {};

/* ============================================================
   HELPERS DE ESTILO REALISTA
   ============================================================ */

function hexToRgba(hex, a = 1) {
    const h = String(hex).replace('#', '');
    const r = parseInt(h.slice(0, 2), 16);
    const g = parseInt(h.slice(2, 4), 16);
    const b = parseInt(h.slice(4, 6), 16);
    return `rgba(${r},${g},${b},${a})`;
}

// Gradiente vertical (barras verticales)
function gradV(ctx, color, top = 0.95, bottom = 0.55) {
    const g = ctx.createLinearGradient(0, 0, 0, 300);
    g.addColorStop(0, hexToRgba(color, top));
    g.addColorStop(1, hexToRgba(color, bottom));
    return g;
}

// Gradiente horizontal (barras horizontales)
function gradH(ctx, color, left = 0.95, right = 0.55) {
    const g = ctx.createLinearGradient(0, 0, 400, 0);
    g.addColorStop(0, hexToRgba(color, left));
    g.addColorStop(1, hexToRgba(color, right));
    return g;
}

/**
 * Calcula porcentajes enteros que SIEMPRE suman 100.
 * Método del resto mayor (Hamilton).
 */
function computePercentages(values) {
    const total = values.reduce((a, b) => a + (Number(b) || 0), 0);
    if (!total) return values.map(() => 0);

    const exact = values.map(v => ((Number(v) || 0) / total) * 100);
    const floored = exact.map(v => Math.floor(v));
    let remainder = 100 - floored.reduce((a, b) => a + b, 0);

    const order = exact
        .map((v, i) => ({ i, frac: v - Math.floor(v) }))
        .sort((a, b) => b.frac - a.frac);

    const result = [...floored];
    let k = 0;
    while (remainder > 0 && order.length) {
        result[order[k % order.length].i] += 1;
        remainder--;
        k++;
    }
    return result;
}

// Plugin: dibuja el % dentro de cada porción del doughnut
const doughnutPercentPlugin = {
    id: 'doughnutPercent',
    afterDatasetsDraw(chart) {
        const type = chart.config.type;
        if (type !== 'doughnut' && type !== 'pie') return;
        const { ctx } = chart;
        const meta = chart.getDatasetMeta(0);
        if (!meta || !meta.data) return;

        const data = chart.data.datasets[0].data.map(Number);
        const total = data.reduce((a, b) => a + (b || 0), 0);
        if (!total) return;

        const pcts = computePercentages(data);

        ctx.save();
        ctx.font = '600 11px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        meta.data.forEach((arc, i) => {
            const v = data[i] || 0;
            if (!v) return;
            if (pcts[i] < 4) return; // no saturar segmentos pequeños

            const angle = (arc.startAngle + arc.endAngle) / 2;
            const midR = (arc.innerRadius + arc.outerRadius) / 2;
            const x = arc.x + Math.cos(angle) * midR;
            const y = arc.y + Math.sin(angle) * midR;

            ctx.shadowColor = 'rgba(0,0,0,0.35)';
            ctx.shadowBlur = 3;
            ctx.shadowOffsetX = 0;
            ctx.shadowOffsetY = 1;
            ctx.fillStyle = '#fff';
            ctx.fillText(pcts[i] + '%', x, y);
        });
        ctx.restore();
    }
};
Chart.register(doughnutPercentPlugin);

// Estilo base para tooltips
const TOOLTIP_STYLE = {
    backgroundColor: 'rgba(17,24,39,.94)',
    titleFont: { size: 12, weight: '600' },
    bodyFont: { size: 12 },
    padding: 10,
    cornerRadius: 8,
    displayColors: true,
    boxPadding: 4,
};

/* ============================================================
   INFO DE GRÁFICOS (modales)
   ============================================================ */

// Helper: dibuja un círculo de color antes del texto
function dot(color) {
    return `<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${color};margin-right:8px;vertical-align:middle;border:1px solid rgba(0,0,0,.1);"></span>`;
}

const CHART_INFO = {
    'anillo-proyectos': {
        titulo: 'Proyectos · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Cada proyecto cuenta una sola vez, según su estado actual.</p>
            <p>${dot(COLORS.sin_iniciar)}<b>Sin iniciar.</b> Proyectos sin actividades o sin avance.</p>
            <p>${dot(COLORS.en_curso)}<b>En curso.</b> Proyectos con actividades abiertas dentro de plazo.</p>
            <p>${dot(COLORS.en_revision)}<b>En revisión.</b> Proyectos que pasaron su fecha final y todavía tienen actividades abiertas.</p>
            <p>${dot(COLORS.finalizado)}<b>Finalizados.</b> Proyectos cuyas actividades ya fueron todas entregadas.</p>
            <p>${dot(COLORS.programado)}<b>Programados.</b> Proyectos que se abrirán en fecha futura.</p>
            <p>${dot(COLORS.bloqueado)}<b>Bloqueados.</b> Proyectos pausados temporalmente.</p>
        `
    },
    'anillo-actividades': {
        titulo: 'Actividades · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Todas las actividades de todos los proyectos.</p>
            <p>${dot(COLORS.sin_iniciar)}<b>Por hacer.</b> Actividades que aún no han sido iniciadas.</p>
            <p>${dot(COLORS.en_curso)}<b>En curso.</b> Actividades abiertas cuya fecha límite no llega.</p>
            <p>${dot(COLORS.en_revision)}<b>En revisión.</b> Actividades terminadas pendientes de aprobación.</p>
            <p>${dot(COLORS.finalizado)}<b>Completadas.</b> Actividades marcadas como entregadas.</p>
            <p>${dot(COLORS.bloqueado)}<b>Bloqueadas.</b> Actividades detenidas por algún impedimento.</p>
        `
    },
    'semaforo-proyecto': {
        titulo: 'Semáforo por proyecto · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Cada barra es un proyecto. El número de la derecha es el total de sus actividades.</p>
            <p>${dot(COLORS.verde)}<b>Verde.</b> Actividades ya entregadas, o sin entregar pero con más de 1 semana de margen.</p>
            <p>${dot(COLORS.azul)}<b>Azul.</b> Actividades en curso.</p>
            <p>${dot(COLORS.amarillo)}<b>Amarillo.</b> Actividades en revisión.</p>
            <p>${dot(COLORS.gris)}<b>Gris.</b> Actividades por hacer.</p>
            <p>${dot(COLORS.rojo)}<b>Rojo.</b> Actividades sin entregar cuya fecha límite ya pasó.</p>
        `
    },
    'carga-proyecto': {
        titulo: 'Carga por responsable · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Cada barra es una persona. Se suman las actividades que tiene asignadas como responsable en proyectos activos.</p>
            <p>${dot(COLORS.verde)}<b>Verde.</b> Actividades ya entregadas, o sin entregar con más de 1 semana de margen.</p>
            <p>${dot(COLORS.naranja)}<b>Naranja.</b> Actividades sin entregar a las que les queda 1 semana o menos.</p>
            <p>${dot(COLORS.rojo)}<b>Rojo.</b> Actividades sin entregar cuya fecha límite ya pasó.</p>
        `
    },
    'avance-tiempo': {
        titulo: 'Avance contra tiempo transcurrido · ¿Cómo se calcula?',
        desc: `
            <p>${dot(COLORS.rtp)}<b>% Completado (Verde).</b> Actividades completadas del proyecto entre el total de sus actividades.</p>
            <p>${dot(COLORS.naranja)}<b>% De tiempo transcurrido (Naranja).</b> Días desde el inicio del proyecto hasta hoy, entre los días totales.</p>
        `
    },
    'ultimo-acceso': {
        titulo: 'Último acceso de los responsables · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Días transcurridos desde el último inicio de sesión de cada responsable.</p>
            <p>${dot(COLORS.verde)}<b>Verde.</b> Hasta 7 días.</p>
            <p>${dot(COLORS.naranja)}<b>Naranja.</b> De 8 a 14 días.</p>
            <p>${dot(COLORS.rojo)}<b>Rojo.</b> Más de 14 días.</p>
        `
    },
    'proyectos-detenidos': {
        titulo: 'Proyectos detenidos · ¿Cómo se calcula?',
        desc: `
            <p><b>Días sin cambios.</b> Fecha de hoy menos la fecha del último cambio registrado en el proyecto.</p>
            <p><b>Top 5.</b> Se ordenan de más a menos días sin cambios y se muestran los 5 primeros.</p>
            <p>${dot(COLORS.rojo)}<b>Rojo.</b> Desde 30 días.</p>
            <p>${dot(COLORS.naranja)}<b>Naranja.</b> Con menos de 30 días.</p>
        `
    }
};

/* ============================================================
   INICIALIZACIÓN
   ============================================================ */

document.addEventListener('DOMContentLoaded', async () => {
    if (window.DASH_DATA) {
        drawProjectsRing();
        drawActivitiesRing();
        bindLegendClicks();
        bindBottomLegends();
    }
    await loadDashboardData();
});

async function loadDashboardData() {
    // Tarjetas que dependen del fetch (todas tienen data-chart + clase chart-card)
    const cards = document.querySelectorAll('.chart-card[data-chart]');

    // Skeleton solo si el fetch tarda > 200 ms (evita el "flash")
    const skeletonTimer = setTimeout(() => {
        cards.forEach(c => c.classList.add('loading'));
    }, 200);

    try {
        const res = await fetch('api/dashboard_data.php');
        const data = await res.json();
        if (data.error) { console.error(data.error); return; }
        drawSemaforo(data.semaforo);
        drawCarga(data.carga);
        drawAvanceTiempo(data.avance_tiempo);
        drawUltimoAcceso(data.ultimo_acceso);
        renderDetenidos(data.detenidos);
    } catch (e) {
        console.error('Error cargando dashboard:', e);
    } finally {
        // Limpia el timer y quita el skeleton SIEMPRE (éxito o error)
        clearTimeout(skeletonTimer);
        cards.forEach(c => c.classList.remove('loading'));
    }
}

/* ============================================================
   ANILLOS
   ============================================================ */

function drawProjectsRing() {
    const canvas = document.getElementById('ringProjects');
    if (!canvas) return;
    const d = window.DASH_DATA.proyectos;

    const valores = [d.sin_iniciar, d.en_curso, d.en_revision, d.finalizado, d.programado, d.bloqueado];
    const activos = valores.filter(v => v > 0).length;
    const esUnico = activos === 1;

    CHART_INSTANCES['anillo-proyectos'] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Sin iniciar', 'En curso', 'En revisión', 'Finalizados', 'Programados', 'Bloqueados'],
            datasets: [{
                data: valores,
                backgroundColor: [
                    COLORS.sin_iniciar, COLORS.en_curso, COLORS.en_revision,
                    COLORS.finalizado, COLORS.programado, COLORS.bloqueado
                ],
                borderColor: '#ffffff',
                borderWidth: esUnico ? 0 : 3,
                borderRadius: esUnico ? 0 : 4,
                spacing: esUnico ? 0 : 2,
                hoverOffset: esUnico ? 0 : 6,
                hoverBorderWidth: 3,
                shadowOffsetX: 0,
                shadowOffsetY: 3,
                shadowBlur: 8,
                shadowColor: 'rgba(16,24,40,.18)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            rotation: -90,
            circumference: 360,
            animation: { duration: 900, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                doughnutPercent: { enabled: true },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        label: (c) => {
                            const pcts = computePercentages(
                                c.chart.data.datasets[0].data.map(Number)
                            );
                            return ` ${c.label}: ${c.parsed} (${pcts[c.dataIndex]}%)`;
                        },
                        afterLabel: (ctx2) => {
                            const list = getProjectListByIndex(ctx2.dataIndex);
                            if (!list.length) return 'Sin proyectos';
                            return list.map(p => '• ' + p.nombre);
                        }
                    }
                }
            },
            onClick: (evt, elements) => {
                if (!elements.length) return;
                openProjectList(elements[0].index);
            }
        }
    });
}

function getProjectListByIndex(idx) {
    const d = window.DASH_DATA.proyectos;
    if (idx === 0) return d.sin_iniciarList;
    if (idx === 1) return d.en_cursoList;
    if (idx === 2) return d.en_revisionList;
    if (idx === 3) return d.finalizadosList;
    if (idx === 4) return d.programadoList;
    if (idx === 5) return d.bloqueadoList;
    return [];
}

function openProjectList(idx) {
    const titles = [
        '⏸️ Proyectos sin iniciar', '🔄 Proyectos en curso', '⏳ Proyectos en revisión',
        '✅ Proyectos finalizados', '📅 Proyectos programados', '🔒 Proyectos bloqueados'
    ];
    showModal(titles[idx], getProjectListByIndex(idx), 'proyecto');
}

function drawActivitiesRing() {
    const canvas = document.getElementById('ringActivities');
    if (!canvas) return;
    const d = window.DASH_DATA.actividades;

    const valores = [d.por_hacer, d.en_curso, d.en_revision, d.completada, d.bloqueado];
    const activos = valores.filter(v => v > 0).length;
    const esUnico = activos === 1;

    CHART_INSTANCES['anillo-actividades'] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Por hacer', 'En curso', 'En revisión', 'Completadas', 'Bloqueadas'],
            datasets: [{
                data: valores,
                backgroundColor: [
                    COLORS.sin_iniciar, COLORS.en_curso, COLORS.en_revision,
                    COLORS.finalizado, COLORS.bloqueado
                ],
                borderColor: '#ffffff',
                borderWidth: esUnico ? 0 : 3,
                borderRadius: esUnico ? 0 : 4,
                spacing: esUnico ? 0 : 2,
                hoverOffset: esUnico ? 0 : 6,
                hoverBorderWidth: 3,
                shadowOffsetX: 0,
                shadowOffsetY: 3,
                shadowBlur: 8,
                shadowColor: 'rgba(16,24,40,.18)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            rotation: -90,
            circumference: 360,
            animation: { duration: 900, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                doughnutPercent: { enabled: true },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        label: (c) => {
                            const pcts = computePercentages(
                                c.chart.data.datasets[0].data.map(Number)
                            );
                            return ` ${c.label}: ${c.parsed} (${pcts[c.dataIndex]}%)`;
                        },
                        afterLabel: (ctx2) => {
                            const list = getActivityListByIndex(ctx2.dataIndex);
                            if (!list.length) return 'Sin actividades';
                            return list.slice(0, 5).map(a => '• ' + a.titulo)
                                .concat(list.length > 5 ? ['...'] : []);
                        }
                    }
                }
            },
            onClick: (evt, elements) => {
                if (!elements.length) return;
                openActivityList(elements[0].index);
            }
        }
    });
}

function getActivityListByIndex(idx) {
    const d = window.DASH_DATA.actividades;
    if (idx === 0) return d.por_hacerList;
    if (idx === 1) return d.en_cursoList;
    if (idx === 2) return d.en_revisionList;
    if (idx === 3) return d.completadaList;
    if (idx === 4) return d.bloqueadoList;
    return [];
}

function openActivityList(idx) {
    const titles = [
        '📝 Actividades por hacer', '🔄 Actividades en curso', '⏳ Actividades en revisión',
        '✅ Actividades completadas', '🔴 Actividades bloqueadas'
    ];
    showModal(titles[idx], getActivityListByIndex(idx), 'actividad');
}

function bindLegendClicks() {
    document.querySelectorAll('.ring-legend span').forEach(el => {
        el.addEventListener('click', () => {
            const cat = el.dataset.cat;
            const d = window.DASH_DATA;
            if (cat === 'sin_iniciar')   return showModal('⏸️ Proyectos sin iniciar',   d.proyectos.sin_iniciarList, 'proyecto');
            if (cat === 'en_curso')      return showModal('🔄 Proyectos en curso',      d.proyectos.en_cursoList, 'proyecto');
            if (cat === 'en_revision')   return showModal('⏳ Proyectos en revisión',   d.proyectos.en_revisionList, 'proyecto');
            if (cat === 'finalizado')    return showModal('✅ Proyectos finalizados',   d.proyectos.finalizadosList, 'proyecto');
            if (cat === 'programado')    return showModal('📅 Proyectos programados',   d.proyectos.programadoList, 'proyecto');
            if (cat === 'bloqueado')     return showModal('🔒 Proyectos bloqueados',    d.proyectos.bloqueadoList, 'proyecto');

            if (cat === 'por_hacer')     return showModal('📝 Actividades por hacer',   d.actividades.por_hacerList, 'actividad');
            if (cat === 'en_curso')      return showModal('🔄 Actividades en curso',    d.actividades.en_cursoList, 'actividad');
            if (cat === 'en_revision')   return showModal('⏳ Actividades en revisión', d.actividades.en_revisionList, 'actividad');
            if (cat === 'completada')    return showModal('✅ Actividades completadas', d.actividades.completadaList, 'actividad');
            if (cat === 'bloqueado')     return showModal('🔴 Actividades bloqueadas',  d.actividades.bloqueadoList, 'actividad');
        });
    });
}

/* ============================================================
   SEMÁFORO (barras horizontales apiladas)
   ============================================================ */

function drawSemaforo(data) {
    const canvas = document.getElementById('chartSemaforo');
    if (!canvas || !data.length) return;
    const ctx = canvas.getContext('2d');

    const bgs = [
        gradH(ctx, COLORS.verde),
        gradH(ctx, COLORS.azul),
        gradH(ctx, COLORS.amarillo),
        gradH(ctx, COLORS.gris),
        gradH(ctx, COLORS.rojo)
    ];

    CHART_INSTANCES['semaforo-proyecto'] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.map(d => d.proyecto),
            datasets: [
                { label: 'Completadas', data: data.map(d => +d.completadas), backgroundColor: bgs[0], borderSkipped: false },
                { label: 'En curso',    data: data.map(d => +d.en_curso),    backgroundColor: bgs[1], borderSkipped: false },
                { label: 'En revisión', data: data.map(d => +d.en_revision), backgroundColor: bgs[2], borderSkipped: false },
                { label: 'Por hacer',   data: data.map(d => +d.por_hacer),   backgroundColor: bgs[3], borderSkipped: false },
                { label: 'Bloqueadas',  data: data.map(d => +d.bloqueadas),  backgroundColor: bgs[4], borderSkipped: false }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            animation: { duration: 900, easing: 'easeOutQuart' },
            scales: {
                x: {
                    stacked: true,
                    beginAtZero: true,
                    grid: { color: 'rgba(148,163,184,.18)' },
                    ticks: { color: '#64748b', font: { size: 11 }, precision: 0 },
                    border: { display: false }
                },
                y: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { color: '#475569', font: { size: 11, weight: '500' } }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        afterBody: (items) => 'Total: ' + data[items[0].dataIndex].total + ' actividades'
                    }
                }
            },
            elements: {
                bar: {
                    borderRadius: { topLeft: 0, bottomLeft: 0, topRight: 4, bottomRight: 4 },
                    barPercentage: 0.72,
                    categoryPercentage: 0.75
                }
            }
        }
    });
}

/* ============================================================
   CARGA (barras horizontales apiladas)
   ============================================================ */

function drawCarga(data) {
    const canvas = document.getElementById('chartCarga');
    if (!canvas || !data.length) return;
    const ctx = canvas.getContext('2d');

    CHART_INSTANCES['carga-proyecto'] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.map(d => d.nombre_completo),
            datasets: [
                { label: 'Entregadas o +1 semana', data: data.map(d => +d.verdes),   backgroundColor: gradH(ctx, COLORS.verde),   stack: 'act', borderSkipped: false },
                { label: 'Queda ≤ 1 semana',       data: data.map(d => +d.naranjas), backgroundColor: gradH(ctx, COLORS.naranja), stack: 'act', borderSkipped: false },
                { label: 'Fecha límite vencida',   data: data.map(d => +d.rojos),    backgroundColor: gradH(ctx, COLORS.rojo),    stack: 'act', borderSkipped: false }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            animation: { duration: 900, easing: 'easeOutQuart' },
            scales: {
                x: {
                    stacked: true,
                    beginAtZero: true,
                    grid: { color: 'rgba(148,163,184,.18)' },
                    ticks: { color: '#64748b', font: { size: 11 }, precision: 0 },
                    border: { display: false }
                },
                y: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { color: '#475569', font: { size: 11, weight: '500' } }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        afterBody: (items) => 'Total: ' + data[items[0].dataIndex].total + ' actividades'
                    }
                }
            },
            elements: {
                bar: {
                    borderRadius: { topLeft: 0, bottomLeft: 0, topRight: 4, bottomRight: 4 },
                    barPercentage: 0.72,
                    categoryPercentage: 0.75
                }
            }
        }
    });
}

/* ============================================================
   AVANCE CONTRA TIEMPO (barras verticales agrupadas)
   ============================================================ */

function drawAvanceTiempo(data) {
    const canvas = document.getElementById('chartAvanceTiempo');
    if (!canvas || !data.length) return;
    const ctx = canvas.getContext('2d');

    CHART_INSTANCES['avance-tiempo'] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.map(d => d.proyecto),
            datasets: [
                { label: 'Avance real', data: data.map(d => d.avance_real),   backgroundColor: gradV(ctx, COLORS.rtp),     borderSkipped: false },
                { label: 'Tiempo',      data: data.map(d => d.avance_tiempo), backgroundColor: gradV(ctx, COLORS.naranja), borderSkipped: false }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 900, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        label: (c) => ` ${c.dataset.label}: ${c.parsed.y}%`
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#475569', font: { size: 11, weight: '500' } }
                },
                y: {
                    beginAtZero: true,
                    max: 100,
                    grid: { color: 'rgba(148,163,184,.18)' },
                    ticks: { color: '#64748b', font: { size: 11 }, callback: v => v + '%' },
                    border: { display: false }
                }
            },
            elements: {
                bar: {
                    borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
                    barPercentage: 0.7,
                    categoryPercentage: 0.75
                }
            }
        }
    });
}

/* ============================================================
   ÚLTIMO ACCESO (barras horizontales con gradiente por color)
   ============================================================ */

function drawUltimoAcceso(data) {
    const canvas = document.getElementById('chartUltimoAcceso');
    if (!canvas || !data.length) return;
    const ctx = canvas.getContext('2d');

    const colores = data.map(d => {
        const dias = d.dias_sin_acceso ?? 999;
        if (dias <= 7) return COLORS.verde;
        if (dias <= 14) return COLORS.naranja;
        return COLORS.rojo;
    });

    CHART_INSTANCES['ultimo-acceso'] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.map(d => d.nombre_completo),
            datasets: [{
                label: 'Días sin acceso',
                data: data.map(d => Math.min(30, d.dias_sin_acceso ?? 30)),
                backgroundColor: colores.map(c => gradH(ctx, c)),
                borderRadius: { topLeft: 0, bottomLeft: 0, topRight: 6, bottomRight: 6 },
                borderSkipped: false,
                barPercentage: 0.7,
                categoryPercentage: 0.8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            animation: { duration: 900, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        label: (ctx2) => {
                            const real = data[ctx2.dataIndex].dias_sin_acceso;
                            return real === null ? 'Nunca ha ingresado' : real + ' días sin acceso';
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    max: 30,
                    grid: { color: 'rgba(148,163,184,.18)' },
                    ticks: { color: '#64748b', font: { size: 11 }, precision: 0 },
                    border: { display: false }
                },
                y: {
                    grid: { display: false },
                    ticks: { color: '#475569', font: { size: 11, weight: '500' } }
                }
            }
        }
    });
}

/* ============================================================
   DETENIDOS
   ============================================================ */

function renderDetenidos(data) {
    const contenedor = document.getElementById('listaDetenidos');
    if (!contenedor) return;

    if (!data.length) {
        contenedor.innerHTML = '<p class="empty-msg">No hay proyectos detenidos. 🎉</p>';
        return;
    }

    let html = '<ul class="stalled-items">';
    data.forEach(d => {
        const dias = d.dias_sin_cambios;
        const clase = dias >= 30 ? 'rojo' : 'naranja';
        html += `
            <li class="stalled-item ${clase}">
                <div class="stalled-info">
                    <div class="stalled-title">${escapeHtml(d.proyecto)}</div>
                    <div class="stalled-meta">Responsable: ${escapeHtml(d.responsable || 'Sin asignar')}</div>
                </div>
                <div class="stalled-dias">
                    <span class="dias-num">${dias}</span>
                    <span class="dias-label">días sin cambios</span>
                </div>
                <button class="btn btn-notificar" onclick="notificar('${d.id_proyecto}')">🔔 Notificar</button>
            </li>
        `;
    });
    html += '</ul>';
    contenedor.innerHTML = html;
}

async function notificar(idProyecto) {
    if (!confirm('¿Enviar recordatorio al responsable?')) return;
    const res = await fetch('api/notify_stalled.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_proyecto: idProyecto })
    });
    const json = await res.json();
    if (json.error) alert(json.error);
    else alert('✅ Notificación enviada al responsable (' + json.dias + ' días sin cambios).');
}

/* ============================================================
   MODALES EN LEYENDAS INFERIORES
   ============================================================ */

function bindBottomLegends() {
    document.querySelectorAll('.chart-legend span[data-legend]').forEach(el => {
        el.addEventListener('click', () => {
            const cat = el.dataset.legend;
            openLegendModal(cat);
        });
    });
}

function openLegendModal(cat) {
    const allActs = window.DASH_DATA.todasList || [];
    let filtradas = [];
    let titulo = '';

    if (cat === 'completadas') {
        filtradas = allActs.filter(a => a.estado === 'completada');
        titulo = '✅ Completadas';
    } else if (cat === 'en_curso') {
        filtradas = allActs.filter(a => a.estado === 'en_curso');
        titulo = '🔄 En curso';
    } else if (cat === 'en_revision') {
        filtradas = allActs.filter(a => a.estado === 'en_revision');
        titulo = '⏳ En revisión';
    } else if (cat === 'por_hacer') {
        filtradas = allActs.filter(a => a.estado === 'por_hacer');
        titulo = '📝 Por hacer';
    } else if (cat === 'bloqueadas') {
        filtradas = allActs.filter(a => a.estado === 'bloqueado');
        titulo = '🔴 Bloqueadas';
    } else if (cat === 'carga_verde') {
        filtradas = allActs.filter(a => {
            if (a.estado === 'completada') return true;
            if (!a.fecha_limite) return false;
            const d = new Date(a.fecha_limite + 'T00:00:00');
            const hoy7 = new Date(); hoy7.setDate(hoy7.getDate() + 7);
            return d > hoy7;
        });
        titulo = '🟢 Entregadas o con +1 semana de margen';
    } else if (cat === 'carga_naranja') {
        filtradas = allActs.filter(a => {
            if (a.estado === 'completada' || !a.fecha_limite) return false;
            const d = new Date(a.fecha_limite + 'T00:00:00');
            const hoy = new Date(); hoy.setHours(0,0,0,0);
            const hoy7 = new Date(); hoy7.setDate(hoy7.getDate() + 7);
            return d >= hoy && d <= hoy7;
        });
        titulo = '🟠 Queda ≤ 1 semana';
    } else if (cat === 'carga_rojo') {
        filtradas = allActs.filter(a => {
            if (a.estado === 'completada' || !a.fecha_limite) return false;
            const d = new Date(a.fecha_limite + 'T00:00:00');
            const hoy = new Date(); hoy.setHours(0,0,0,0);
            return d < hoy;
        });
        titulo = '🔴 Fecha límite vencida';
    } else if (cat === 'acceso_verde') {
        showModal('ℹ️ Información', [], 'actividad');
        document.getElementById('listBody').innerHTML = '<p class="empty-msg">La información de días de acceso se basa en usuarios. <br><br>Revisa la gráfica directamente para ver quién no ha ingresado.</p>';
        document.getElementById('listTitle').textContent = titulo || 'Último acceso';
        document.getElementById('listModal').classList.remove('hidden');
        return;
    } else {
        return;
    }

    const agrupadas = {};
    filtradas.forEach(a => {
        const resp = a.responsable || 'Sin asignar';
        if (!agrupadas[resp]) agrupadas[resp] = [];
        agrupadas[resp].push(a);
    });

    let html = '';
    if (filtradas.length === 0) {
        html = '<p class="empty-msg">No hay actividades en esta categoría.</p>';
    } else {
        for (const [resp, acts] of Object.entries(agrupadas)) {
            html += `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--rtp-guinda); border-bottom: 2px solid #ecf9d9; padding-bottom: 4px; margin-bottom: 10px;">
                        👤 ${escapeHtml(resp)} <span style="font-size: 10pt; color: #666; font-weight: normal;">(${acts.length} ${acts.length === 1 ? 'actividad' : 'actividades'})</span>
                    </h4>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
            `;
            acts.forEach(a => {
                html += `
                    <div class="list-item" style="margin-bottom: 0; padding: 10px 14px;" onclick="location.href='project_detail.php?id=${a.proyecto_id}&actividad=${a.id}'">
                        <div class="list-item-title" style="font-size: 10.5pt;">📌 ${escapeHtml(a.titulo)}</div>
                        <div class="list-item-meta" style="font-size: 9pt;">
                            Proyecto: <b>${escapeHtml(a.proyecto_nombre)}</b> · Límite: ${a.fecha_limite ? a.fecha_limite : '—'}
                        </div>
                    </div>
                `;
            });
            html += `
                    </div>
                </div>
            `;
        }
    }

    document.getElementById('listTitle').textContent = `${titulo} (${filtradas.length})`;
    document.getElementById('listBody').innerHTML = html;
    document.getElementById('listModal').classList.remove('hidden');
}

/* ============================================================
   DESCARGA PDF
   ============================================================ */

const PDF_COLORS = {
    guinda:    [159, 34, 65],
    verde:     [120, 190, 32],
    verdeDark: [2, 122, 53],
    gris:      [85, 88, 90],
    grisClaro: [220, 222, 225],
    negro:     [30, 30, 30],
    blanco:    [255, 255, 255],
    rojo:      [229, 7, 76],
    naranja:   [240, 130, 23],
    amarillo:  [253, 198, 10],
    azul:      [38, 108, 180]
};

function hexToRgb(hex) {
    if (!hex) return [150, 150, 150];
    const h = hex.replace('#', '');
    const r = parseInt(h.slice(0, 2), 16);
    const g = parseInt(h.slice(2, 4), 16);
    const b = parseInt(h.slice(4, 6), 16);
    return [r, g, b];
}

function fechaLarga() {
    const ahora = new Date();
    const dd = String(ahora.getDate()).padStart(2, '0');
    const mm = String(ahora.getMonth() + 1).padStart(2, '0');
    const yyyy = ahora.getFullYear();
    const hh = String(ahora.getHours()).padStart(2, '0');
    const mi = String(ahora.getMinutes()).padStart(2, '0');
    return `${dd}/${mm}/${yyyy} ${hh}:${mi}`;
}

function fechaArchivo() {
    const ahora = new Date();
    return ahora.toISOString().slice(0, 16).replace('T', '-').replace(':', '');
}

function pdfHeader(pdf, titulo, subtitulo) {
    const pageWidth = pdf.internal.pageSize.getWidth();
    pdf.setFillColor(...PDF_COLORS.guinda);
    pdf.rect(0, 0, pageWidth, 22, 'F');
    pdf.setTextColor(...PDF_COLORS.blanco);
    pdf.setFontSize(15);
    pdf.setFont('helvetica', 'bold');
    pdf.text('RTP Kanban · Reporte', 12, 10);
    pdf.setFontSize(9);
    pdf.setFont('helvetica', 'normal');
    pdf.text(`Generado el ${fechaLarga()}`, 12, 16);
    pdf.setFontSize(11);
    pdf.setFont('helvetica', 'bold');
    pdf.text(titulo || '', pageWidth - 12, 10, { align: 'right' });
    if (subtitulo) {
        pdf.setFontSize(8);
        pdf.setFont('helvetica', 'normal');
        pdf.text(subtitulo, pageWidth - 12, 16, { align: 'right' });
    }
}

function pdfFooter(pdf, paginaActual, totalPaginas) {
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    pdf.setDrawColor(...PDF_COLORS.grisClaro);
    pdf.setLineWidth(0.3);
    pdf.line(12, pageHeight - 12, pageWidth - 12, pageHeight - 12);
    pdf.setFontSize(8);
    pdf.setTextColor(...PDF_COLORS.gris);
    pdf.setFont('helvetica', 'normal');
    pdf.text('Sistema de Gestión de Proyectos · RTP', 12, pageHeight - 7);
    pdf.text(`Página ${paginaActual} de ${totalPaginas}`, pageWidth - 12, pageHeight - 7, { align: 'right' });
}

function pdfLeyenda(pdf, y, items) {
    const pageWidth = pdf.internal.pageSize.getWidth();
    const startX = 15;
    let curY = y;
    pdf.setFontSize(10);
    pdf.setFont('helvetica', 'bold');
    pdf.setTextColor(...PDF_COLORS.guinda);
    pdf.text('Leyenda', startX, curY);
    curY += 7;
    pdf.setFontSize(9);
    pdf.setFont('helvetica', 'normal');

    const total = items.reduce((s, i) => s + (i.valor || 0), 0) || 1;
    const pcts = computePercentages(items.map(i => i.valor || 0));

    items.forEach((item, idx) => {
        const rgb = hexToRgb(item.color);
        pdf.setFillColor(rgb[0], rgb[1], rgb[2]);
        pdf.roundedRect(startX, curY - 3.5, 4, 4, 0.5, 0.5, 'F');
        pdf.setTextColor(...PDF_COLORS.negro);
        pdf.setFont('helvetica', 'normal');
        pdf.text(item.label, startX + 6, curY);
        pdf.setTextColor(...PDF_COLORS.gris);
        pdf.setFont('helvetica', 'bold');
        pdf.text(`${item.valor}  (${pcts[idx]}%)`, pageWidth - 15, curY, { align: 'right' });
        curY += 6.5;
    });
    pdf.setDrawColor(...PDF_COLORS.grisClaro);
    pdf.line(startX, curY - 2, pageWidth - 15, curY - 2);
    curY += 5;
    pdf.setFont('helvetica', 'bold');
    pdf.setTextColor(...PDF_COLORS.guinda);
    pdf.text('Total', startX, curY);
    pdf.text(`${total}`, pageWidth - 15, curY, { align: 'right' });
    curY += 10;
    return curY;
}

function pdfTabla(pdf, y, headers, rows, opts = {}) {
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const marginX = 15;
    const tableWidth = pageWidth - marginX * 2;
    const colWidths = opts.colWidths || headers.map(() => tableWidth / headers.length);
    let curY = y;
    pdf.setFillColor(...PDF_COLORS.grisClaro);
    pdf.rect(marginX, curY - 4.5, tableWidth, 7, 'F');
    pdf.setFontSize(9);
    pdf.setFont('helvetica', 'bold');
    pdf.setTextColor(...PDF_COLORS.negro);
    let x = marginX + 2;
    headers.forEach((h, i) => {
        pdf.text(h, x, curY);
        x += colWidths[i];
    });
    curY += 8;
    pdf.setFont('helvetica', 'normal');
    pdf.setFontSize(8.5);
    rows.forEach((row, idx) => {
        if (curY > pageHeight - 20) {
            pdf.addPage();
            pdfHeader(pdf, opts.titulo || '', opts.subtitulo || '');
            curY = 30;
        }
        if (idx % 2 === 0) {
            pdf.setFillColor(248, 250, 252);
            pdf.rect(marginX, curY - 4.5, tableWidth, 7, 'F');
        }
        pdf.setTextColor(...PDF_COLORS.negro);
        x = marginX + 2;
        row.forEach((cell, i) => {
            const texto = String(cell ?? '—');
            const maxChars = Math.floor(colWidths[i] / 1.8);
            const textoFinal = texto.length > maxChars ? texto.slice(0, maxChars - 1) + '…' : texto;
            pdf.text(textoFinal, x, curY);
            x += colWidths[i];
        });
        curY += 7;
    });
    return curY + 4;
}

function getLeyendaItems(chartKey) {
    const d = window.DASH_DATA || {};
    const p = d.proyectos || {};
    const a = d.actividades || {};

    switch (chartKey) {
        case 'anillo-proyectos':
            return [
                { color: COLORS.sin_iniciar, label: 'Sin iniciar', valor: p.sin_iniciar || 0 },
                { color: COLORS.en_curso,    label: 'En curso',    valor: p.en_curso || 0 },
                { color: COLORS.en_revision, label: 'En revisión', valor: p.en_revision || 0 },
                { color: COLORS.finalizado,  label: 'Finalizados', valor: p.finalizado || 0 },
                { color: COLORS.programado,  label: 'Programados', valor: p.programado || 0 },
                { color: COLORS.bloqueado,   label: 'Bloqueados',  valor: p.bloqueado || 0 }
            ];
        case 'anillo-actividades':
            return [
                { color: COLORS.sin_iniciar, label: 'Por hacer',   valor: a.por_hacer || 0 },
                { color: COLORS.en_curso,    label: 'En curso',    valor: a.en_curso || 0 },
                { color: COLORS.en_revision, label: 'En revisión', valor: a.en_revision || 0 },
                { color: COLORS.finalizado,  label: 'Completadas', valor: a.completada || 0 },
                { color: COLORS.bloqueado,   label: 'Bloqueadas',  valor: a.bloqueado || 0 }
            ];
        default:
            return null;
    }
}

function getDetalleTabla(chartKey) {
    const d = window.DASH_DATA || {};
    const p = d.proyectos || {};
    const a = d.actividades || {};

    switch (chartKey) {
        case 'anillo-proyectos': {
            const headers = ['Proyecto', 'Estado', 'Avance', 'Fecha fin'];
            const rows = [];
            const pushRows = (list) => (list || []).forEach(x => {
                rows.push([ x.nombre, x.estado, (x.avance ?? 0) + '%', x.fecha_fin || '—' ]);
            });
            pushRows(p.sin_iniciarList);
            pushRows(p.en_cursoList);
            pushRows(p.en_revisionList);
            pushRows(p.finalizadosList);
            pushRows(p.programadoList);
            pushRows(p.bloqueadoList);
            return { headers, rows, titulo: 'Detalle de proyectos' };
        }
        case 'anillo-actividades': {
            const headers = ['Actividad', 'Proyecto', 'Estado'];
            const rows = [];
            const pushRows = (list) => (list || []).forEach(x => {
                rows.push([ x.titulo, x.proyecto_nombre || '—', x.estado ]);
            });
            pushRows(a.por_hacerList);
            pushRows(a.en_cursoList);
            pushRows(a.en_revisionList);
            pushRows(a.completadaList);
            pushRows(a.bloqueadoList);
            return { headers, rows, titulo: 'Detalle de actividades' };
        }
        default:
            return null;
    }
}

/* ============================================================
   DESCARGA PDF · TOP 5 PROYECTOS DETENIDOS (NUEVO)
   ============================================================ */

function truncar(texto, max) {
    if (!texto) return '—';
    return texto.length > max ? texto.slice(0, max - 1) + '…' : texto;
}

function descargarDetenidosPDF() {
    const contenedor = document.getElementById('listaDetenidos');
    if (!contenedor) { alert('Lista no disponible todavía.'); return; }

    const items = contenedor.querySelectorAll('.stalled-item');
    if (!items.length) { alert('No hay proyectos detenidos para exportar.'); return; }

    const info = CHART_INFO['proyectos-detenidos'] || { titulo: 'Proyectos detenidos', desc: '' };
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();

    // Header
    pdfHeader(pdf, info.titulo, 'Análisis de datos');

    // Descripción
    let curY = 30;
    pdf.setFontSize(9);
    pdf.setTextColor(...PDF_COLORS.gris);
    pdf.setFont('helvetica', 'normal');
    const textoPlano = (info.desc || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    const splitDesc = pdf.splitTextToSize(textoPlano, pageWidth - 30);
    pdf.text(splitDesc, 15, curY);
    curY += splitDesc.length * 4.5 + 8;

    // Título sección
    pdf.setFontSize(10);
    pdf.setFont('helvetica', 'bold');
    pdf.setTextColor(...PDF_COLORS.guinda);
    pdf.text(`Listado de proyectos detenidos (${items.length})`, 15, curY);
    curY += 8;

    // Tabla
    const marginX = 15;
    const tableWidth = pageWidth - marginX * 2;
    const colWidths = [tableWidth * 0.50, tableWidth * 0.32, tableWidth * 0.18];

    pdf.setFillColor(...PDF_COLORS.grisClaro);
    pdf.rect(marginX, curY - 4.5, tableWidth, 7, 'F');
    pdf.setFontSize(9);
    pdf.setFont('helvetica', 'bold');
    pdf.setTextColor(...PDF_COLORS.negro);
    pdf.text('Proyecto', marginX + 2, curY);
    pdf.text('Responsable', marginX + 2 + colWidths[0], curY);
    pdf.text('Días sin cambios', marginX + 2 + colWidths[0] + colWidths[1], curY);
    curY += 8;

    pdf.setFont('helvetica', 'normal');
    pdf.setFontSize(8.5);

    items.forEach((item, idx) => {
        if (curY > pageHeight - 20) {
            pdf.addPage();
            pdfHeader(pdf, info.titulo, 'Análisis de datos');
            curY = 20;
        }

        const titulo = (item.querySelector('.stalled-title')?.textContent || '').trim();
        const meta = (item.querySelector('.stalled-meta')?.textContent || '').trim()
            .replace(/^Responsable:\s*/i, '');
        const dias = (item.querySelector('.dias-num')?.textContent || '').trim();
        const diasNum = parseInt(dias, 10) || 0;
        const colorDias = diasNum >= 30 ? PDF_COLORS.rojo : PDF_COLORS.naranja;

        if (idx % 2 === 0) {
            pdf.setFillColor(248, 250, 252);
            pdf.rect(marginX, curY - 4.5, tableWidth, 8, 'F');
        }

        pdf.setTextColor(...PDF_COLORS.negro);
        pdf.setFont('helvetica', 'normal');
        pdf.text(truncar(titulo, 45), marginX + 2, curY);

        pdf.setTextColor(...PDF_COLORS.gris);
        pdf.text(truncar(meta, 30), marginX + 2 + colWidths[0], curY);

        pdf.setTextColor(...colorDias);
        pdf.setFont('helvetica', 'bold');
        pdf.text(`${dias} días`, marginX + 2 + colWidths[0] + colWidths[1], curY);
        pdf.setFont('helvetica', 'normal');

        curY += 8;
    });

    // Footer
    const total = pdf.internal.getNumberOfPages();
    for (let i = 1; i <= total; i++) {
        pdf.setPage(i);
        pdfFooter(pdf, i, total);
    }

    pdf.save(`proyectos-detenidos-${fechaArchivo()}.pdf`);
}

function downloadChart(chartKey) {
    // ✅ Caso especial: Top 5 proyectos detenidos (no es Chart.js, es HTML)
    if (chartKey === 'proyectos-detenidos') {
        descargarDetenidosPDF();
        return;
    }

    const chart = CHART_INSTANCES[chartKey];
    if (!chart) { alert('Gráfico no disponible todavía.'); return; }
    const info = CHART_INFO[chartKey] || { titulo: chartKey, desc: '' };
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const pageWidth = pdf.internal.pageSize.getWidth();
    pdfHeader(pdf, info.titulo, 'Análisis de datos');
    const textoPlano = (info.desc || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    let curY = 30;
    pdf.setFontSize(9);
    pdf.setTextColor(...PDF_COLORS.gris);
    pdf.setFont('helvetica', 'normal');
    const splitDesc = pdf.splitTextToSize(textoPlano, pageWidth - 30);
    pdf.text(splitDesc, 15, curY);
    curY += splitDesc.length * 4.5 + 6;
    const canvas = chart.canvas;
    const imgData = canvas.toDataURL('image/png', 1.0);
    const maxImgWidth = pageWidth - 60;
    const maxImgHeight = 100;
    let imgWidth = maxImgWidth;
    let imgHeight = (canvas.height * imgWidth) / canvas.width;
    if (imgHeight > maxImgHeight) {
        imgHeight = maxImgHeight;
        imgWidth = (canvas.width * imgHeight) / canvas.height;
    }
    const x = (pageWidth - imgWidth) / 2;
    pdf.setFillColor(250, 250, 250);
    pdf.roundedRect(x - 4, curY - 4, imgWidth + 8, imgHeight + 8, 3, 3, 'F');
    pdf.addImage(imgData, 'PNG', x, curY, imgWidth, imgHeight);
    curY += imgHeight + 12;
    const items = getLeyendaItems(chartKey);
    if (items && items.length) {
        curY = pdfLeyenda(pdf, curY, items);
    }
    const detalle = getDetalleTabla(chartKey);
    if (detalle && detalle.rows.length) {
        pdf.setFontSize(10);
        pdf.setFont('helvetica', 'bold');
        pdf.setTextColor(...PDF_COLORS.guinda);
        pdf.text(detalle.titulo + ` (${detalle.rows.length})`, 15, curY);
        curY += 6;
        curY = pdfTabla(pdf, curY, detalle.headers, detalle.rows, {
            titulo: info.titulo, subtitulo: 'Análisis de datos'
        });
    }
    const total = pdf.internal.getNumberOfPages();
    for (let i = 1; i <= total; i++) {
        pdf.setPage(i);
        pdfFooter(pdf, i, total);
    }
    const nombreArchivo = `${chartKey}-${fechaArchivo()}.pdf`;
    pdf.save(nombreArchivo);
}

function showInfo(chartKey) {
    const info = CHART_INFO[chartKey];
    if (!info) return;
    document.getElementById('infoTitle').textContent = info.titulo;
    document.getElementById('infoBody').innerHTML = info.desc;
    document.getElementById('infoModal').classList.remove('hidden');
}

function closeInfoModal() {
    document.getElementById('infoModal').classList.add('hidden');
}

function showModal(titulo, lista, tipo) {
    const modal = document.getElementById('listModal');
    const tituloEl = document.getElementById('listTitle');
    const body = document.getElementById('listBody');
    tituloEl.textContent = `${titulo} (${lista.length})`;
    if (!lista.length) {
        body.innerHTML = '<p class="empty-msg">No hay elementos en esta categoría.</p>';
        modal.classList.remove('hidden');
        return;
    }
    let html = '';
    if (tipo === 'proyecto') {
        lista.forEach(p => {
            html += `
                <div class="list-item" onclick="location.href='project_detail.php?id=${p.id}'">
                    <div class="list-item-title">📁 ${escapeHtml(p.nombre)}</div>
                    <div class="list-item-meta">
                        Estado: <b>${escapeHtml(p.estado)}</b> · Avance: <b>${p.avance}%</b>
                        ${p.fecha_fin ? ' · Fin: ' + p.fecha_fin : ''}
                    </div>
                    <div class="list-item-action">Abrir proyecto →</div>
                </div>
            `;
        });
    } else {
        lista.forEach(a => {
            html += `
                <div class="list-item" onclick="location.href='project_detail.php?id=${a.proyecto_id}&actividad=${a.id}'">
                    <div class="list-item-title">📌 ${escapeHtml(a.titulo)}</div>
                    <div class="list-item-meta">
                        Proyecto: <b>${escapeHtml(a.proyecto_nombre)}</b> · Estado: <b>${escapeHtml(a.estado)}</b>
                    </div>
                    <div class="list-item-action">Ir al proyecto →</div>
                </div>
            `;
        });
    }
    body.innerHTML = html;
    modal.classList.remove('hidden');
}

function closeListModal() {
    document.getElementById('listModal').classList.add('hidden');
}

document.addEventListener('click', (e) => {
    const modal = document.getElementById('listModal');
    if (modal && !modal.classList.contains('hidden') && e.target === modal) closeListModal();
    const infoModal = document.getElementById('infoModal');
    if (infoModal && !infoModal.classList.contains('hidden') && e.target === infoModal) closeInfoModal();
});

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}