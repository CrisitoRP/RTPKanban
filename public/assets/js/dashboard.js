/* ============================================================
   Dashboard RTP · Anillos + Semáforo + Carga + Avance + Acceso
   + Descarga PDF + Info con colores + Modales en Leyendas
   ============================================================ */

const COLORS = {
    verde:    '#027a35',
    azul:     '#266cb4',
    amarillo: '#FDC60A',
    gris:     '#B1B1B1',
    rojo:     '#E5074C',
    naranja:  '#F08217',
    rtp:      '#78BE20'
};

const CHART_INSTANCES = {};

// Helper: dibuja un círculo de color antes del texto
function dot(color) {
    return `<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${color};margin-right:8px;vertical-align:middle;border:1px solid rgba(0,0,0,.1);"></span>`;
}

const CHART_INFO = {
    'anillo-proyectos': {
        titulo: 'Proyectos · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Cada proyecto cuenta una sola vez, según su estado actual. El número del centro es el total de proyectos.</p>
            <p>${dot(COLORS.verde)}<b>Finalizados.</b> Proyectos cuyas actividades ya fueron todas entregadas.</p>
            <p>${dot(COLORS.rojo)}<b>Sin finalizar.</b> Proyectos que pasaron su fecha final y todavía tienen actividades abiertas.</p>
            <p>${dot(COLORS.azul)}<b>En curso.</b> Proyectos con actividades abiertas y que aún están dentro de su plazo.</p>
            <p><b>Porcentaje.</b> Cada tramo del anillo es: proyectos en ese estado entre el total de proyectos.</p>
        `
    },
    'anillo-actividades': {
        titulo: 'Actividades · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Todas las actividades de todos los proyectos. El número del centro es el total.</p>
            <p>${dot(COLORS.verde)}<b>Completadas.</b> Actividades marcadas como entregadas.</p>
            <p>${dot(COLORS.azul)}<b>En curso.</b> Actividades abiertas cuya fecha límite todavía no llega.</p>
            <p>${dot(COLORS.rojo)}<b>No cumplidas.</b> Actividades sin entregar cuya fecha límite ya pasó.</p>
        `
    },
    'semaforo-proyecto': {
        titulo: 'Semáforo por proyecto · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Cada barra es un proyecto. El número de la derecha es el total de sus actividades.</p>
            <p>${dot(COLORS.verde)}<b>Verde.</b> Actividades ya entregadas, o sin entregar pero con más de 1 semana de margen antes de su fecha límite.</p>
            <p>${dot(COLORS.azul)}<b>Azul.</b> Actividades en curso.</p>
            <p>${dot(COLORS.amarillo)}<b>Amarillo.</b> Actividades en revisión.</p>
            <p>${dot(COLORS.gris)}<b>Gris.</b> Actividades por hacer.</p>
            <p>${dot(COLORS.rojo)}<b>Rojo.</b> Actividades sin entregar cuya fecha límite ya pasó.</p>
            <p><b>Largo de cada tramo.</b> Es proporcional al porcentaje que representa del total de actividades del proyecto.</p>
        `
    },
    'carga-proyecto': {
        titulo: 'Carga por responsable · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Cada barra es una persona. Se suman las actividades que tiene asignadas como responsable en proyectos activos. El número de la derecha es ese total.</p>
            <p>${dot(COLORS.verde)}<b>Verde.</b> Actividades ya entregadas, o sin entregar pero con más de 1 semana de margen antes de su fecha límite.</p>
            <p>${dot(COLORS.naranja)}<b>Naranja.</b> Actividades sin entregar a las que les queda 1 semana o menos para su fecha límite.</p>
            <p>${dot(COLORS.rojo)}<b>Rojo.</b> Actividades sin entregar cuya fecha límite ya pasó.</p>
            <p><b>Largo de cada tramo.</b> Es proporcional al porcentaje que representa del total de esa persona. Ejemplo: 5 de 14 actividades = 36% de la barra.</p>
            <p><b>Actualización.</b> Se recalcula con la fecha de hoy cada vez que se abre el dashboard.</p>
        `
    },
    'avance-tiempo': {
        titulo: 'Avance contra tiempo transcurrido · ¿Cómo se calcula?',
        desc: `
            <p>${dot(COLORS.rtp)}<b>% Completado (Verde).</b> Actividades completadas del proyecto entre el total de sus actividades, por 100.</p>
            <p>${dot(COLORS.naranja)}<b>% De tiempo transcurrido (Naranja).</b> Días desde el inicio del proyecto hasta hoy, entre los días totales entre su fecha de inicio y su fecha final, por 100. Tope: 100%.</p>
            <p><b>Cómo leerlo.</b> Si la barra verde va debajo de la naranja, el proyecto lleva menos avance del que su calendario exige.</p>
            <p><b>En pantalla.</b> A la derecha del nombre aparecen los dos valores: completado y tiempo.</p>
        `
    },
    'ultimo-acceso': {
        titulo: 'Último acceso de los responsables · ¿Cómo se calcula?',
        desc: `
            <p><b>Qué se cuenta.</b> Días transcurridos desde el último inicio de sesión de cada responsable: fecha de hoy menos fecha de su último acceso.</p>
            <p>${dot(COLORS.verde)}<b>Verde.</b> Hasta 7 días.</p>
            <p>${dot(COLORS.naranja)}<b>Naranja.</b> De 8 a 14 días.</p>
            <p>${dot(COLORS.rojo)}<b>Rojo.</b> Más de 14 días.</p>
            <p><b>Escala.</b> La barra se mide sobre 30 días; con 30 o más se ve llena.</p>
            <p><b>Alerta.</b> Cuando alguien rebasa el límite que configure el administrador, se le envía una alerta con el nombre y los días sin ingresar.</p>
        `
    },
    'proyectos-detenidos': {
        titulo: 'Proyectos detenidos · ¿Cómo se calcula?',
        desc: `
            <p><b>Días sin cambios.</b> Fecha de hoy menos la fecha del último cambio registrado en el proyecto: movimiento de una actividad, cambio de estado, comentario o edición.</p>
            <p><b>Top 5.</b> Se ordenan de más a menos días sin cambios y se muestran los 5 primeros.</p>
            <p>${dot(COLORS.rojo)}<b>Rojo.</b> Desde 30 días.</p>
            <p>${dot(COLORS.naranja)}<b>Naranja.</b> Con menos de 30 días.</p>
            <p><b>Botón Notificar.</b> Envía un recordatorio al responsable del proyecto indicando quién lo notificó y cuántos días lleva detenido.</p>
        `
    }
};

document.addEventListener('DOMContentLoaded', async () => {
    if (window.DASH_DATA) {
        drawProjectsRing();
        drawActivitiesRing();
        bindLegendClicks();
        bindBottomLegends(); // Escuchar clics en leyendas de abajo
    }
    await loadDashboardData();
});

async function loadDashboardData() {
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
    }
}

/* ============ ANILLOS ============ */
function drawProjectsRing() {
    const canvas = document.getElementById('ringProjects');
    if (!canvas) return;
    const d = window.DASH_DATA.proyectos;

    const valores = [d.finalizados, d.sin_finalizar, d.en_curso];
    const activos = valores.filter(v => v > 0).length;
    const esUnico = activos === 1;

    CHART_INSTANCES['anillo-proyectos'] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Finalizados', 'Sin finalizar', 'En curso'],
            datasets: [{
                data: valores,
                backgroundColor: [COLORS.verde, COLORS.rojo, COLORS.azul],
                borderWidth: esUnico ? 0 : 2,
                borderColor: esUnico ? '#fff' : '#000',
                hoverOffset: 0, // 🛑 Dejamos esto en 0 para que no pelee con el CSS
                hoverBorderWidth: esUnico ? 0 : 2
            }]
        },
        options: {
            responsive: true,
            cutout: '70%',
            rotation: -90,
            circumference: 360,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterLabel: (ctx) => {
                            const list = getProjectListByIndex(ctx.dataIndex);
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
    if (idx === 0) return d.finalizadosList;
    if (idx === 1) return d.sinFinalizarList;
    if (idx === 2) return d.enCursoList;
    return [];
}

function openProjectList(idx) {
    const titles = ['🟢 Proyectos finalizados', '🔴 Proyectos sin finalizar', '🔵 Proyectos en curso'];
    showModal(titles[idx], getProjectListByIndex(idx), 'proyecto');
}

function drawActivitiesRing() {
    const canvas = document.getElementById('ringActivities');
    if (!canvas) return;
    const d = window.DASH_DATA.actividades;

    const valores = [d.completadas, d.en_curso, d.no_cumplidas];
    const activos = valores.filter(v => v > 0).length;
    const esUnico = activos === 1;

    CHART_INSTANCES['anillo-actividades'] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Completadas', 'En curso', 'No cumplidas'],
            datasets: [{
                data: valores,
                backgroundColor: [COLORS.verde, COLORS.azul, COLORS.rojo],
                borderWidth: esUnico ? 0 : 2,
                borderColor: esUnico ? '#fff' : '#000',
                hoverOffset: 0, // 🛑 Dejamos esto en 0 para que no pelee con el CSS
                hoverBorderWidth: esUnico ? 0 : 2
            }]
        },
        options: {
            responsive: true,
            cutout: '70%',
            rotation: -90,
            circumference: 360,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterLabel: (ctx) => {
                            const list = getActivityListByIndex(ctx.dataIndex);
                            if (!list.length) return 'Sin actividades';
                            return list.slice(0, 5).map(a => '• ' + a.titulo).concat(list.length > 5 ? ['...'] : []);
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
    if (idx === 0) return d.completadasList;
    if (idx === 1) return d.enCursoList;
    if (idx === 2) return d.noCumplidasList;
    return [];
}

function openActivityList(idx) {
    const titles = ['✅ Actividades completadas', '🔄 Actividades en curso', '⚠️ Actividades no cumplidas'];
    showModal(titles[idx], getActivityListByIndex(idx), 'actividad');
}

// Unimos todo para que las leyendas superiores funcionen
function bindLegendClicks() {
    document.querySelectorAll('.ring-legend span').forEach(el => {
        el.addEventListener('click', () => {
            const cat = el.dataset.cat;
            const d = window.DASH_DATA;
            if (cat === 'finalizados')   return showModal('🟢 Proyectos finalizados',   d.proyectos.finalizadosList, 'proyecto');
            if (cat === 'sin_finalizar') return showModal('🔴 Proyectos sin finalizar', d.proyectos.sinFinalizarList, 'proyecto');
            if (cat === 'en_curso')      return showModal('🔵 Proyectos en curso',      d.proyectos.enCursoList, 'proyecto');
            if (cat === 'act_completadas')  return showModal('✅ Actividades completadas',  d.actividades.completadasList, 'actividad');
            if (cat === 'act_en_curso')     return showModal('🔄 Actividades en curso',     d.actividades.enCursoList, 'actividad');
            if (cat === 'act_no_cumplidas') return showModal('⚠️ Actividades no cumplidas', d.actividades.noCumplidasList, 'actividad');
        });
    });
}

/* ============ SEMÁFORO ============ */
function drawSemaforo(data) {
    const canvas = document.getElementById('chartSemaforo');
    if (!canvas || !data.length) return;

    CHART_INSTANCES['semaforo-proyecto'] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.map(d => d.proyecto),
            datasets: [
                { label: 'Completadas', data: data.map(d => +d.completadas), backgroundColor: COLORS.verde },
                { label: 'En curso',    data: data.map(d => +d.en_curso),    backgroundColor: COLORS.azul },
                { label: 'En revisión', data: data.map(d => +d.en_revision), backgroundColor: COLORS.amarillo },
                { label: 'Por hacer',   data: data.map(d => +d.por_hacer),   backgroundColor: COLORS.gris },
                { label: 'Bloqueadas',  data: data.map(d => +d.bloqueadas),  backgroundColor: COLORS.rojo }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            scales: {
                x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
                y: { stacked: true }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterBody: (items) => 'Total: ' + data[items[0].dataIndex].total + ' actividades'
                    }
                }
            }
        }
    });
}

/* ============ CARGA ============ */
function drawCarga(data) {
    const canvas = document.getElementById('chartCarga');
    if (!canvas || !data.length) return;

    CHART_INSTANCES['carga-proyecto'] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.map(d => d.nombre_completo),
            datasets: [
                { label: 'Entregadas o +1 semana', data: data.map(d => +d.verdes),   backgroundColor: COLORS.verde,   stack: 'act' },
                { label: 'Queda ≤ 1 semana',       data: data.map(d => +d.naranjas), backgroundColor: COLORS.naranja, stack: 'act' },
                { label: 'Fecha límite vencida',   data: data.map(d => +d.rojos),    backgroundColor: COLORS.rojo,    stack: 'act' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            scales: {
                x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
                y: { stacked: true }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterBody: (items) => 'Total: ' + data[items[0].dataIndex].total + ' actividades'
                    }
                }
            }
        }
    });
}

/* ============ AVANCE CONTRA TIEMPO ============ */
function drawAvanceTiempo(data) {
    const canvas = document.getElementById('chartAvanceTiempo');
    if (!canvas || !data.length) return;

    CHART_INSTANCES['avance-tiempo'] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.map(d => d.proyecto),
            datasets: [
                { label: 'Avance real', data: data.map(d => d.avance_real),   backgroundColor: COLORS.rtp },
                { label: 'Tiempo',      data: data.map(d => d.avance_tiempo), backgroundColor: COLORS.naranja }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } }
        }
    });
}

/* ============ ÚLTIMO ACCESO ============ */
function drawUltimoAcceso(data) {
    const canvas = document.getElementById('chartUltimoAcceso');
    if (!canvas || !data.length) return;

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
                backgroundColor: colores
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const real = data[ctx.dataIndex].dias_sin_acceso;
                            return real === null ? 'Nunca ha ingresado' : real + ' días sin acceso';
                        }
                    }
                }
            },
            scales: { x: { beginAtZero: true, max: 30 } }
        }
    });
}

/* ============ DETENIDOS ============ */
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
   🆕 NUEVO: MODALES EN LAS LEYENDAS INFERIORES
   ============================================================ */
function bindBottomLegends() {
    // Escuchar clics en las leyendas inferiores que agregamos en el HTML
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

    // Filtrar actividades según la categoría
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
        // Entregadas o +1 semana: estado completada o fecha mayor a hoy + 7 días
        filtradas = allActs.filter(a => {
            if (a.estado === 'completada') return true;
            if (!a.fecha_limite) return false;
            const d = new Date(a.fecha_limite + 'T00:00:00');
            const hoy7 = new Date(); hoy7.setDate(hoy7.getDate() + 7);
            return d > hoy7;
        });
        titulo = '🟢 Entregadas o con +1 semana de margen';
    } else if (cat === 'carga_naranja') {
        // Queda ≤ 1 semana
        filtradas = allActs.filter(a => {
            if (a.estado === 'completada' || !a.fecha_limite) return false;
            const d = new Date(a.fecha_limite + 'T00:00:00');
            const hoy = new Date(); hoy.setHours(0,0,0,0);
            const hoy7 = new Date(); hoy7.setDate(hoy7.getDate() + 7);
            return d >= hoy && d <= hoy7;
        });
        titulo = '🟠 Queda ≤ 1 semana';
    } else if (cat === 'carga_rojo') {
        // Fecha límite vencida
        filtradas = allActs.filter(a => {
            if (a.estado === 'completada' || !a.fecha_limite) return false;
            const d = new Date(a.fecha_limite + 'T00:00:00');
            const hoy = new Date(); hoy.setHours(0,0,0,0);
            return d < hoy;
        });
        titulo = '🔴 Fecha límite vencida';
    } else if (cat === 'acceso_verde') {
        // Nota: Para acceso no tenemos actividades específicas por fecha de login, 
        // así que mostraremos todas las del proyecto, pero agrupadas.
        // (Recomendación: Los usuarios con <=7 días de acceso)
        // Como no tenemos la lista de accesos en JS directamente, mostraremos un mensaje.
        showModal('ℹ️ Información', [], 'actividad');
        document.getElementById('listBody').innerHTML = '<p class="empty-msg">La información de días de acceso se basa en usuarios. <br><br>Revisa la gráfica directamente para ver quién no ha ingresado.</p>';
        document.getElementById('listTitle').textContent = titulo || 'Último acceso';
        document.getElementById('listModal').classList.remove('hidden');
        return;
    } else {
        // Si no cae en ninguna (por ejemplo, avance-tiempo no tiene subcategorías)
        return;
    }

    // Agrupar por responsable
    const agrupadas = {};
    filtradas.forEach(a => {
        const resp = a.responsable || 'Sin asignar';
        if (!agrupadas[resp]) agrupadas[resp] = [];
        agrupadas[resp].push(a);
    });

    // Construir HTML del modal
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
   DESCARGA PDF · Generación con plantilla enriquecida
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

    // Banda guinda superior
    pdf.setFillColor(...PDF_COLORS.guinda);
    pdf.rect(0, 0, pageWidth, 22, 'F');

    // Título sistema
    pdf.setTextColor(...PDF_COLORS.blanco);
    pdf.setFontSize(15);
    pdf.setFont('helvetica', 'bold');
    pdf.text('RTP Kanban · Reporte', 12, 10);

    // Fecha
    pdf.setFontSize(9);
    pdf.setFont('helvetica', 'normal');
    pdf.text(`Generado el ${fechaLarga()}`, 12, 16);

    // Nombre del diagrama
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

    items.forEach(item => {
        const rgb = hexToRgb(item.color);
        pdf.setFillColor(rgb[0], rgb[1], rgb[2]);
        pdf.roundedRect(startX, curY - 3.5, 4, 4, 0.5, 0.5, 'F');

        pdf.setTextColor(...PDF_COLORS.negro);
        pdf.setFont('helvetica', 'normal');
        pdf.text(item.label, startX + 6, curY);

        const pct = ((item.valor || 0) / total * 100).toFixed(1);
        pdf.setTextColor(...PDF_COLORS.gris);
        pdf.setFont('helvetica', 'bold');
        pdf.text(`${item.valor}  (${pct}%)`, pageWidth - 15, curY, { align: 'right' });

        curY += 6.5;
    });

    // Separador
    pdf.setDrawColor(...PDF_COLORS.grisClaro);
    pdf.line(startX, curY - 2, pageWidth - 15, curY - 2);
    curY += 5;

    // Total
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

    // Encabezados
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

    // Filas
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
                { color: '#027a35', label: 'Finalizados',   valor: p.finalizados   || 0 },
                { color: '#E5074C', label: 'Sin finalizar', valor: p.sin_finalizar || 0 },
                { color: '#266cb4', label: 'En curso',      valor: p.en_curso      || 0 }
            ];
        case 'anillo-actividades':
            return [
                { color: '#027a35', label: 'Completadas',  valor: a.completadas  || 0 },
                { color: '#266cb4', label: 'En curso',     valor: a.en_curso     || 0 },
                { color: '#E5074C', label: 'No cumplidas', valor: a.no_cumplidas || 0 }
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
                rows.push([
                    x.nombre,
                    x.estado,
                    (x.avance ?? 0) + '%',
                    x.fecha_fin || '—'
                ]);
            });
            pushRows(p.finalizadosList);
            pushRows(p.sinFinalizarList);
            pushRows(p.enCursoList);
            return { headers, rows, titulo: 'Detalle de proyectos' };
        }
        case 'anillo-actividades': {
            const headers = ['Actividad', 'Proyecto', 'Estado'];
            const rows = [];
            const pushRows = (list) => (list || []).forEach(x => {
                rows.push([
                    x.titulo,
                    x.proyecto_nombre || '—',
                    x.estado
                ]);
            });
            pushRows(a.completadasList);
            pushRows(a.enCursoList);
            pushRows(a.noCumplidasList);
            return { headers, rows, titulo: 'Detalle de actividades' };
        }
        default:
            return null;
    }
}

function downloadChart(chartKey) {
    const chart = CHART_INSTANCES[chartKey];
    if (!chart) { alert('Gráfico no disponible todavía.'); return; }

    const info = CHART_INFO[chartKey] || { titulo: chartKey, desc: '' };
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const pageWidth = pdf.internal.pageSize.getWidth();

    // Header
    pdfHeader(pdf, info.titulo, 'Análisis de datos');

    // Descripción en texto plano
    const textoPlano = (info.desc || '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    let curY = 30;
    pdf.setFontSize(9);
    pdf.setTextColor(...PDF_COLORS.gris);
    pdf.setFont('helvetica', 'normal');
    const splitDesc = pdf.splitTextToSize(textoPlano, pageWidth - 30);
    pdf.text(splitDesc, 15, curY);
    curY += splitDesc.length * 4.5 + 6;

    // Imagen del gráfico
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

    // Leyenda
    const items = getLeyendaItems(chartKey);
    if (items && items.length) {
        curY = pdfLeyenda(pdf, curY, items);
    }

    // Tabla de detalle
    const detalle = getDetalleTabla(chartKey);
    if (detalle && detalle.rows.length) {
        pdf.setFontSize(10);
        pdf.setFont('helvetica', 'bold');
        pdf.setTextColor(...PDF_COLORS.guinda);
        pdf.text(detalle.titulo + ` (${detalle.rows.length})`, 15, curY);
        curY += 6;

        curY = pdfTabla(pdf, curY, detalle.headers, detalle.rows, {
            titulo: info.titulo,
            subtitulo: 'Análisis de datos'
        });
    }

    // Footer en todas las páginas
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

/* ============ MODAL DE LISTAS ============ */
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