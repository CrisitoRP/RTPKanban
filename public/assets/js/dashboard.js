/* ============================================================
   Dashboard RTP · Anillos + Semáforo + Carga + Avance + Acceso
   + Descarga PDF + Info con colores
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
            <p>${dot(COLORS.azul)}<b>% Completado (Azul).</b> Actividades completadas del proyecto entre el total de sus actividades, por 100.</p>
            <p>${dot(COLORS.gris)}<b>% De tiempo transcurrido (Gris).</b> Días desde el inicio del proyecto hasta hoy, entre los días totales entre su fecha de inicio y su fecha final, por 100. Tope: 100%.</p>
            <p><b>Cómo leerlo.</b> Si la barra azul va debajo de la gris, el proyecto lleva menos avance del que su calendario exige.</p>
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

    CHART_INSTANCES['anillo-proyectos'] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Finalizados', 'Sin finalizar', 'En curso'],
            datasets: [{
                data: [d.finalizados, d.sin_finalizar, d.en_curso],
                backgroundColor: [COLORS.verde, COLORS.rojo, COLORS.azul],
                borderWidth: 2, borderColor: '#fff', hoverOffset: 12
            }]
        },
        options: {
            responsive: true, cutout: '70%',
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

    CHART_INSTANCES['anillo-actividades'] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Completadas', 'En curso', 'No cumplidas'],
            datasets: [{
                data: [d.completadas, d.en_curso, d.no_cumplidas],
                backgroundColor: [COLORS.verde, COLORS.azul, COLORS.rojo],
                borderWidth: 2, borderColor: '#fff', hoverOffset: 12
            }]
        },
        options: {
            responsive: true, cutout: '70%',
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
                { label: 'Tiempo',      data: data.map(d => d.avance_tiempo), backgroundColor: COLORS.gris }
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

/* ============ DESCARGA PDF ============ */
function downloadChart(chartKey) {
    const chart = CHART_INSTANCES[chartKey];
    if (!chart) { alert('Gráfico no disponible todavía.'); return; }

    const ahora = new Date();
    const fecha = ahora.toISOString().slice(0, 10);
    const hora = ahora.toTimeString().slice(0, 5).replace(':', '-');

    const info = CHART_INFO[chartKey] || { titulo: chartKey, desc: '' };
    const nombreArchivo = `${chartKey}-${fecha}-${hora}.pdf`;

    const canvas = chart.canvas;
    const imgData = canvas.toDataURL('image/png', 1.0);

    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({
        orientation: canvas.width > canvas.height ? 'landscape' : 'portrait',
        unit: 'mm',
        format: 'a4'
    });

    pdf.setFontSize(16);
    pdf.setTextColor(159, 34, 65);
    pdf.text(info.titulo, 15, 20);

    pdf.setFontSize(10);
    pdf.setTextColor(85, 88, 90);
    pdf.text(`Fecha de descarga: ${fecha} ${hora.replace('-', ':')}`, 15, 28);

    // Convertir el HTML del desc a texto plano para el PDF
    if (info.desc) {
        const textoPlano = info.desc
            .replace(/<[^>]+>/g, '')     // quitar etiquetas HTML
            .replace(/\s+/g, ' ')         // colapsar espacios
            .trim();

        pdf.setFontSize(9);
        const splitDesc = pdf.splitTextToSize(textoPlano, 180);
        pdf.text(splitDesc, 15, 36);
    }

    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const imgWidth = Math.min(pageWidth - 30, 180);
    const imgHeight = (canvas.height * imgWidth) / canvas.width;
    const x = (pageWidth - imgWidth) / 2;
    const y = 70;

    pdf.addImage(imgData, 'PNG', x, y, imgWidth, imgHeight);

    pdf.setFontSize(8);
    pdf.setTextColor(150, 150, 150);
    pdf.text('Sistema de Gestión de Proyectos · RTP', 15, pageHeight - 10);

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