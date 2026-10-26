--
-- PostgreSQL database dump (Limpio - Solo Estructura)
--

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: pg_database_owner
--

CREATE SCHEMA public;

ALTER SCHEMA public OWNER TO pg_database_owner;

COMMENT ON SCHEMA public IS 'standard public schema';

--
-- Name: calcular_avance_proyecto(integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.calcular_avance_proyecto(p_id_proyecto integer) RETURNS numeric
    LANGUAGE plpgsql
    AS $$
DECLARE
    total INTEGER;
    suma NUMERIC := 0;
BEGIN
    SELECT COUNT(*) INTO total
    FROM gestion_actividades
    WHERE id_proyecto = p_id_proyecto;

    IF total = 0 THEN RETURN 0; END IF;

    SELECT COALESCE(SUM(
        CASE estado
            WHEN 'por_hacer'   THEN 0
            WHEN 'en_curso'    THEN 50
            WHEN 'en_revision' THEN 80
            WHEN 'completada'  THEN 100
            WHEN 'bloqueado'   THEN 0
            ELSE 0
        END
    ), 0) INTO suma
    FROM gestion_actividades
    WHERE id_proyecto = p_id_proyecto;

    RETURN ROUND(suma / total, 2);
END;
$$;

ALTER FUNCTION public.calcular_avance_proyecto(p_id_proyecto integer) OWNER TO postgres;

--
-- Name: fn_actualizar_avance_proyecto(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_actualizar_avance_proyecto() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    UPDATE gestion_proyectos
    SET porcentaje_avance = calcular_avance_proyecto(COALESCE(NEW.id_proyecto, OLD.id_proyecto)),
        ultima_actividad_en = CURRENT_TIMESTAMP,
        actualizado_en = CURRENT_TIMESTAMP
    WHERE id_proyecto = COALESCE(NEW.id_proyecto, OLD.id_proyecto);
    RETURN NEW;
END;
$$;

ALTER FUNCTION public.fn_actualizar_avance_proyecto() OWNER TO postgres;

--
-- Name: fn_historial_actividad(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_historial_actividad() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.estado <> NEW.estado THEN
        INSERT INTO gestion_historial_actividad
            (id_actividad, id_usuario, accion, valor_anterior, valor_nuevo)
        VALUES
            (NEW.id_actividad, NEW.asignado_a, 'cambio_estado',
             OLD.estado::TEXT, NEW.estado::TEXT);
    END IF;
    RETURN NEW;
END;
$$;

ALTER FUNCTION public.fn_historial_actividad() OWNER TO postgres;

--
-- Name: fn_historial_proyecto(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_historial_proyecto() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.estado <> NEW.estado THEN
        INSERT INTO gestion_historial_estado_proyecto
            (id_proyecto, estado_anterior, estado_nuevo,
             avance_anterior, avance_nuevo)
        VALUES
            (NEW.id_proyecto, OLD.estado, NEW.estado,
             OLD.porcentaje_avance, NEW.porcentaje_avance);
    END IF;
    RETURN NEW;
END;
$$;

ALTER FUNCTION public.fn_historial_proyecto() OWNER TO postgres;

SET default_tablespace = '';
SET default_table_access_method = heap;

--
-- Name: gestion_actividades; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_actividades (
    id_actividad integer NOT NULL,
    id_proyecto integer NOT NULL,
    titulo character varying(200) NOT NULL,
    descripcion text,
    estado character varying(20) DEFAULT 'por_hacer'::character varying,
    prioridad character varying(20) DEFAULT 'media'::character varying,
    asignado_a integer,
    fecha_limite date,
    posicion integer DEFAULT 0,
    bloqueado_en timestamp without time zone,
    bloqueado_por integer,
    motivo_bloqueo text,
    desbloqueado_en timestamp without time zone,
    desbloqueado_por integer,
    enviada_revision_en timestamp without time zone,
    enviada_revision_por integer,
    aprobada_en timestamp without time zone,
    aprobada_por integer,
    creado_por integer,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    actualizado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    archivada boolean DEFAULT false,
    archivada_en timestamp without time zone,
    archivada_por integer,
    eliminada boolean DEFAULT false,
    eliminada_en timestamp without time zone,
    eliminada_por integer,
    porcentaje_aporte numeric(5,2) DEFAULT 0,
    estado_antes_bloqueo character varying(20),
    fecha_inicio date,
    CONSTRAINT gestion_actividades_estado_check CHECK (((estado)::text = ANY ((ARRAY['por_hacer'::character varying, 'en_curso'::character varying, 'en_revision'::character varying, 'completada'::character varying, 'bloqueado'::character varying])::text[]))),
    CONSTRAINT gestion_actividades_prioridad_check CHECK (((prioridad)::text = ANY ((ARRAY['baja'::character varying, 'media'::character varying, 'alta'::character varying, 'critica'::character varying])::text[])))
);

ALTER TABLE public.gestion_actividades OWNER TO postgres;

--
-- Name: gestion_actividades_id_actividad_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_actividades_id_actividad_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_actividades_id_actividad_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_actividades_id_actividad_seq OWNED BY public.gestion_actividades.id_actividad;

--
-- Name: gestion_actividades_responsables; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_actividades_responsables (
    id_asignacion integer NOT NULL,
    id_actividad integer NOT NULL,
    id_usuario integer NOT NULL,
    asignado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE public.gestion_actividades_responsables OWNER TO postgres;

--
-- Name: gestion_actividades_responsables_id_asignacion_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_actividades_responsables_id_asignacion_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_actividades_responsables_id_asignacion_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_actividades_responsables_id_asignacion_seq OWNED BY public.gestion_actividades_responsables.id_asignacion;

--
-- Name: gestion_adjuntos_actividad; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_adjuntos_actividad (
    id_adjunto integer NOT NULL,
    id_actividad integer NOT NULL,
    tipo character varying(20) NOT NULL,
    nombre_archivo character varying(255),
    ruta_archivo character varying(500),
    url_externa character varying(1000),
    tipo_mime character varying(100),
    tamano_archivo integer,
    subido_por integer,
    subido_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT gestion_adjuntos_actividad_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['archivo'::character varying, 'imagen'::character varying, 'enlace'::character varying])::text[])))
);

ALTER TABLE public.gestion_adjuntos_actividad OWNER TO postgres;

--
-- Name: gestion_adjuntos_actividad_id_adjunto_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_adjuntos_actividad_id_adjunto_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_adjuntos_actividad_id_adjunto_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_adjuntos_actividad_id_adjunto_seq OWNED BY public.gestion_adjuntos_actividad.id_adjunto;

--
-- Name: gestion_comentarios_actividad; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_comentarios_actividad (
    id_comentario integer NOT NULL,
    id_actividad integer NOT NULL,
    id_usuario integer NOT NULL,
    contenido text NOT NULL,
    es_nota_admin boolean DEFAULT false,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE public.gestion_comentarios_actividad OWNER TO postgres;

--
-- Name: gestion_comentarios_actividad_id_comentario_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_comentarios_actividad_id_comentario_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_comentarios_actividad_id_comentario_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_comentarios_actividad_id_comentario_seq OWNED BY public.gestion_comentarios_actividad.id_comentario;

--
-- Name: gestion_eventos_calendario; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_eventos_calendario (
    id_evento integer NOT NULL,
    id_usuario integer NOT NULL,
    id_proyecto integer,
    id_actividad integer,
    titulo character varying(200) NOT NULL,
    descripcion text,
    fecha_evento date NOT NULL,
    color character varying(20) DEFAULT '#3788d8'::character varying,
    tipo_evento character varying(20) DEFAULT 'personalizado'::character varying,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT gestion_eventos_calendario_tipo_evento_check CHECK (((tipo_evento)::text = ANY ((ARRAY['proyecto'::character varying, 'actividad'::character varying, 'personalizado'::character varying])::text[])))
);

ALTER TABLE public.gestion_eventos_calendario OWNER TO postgres;

--
-- Name: gestion_eventos_calendario_id_evento_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_eventos_calendario_id_evento_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_eventos_calendario_id_evento_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_eventos_calendario_id_evento_seq OWNED BY public.gestion_eventos_calendario.id_evento;

--
-- Name: gestion_historial_actividad; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_historial_actividad (
    id_historial integer NOT NULL,
    id_actividad integer NOT NULL,
    id_usuario integer,
    accion character varying(100) NOT NULL,
    valor_anterior text,
    valor_nuevo text,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE public.gestion_historial_actividad OWNER TO postgres;

--
-- Name: gestion_historial_actividad_id_historial_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_historial_actividad_id_historial_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_historial_actividad_id_historial_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_historial_actividad_id_historial_seq OWNED BY public.gestion_historial_actividad.id_historial;

--
-- Name: gestion_historial_bloqueos_actividad; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_historial_bloqueos_actividad (
    id_bloqueo integer NOT NULL,
    id_actividad integer NOT NULL,
    accion character varying(20) NOT NULL,
    motivo text,
    fecha_limite_anterior date,
    fecha_limite_nueva date,
    realizado_por integer,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT gestion_historial_bloqueos_actividad_accion_check CHECK (((accion)::text = ANY ((ARRAY['bloqueo'::character varying, 'desbloqueo'::character varying])::text[])))
);

ALTER TABLE public.gestion_historial_bloqueos_actividad OWNER TO postgres;

--
-- Name: gestion_historial_bloqueos_actividad_id_bloqueo_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_historial_bloqueos_actividad_id_bloqueo_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_historial_bloqueos_actividad_id_bloqueo_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_historial_bloqueos_actividad_id_bloqueo_seq OWNED BY public.gestion_historial_bloqueos_actividad.id_bloqueo;

--
-- Name: gestion_historial_estado_proyecto; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_historial_estado_proyecto (
    id_historial integer NOT NULL,
    id_proyecto integer NOT NULL,
    id_usuario integer,
    estado_anterior character varying(20),
    estado_nuevo character varying(20),
    avance_anterior numeric(5,2),
    avance_nuevo numeric(5,2),
    comentario text,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE public.gestion_historial_estado_proyecto OWNER TO postgres;

--
-- Name: gestion_historial_estado_proyecto_id_historial_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_historial_estado_proyecto_id_historial_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_historial_estado_proyecto_id_historial_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_historial_estado_proyecto_id_historial_seq OWNED BY public.gestion_historial_estado_proyecto.id_historial;

--
-- Name: gestion_historial_proyecto; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_historial_proyecto (
    id_historial integer NOT NULL,
    id_proyecto integer NOT NULL,
    id_usuario integer,
    tipo character varying(30) NOT NULL,
    accion character varying(50) NOT NULL,
    descripcion text NOT NULL,
    metadata jsonb,
    creado_en timestamp without time zone DEFAULT now() NOT NULL
);

ALTER TABLE public.gestion_historial_proyecto OWNER TO postgres;

--
-- Name: gestion_historial_proyecto_id_historial_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_historial_proyecto_id_historial_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_historial_proyecto_id_historial_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_historial_proyecto_id_historial_seq OWNED BY public.gestion_historial_proyecto.id_historial;

--
-- Name: gestion_instantaneas_avance; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_instantaneas_avance (
    id_instantanea integer NOT NULL,
    id_proyecto integer NOT NULL,
    porcentaje_avance numeric(5,2) NOT NULL,
    fecha date DEFAULT CURRENT_DATE
);

ALTER TABLE public.gestion_instantaneas_avance OWNER TO postgres;

--
-- Name: gestion_instantaneas_avance_id_instantanea_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_instantaneas_avance_id_instantanea_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_instantaneas_avance_id_instantanea_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_instantaneas_avance_id_instantanea_seq OWNED BY public.gestion_instantaneas_avance.id_instantanea;

--
-- Name: gestion_invitaciones; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_invitaciones (
    id_invitacion integer NOT NULL,
    id_proyecto integer NOT NULL,
    correo character varying(150) NOT NULL,
    rol_miembro character varying(20) NOT NULL,
    token character varying(255) NOT NULL,
    estado character varying(20) DEFAULT 'pendiente'::character varying,
    expira_en timestamp without time zone NOT NULL,
    creado_por integer,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    aceptado_en timestamp without time zone,
    id_actividad integer,
    CONSTRAINT gestion_invitaciones_estado_check CHECK (((estado)::text = ANY ((ARRAY['pendiente'::character varying, 'aceptada'::character varying, 'rechazada'::character varying, 'expirada'::character varying])::text[]))),
    CONSTRAINT gestion_invitaciones_rol_miembro_check CHECK (((rol_miembro)::text = ANY ((ARRAY['administrador'::character varying, 'responsable'::character varying, 'visualizador'::character varying])::text[])))
);

ALTER TABLE public.gestion_invitaciones OWNER TO postgres;

--
-- Name: gestion_invitaciones_id_invitacion_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_invitaciones_id_invitacion_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_invitaciones_id_invitacion_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_invitaciones_id_invitacion_seq OWNED BY public.gestion_invitaciones.id_invitacion;

--
-- Name: gestion_miembros_proyecto; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_miembros_proyecto (
    id_miembro integer NOT NULL,
    id_proyecto integer NOT NULL,
    id_usuario integer NOT NULL,
    rol_miembro character varying(20) NOT NULL,
    se_unio_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT gestion_miembros_proyecto_rol_miembro_check CHECK (((rol_miembro)::text = ANY ((ARRAY['administrador'::character varying, 'responsable'::character varying, 'visualizador'::character varying])::text[])))
);

ALTER TABLE public.gestion_miembros_proyecto OWNER TO postgres;

--
-- Name: gestion_miembros_proyecto_id_miembro_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_miembros_proyecto_id_miembro_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_miembros_proyecto_id_miembro_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_miembros_proyecto_id_miembro_seq OWNED BY public.gestion_miembros_proyecto.id_miembro;

--
-- Name: gestion_notificaciones; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_notificaciones (
    id_notificacion integer NOT NULL,
    id_usuario integer NOT NULL,
    id_proyecto integer,
    id_actividad integer,
    tipo character varying(40) NOT NULL,
    titulo character varying(200) NOT NULL,
    mensaje text NOT NULL,
    leida boolean DEFAULT false,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    requiere_accion boolean DEFAULT false,
    accion_completada boolean DEFAULT false,
    actividad_pendiente_id integer,
    usuario_a_eliminar_id integer
);

ALTER TABLE public.gestion_notificaciones OWNER TO postgres;

--
-- Name: gestion_notificaciones_id_notificacion_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_notificaciones_id_notificacion_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_notificaciones_id_notificacion_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_notificaciones_id_notificacion_seq OWNED BY public.gestion_notificaciones.id_notificacion;

--
-- Name: gestion_proyectos; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_proyectos (
    id_proyecto integer NOT NULL,
    nombre character varying(200) NOT NULL,
    descripcion text,
    fecha_inicio date,
    fecha_fin date,
    estado character varying(20) DEFAULT 'activo'::character varying,
    prioridad character varying(20) DEFAULT 'media'::character varying,
    id_responsable integer,
    creado_por integer,
    porcentaje_avance numeric(5,2) DEFAULT 0,
    ultima_actividad_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    actualizado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    archivado boolean DEFAULT false,
    archivado_en timestamp without time zone,
    archivado_por integer,
    eliminado boolean DEFAULT false,
    eliminado_en timestamp without time zone,
    eliminado_por integer,
    bloqueado boolean DEFAULT false NOT NULL,
    motivo_bloqueo text,
    bloqueado_en timestamp without time zone,
    bloqueado_por integer,
    fecha_apertura timestamp without time zone,
    abierto boolean DEFAULT true NOT NULL,
    orden_manual integer DEFAULT 0,
    columna_manual character varying(30) DEFAULT NULL::character varying,
    CONSTRAINT gestion_proyectos_estado_check CHECK (((estado)::text = ANY ((ARRAY['activo'::character varying, 'en_riesgo'::character varying, 'detenido'::character varying, 'finalizado'::character varying, 'cancelado'::character varying])::text[]))),
    CONSTRAINT gestion_proyectos_prioridad_check CHECK (((prioridad)::text = ANY ((ARRAY['baja'::character varying, 'media'::character varying, 'alta'::character varying, 'critica'::character varying])::text[])))
);

ALTER TABLE public.gestion_proyectos OWNER TO postgres;

--
-- Name: gestion_proyectos_id_proyecto_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_proyectos_id_proyecto_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_proyectos_id_proyecto_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_proyectos_id_proyecto_seq OWNED BY public.gestion_proyectos.id_proyecto;

--
-- Name: gestion_reasignaciones_pendientes; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_reasignaciones_pendientes (
    id_reasignacion integer NOT NULL,
    id_actividad integer NOT NULL,
    id_usuario_anterior integer,
    id_usuario_nuevo integer,
    motivo text,
    completada boolean DEFAULT false,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    completado_en timestamp without time zone
);

ALTER TABLE public.gestion_reasignaciones_pendientes OWNER TO postgres;

--
-- Name: gestion_reasignaciones_pendientes_id_reasignacion_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_reasignaciones_pendientes_id_reasignacion_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_reasignaciones_pendientes_id_reasignacion_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_reasignaciones_pendientes_id_reasignacion_seq OWNED BY public.gestion_reasignaciones_pendientes.id_reasignacion;

--
-- Name: gestion_usuarios; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.gestion_usuarios (
    id_usuario integer NOT NULL,
    nombre_usuario character varying(80) NOT NULL,
    correo character varying(150) NOT NULL,
    hash_contrasena character varying(255) NOT NULL,
    nombre_completo character varying(150) NOT NULL,
    rol character varying(20) DEFAULT 'visualizador'::character varying NOT NULL,
    activo boolean DEFAULT true,
    url_avatar character varying(255),
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    actualizado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    ultimo_acceso timestamp without time zone,
    umbral_alerta_dias integer DEFAULT 15,
    actividades_finalizadas boolean DEFAULT false,
    actividades_finalizadas_en timestamp without time zone,
    eliminado boolean DEFAULT false,
    eliminado_en timestamp without time zone,
    CONSTRAINT gestion_usuarios_rol_check CHECK (((rol)::text = ANY ((ARRAY['administrador'::character varying, 'responsable'::character varying, 'visualizador'::character varying])::text[])))
);

ALTER TABLE public.gestion_usuarios OWNER TO postgres;

--
-- Name: gestion_usuarios_id_usuario_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.gestion_usuarios_id_usuario_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.gestion_usuarios_id_usuario_seq OWNER TO postgres;

ALTER SEQUENCE public.gestion_usuarios_id_usuario_seq OWNED BY public.gestion_usuarios.id_usuario;

--
-- Name: vista_proyectos_detenidos; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.vista_proyectos_detenidos AS
 SELECT id_proyecto,
    nombre,
    descripcion,
    fecha_inicio,
    fecha_fin,
    estado,
    prioridad,
    id_responsable,
    creado_por,
    porcentaje_avance,
    ultima_actividad_en,
    creado_en,
    actualizado_en
   FROM public.gestion_proyectos
  WHERE (((estado)::text = ANY ((ARRAY['activo'::character varying, 'en_riesgo'::character varying])::text[])) AND (ultima_actividad_en < (now() - '7 days'::interval)));

ALTER VIEW public.vista_proyectos_detenidos OWNER TO postgres;

--
-- Name: vista_resumen_actividades; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.vista_resumen_actividades AS
 SELECT id_proyecto,
    estado,
    count(*) AS total
   FROM public.gestion_actividades
  GROUP BY id_proyecto, estado;

ALTER VIEW public.vista_resumen_actividades OWNER TO postgres;

--
-- Name: vista_resumen_proyectos; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.vista_resumen_proyectos AS
 SELECT estado,
    count(*) AS total,
    round(avg(porcentaje_avance), 2) AS avance_promedio
   FROM public.gestion_proyectos
  GROUP BY estado;

ALTER VIEW public.vista_resumen_proyectos OWNER TO postgres;

--
-- Defaults y Secuencias
--

ALTER TABLE ONLY public.gestion_actividades ALTER COLUMN id_actividad SET DEFAULT nextval('public.gestion_actividades_id_actividad_seq'::regclass);
ALTER TABLE ONLY public.gestion_actividades_responsables ALTER COLUMN id_asignacion SET DEFAULT nextval('public.gestion_actividades_responsables_id_asignacion_seq'::regclass);
ALTER TABLE ONLY public.gestion_adjuntos_actividad ALTER COLUMN id_adjunto SET DEFAULT nextval('public.gestion_adjuntos_actividad_id_adjunto_seq'::regclass);
ALTER TABLE ONLY public.gestion_comentarios_actividad ALTER COLUMN id_comentario SET DEFAULT nextval('public.gestion_comentarios_actividad_id_comentario_seq'::regclass);
ALTER TABLE ONLY public.gestion_eventos_calendario ALTER COLUMN id_evento SET DEFAULT nextval('public.gestion_eventos_calendario_id_evento_seq'::regclass);
ALTER TABLE ONLY public.gestion_historial_actividad ALTER COLUMN id_historial SET DEFAULT nextval('public.gestion_historial_actividad_id_historial_seq'::regclass);
ALTER TABLE ONLY public.gestion_historial_bloqueos_actividad ALTER COLUMN id_bloqueo SET DEFAULT nextval('public.gestion_historial_bloqueos_actividad_id_bloqueo_seq'::regclass);
ALTER TABLE ONLY public.gestion_historial_estado_proyecto ALTER COLUMN id_historial SET DEFAULT nextval('public.gestion_historial_estado_proyecto_id_historial_seq'::regclass);
ALTER TABLE ONLY public.gestion_historial_proyecto ALTER COLUMN id_historial SET DEFAULT nextval('public.gestion_historial_proyecto_id_historial_seq'::regclass);
ALTER TABLE ONLY public.gestion_instantaneas_avance ALTER COLUMN id_instantanea SET DEFAULT nextval('public.gestion_instantaneas_avance_id_instantanea_seq'::regclass);
ALTER TABLE ONLY public.gestion_invitaciones ALTER COLUMN id_invitacion SET DEFAULT nextval('public.gestion_invitaciones_id_invitacion_seq'::regclass);
ALTER TABLE ONLY public.gestion_miembros_proyecto ALTER COLUMN id_miembro SET DEFAULT nextval('public.gestion_miembros_proyecto_id_miembro_seq'::regclass);
ALTER TABLE ONLY public.gestion_notificaciones ALTER COLUMN id_notificacion SET DEFAULT nextval('public.gestion_notificaciones_id_notificacion_seq'::regclass);
ALTER TABLE ONLY public.gestion_proyectos ALTER COLUMN id_proyecto SET DEFAULT nextval('public.gestion_proyectos_id_proyecto_seq'::regclass);
ALTER TABLE ONLY public.gestion_reasignaciones_pendientes ALTER COLUMN id_reasignacion SET DEFAULT nextval('public.gestion_reasignaciones_pendientes_id_reasignacion_seq'::regclass);
ALTER TABLE ONLY public.gestion_usuarios ALTER COLUMN id_usuario SET DEFAULT nextval('public.gestion_usuarios_id_usuario_seq'::regclass);

--
-- Secuencias iniciales (Todo en 1 para empezar desde cero)
--

SELECT pg_catalog.setval('public.gestion_actividades_id_actividad_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_actividades_responsables_id_asignacion_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_adjuntos_actividad_id_adjunto_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_comentarios_actividad_id_comentario_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_eventos_calendario_id_evento_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_historial_actividad_id_historial_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_historial_bloqueos_actividad_id_bloqueo_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_historial_estado_proyecto_id_historial_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_historial_proyecto_id_historial_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_instantaneas_avance_id_instantanea_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_invitaciones_id_invitacion_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_miembros_proyecto_id_miembro_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_notificaciones_id_notificacion_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_proyectos_id_proyecto_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_reasignaciones_pendientes_id_reasignacion_seq', 1, false);
SELECT pg_catalog.setval('public.gestion_usuarios_id_usuario_seq', 1, false);

--
-- Primary Keys y Constraints
--

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_pkey PRIMARY KEY (id_actividad);

ALTER TABLE ONLY public.gestion_actividades_responsables
    ADD CONSTRAINT gestion_actividades_responsables_id_actividad_id_usuario_key UNIQUE (id_actividad, id_usuario);

ALTER TABLE ONLY public.gestion_actividades_responsables
    ADD CONSTRAINT gestion_actividades_responsables_pkey PRIMARY KEY (id_asignacion);

ALTER TABLE ONLY public.gestion_adjuntos_actividad
    ADD CONSTRAINT gestion_adjuntos_actividad_pkey PRIMARY KEY (id_adjunto);

ALTER TABLE ONLY public.gestion_comentarios_actividad
    ADD CONSTRAINT gestion_comentarios_actividad_pkey PRIMARY KEY (id_comentario);

ALTER TABLE ONLY public.gestion_eventos_calendario
    ADD CONSTRAINT gestion_eventos_calendario_pkey PRIMARY KEY (id_evento);

ALTER TABLE ONLY public.gestion_historial_actividad
    ADD CONSTRAINT gestion_historial_actividad_pkey PRIMARY KEY (id_historial);

ALTER TABLE ONLY public.gestion_historial_bloqueos_actividad
    ADD CONSTRAINT gestion_historial_bloqueos_actividad_pkey PRIMARY KEY (id_bloqueo);

ALTER TABLE ONLY public.gestion_historial_estado_proyecto
    ADD CONSTRAINT gestion_historial_estado_proyecto_pkey PRIMARY KEY (id_historial);

ALTER TABLE ONLY public.gestion_historial_proyecto
    ADD CONSTRAINT gestion_historial_proyecto_pkey PRIMARY KEY (id_historial);

ALTER TABLE ONLY public.gestion_instantaneas_avance
    ADD CONSTRAINT gestion_instantaneas_avance_id_proyecto_fecha_key UNIQUE (id_proyecto, fecha);

ALTER TABLE ONLY public.gestion_instantaneas_avance
    ADD CONSTRAINT gestion_instantaneas_avance_pkey PRIMARY KEY (id_instantanea);

ALTER TABLE ONLY public.gestion_invitaciones
    ADD CONSTRAINT gestion_invitaciones_pkey PRIMARY KEY (id_invitacion);

ALTER TABLE ONLY public.gestion_invitaciones
    ADD CONSTRAINT gestion_invitaciones_token_key UNIQUE (token);

ALTER TABLE ONLY public.gestion_miembros_proyecto
    ADD CONSTRAINT gestion_miembros_proyecto_id_proyecto_id_usuario_key UNIQUE (id_proyecto, id_usuario);

ALTER TABLE ONLY public.gestion_miembros_proyecto
    ADD CONSTRAINT gestion_miembros_proyecto_pkey PRIMARY KEY (id_miembro);

ALTER TABLE ONLY public.gestion_notificaciones
    ADD CONSTRAINT gestion_notificaciones_pkey PRIMARY KEY (id_notificacion);

ALTER TABLE ONLY public.gestion_proyectos
    ADD CONSTRAINT gestion_proyectos_pkey PRIMARY KEY (id_proyecto);

ALTER TABLE ONLY public.gestion_reasignaciones_pendientes
    ADD CONSTRAINT gestion_reasignaciones_pendientes_pkey PRIMARY KEY (id_reasignacion);

ALTER TABLE ONLY public.gestion_usuarios
    ADD CONSTRAINT gestion_usuarios_correo_key UNIQUE (correo);

ALTER TABLE ONLY public.gestion_usuarios
    ADD CONSTRAINT gestion_usuarios_nombre_usuario_key UNIQUE (nombre_usuario);

ALTER TABLE ONLY public.gestion_usuarios
    ADD CONSTRAINT gestion_usuarios_pkey PRIMARY KEY (id_usuario);

--
-- Índices
--

CREATE INDEX idx_act_resp_actividad ON public.gestion_actividades_responsables USING btree (id_actividad);
CREATE INDEX idx_act_resp_usuario ON public.gestion_actividades_responsables USING btree (id_usuario);
CREATE INDEX idx_gestion_actividades_archivada ON public.gestion_actividades USING btree (archivada);
CREATE INDEX idx_gestion_actividades_asignado ON public.gestion_actividades USING btree (asignado_a);
CREATE INDEX idx_gestion_actividades_eliminada ON public.gestion_actividades USING btree (eliminada);
CREATE INDEX idx_gestion_actividades_estado ON public.gestion_actividades USING btree (estado);
CREATE INDEX idx_gestion_actividades_porcentaje ON public.gestion_actividades USING btree (porcentaje_aporte);
CREATE INDEX idx_gestion_actividades_proyecto ON public.gestion_actividades USING btree (id_proyecto);
CREATE INDEX idx_gestion_adjuntos_actividad ON public.gestion_adjuntos_actividad USING btree (id_actividad);
CREATE INDEX idx_gestion_comentarios_actividad ON public.gestion_comentarios_actividad USING btree (id_actividad);
CREATE INDEX idx_gestion_eventos_fecha ON public.gestion_eventos_calendario USING btree (fecha_evento);
CREATE INDEX idx_gestion_eventos_usuario ON public.gestion_eventos_calendario USING btree (id_usuario);
CREATE INDEX idx_gestion_hist_bloqueos_actividad ON public.gestion_historial_bloqueos_actividad USING btree (id_actividad);
CREATE INDEX idx_gestion_hist_proyecto ON public.gestion_historial_estado_proyecto USING btree (id_proyecto);
CREATE INDEX idx_gestion_historial_actividad ON public.gestion_historial_actividad USING btree (id_actividad);
CREATE INDEX idx_gestion_instantaneas_proyecto ON public.gestion_instantaneas_avance USING btree (id_proyecto);
CREATE INDEX idx_gestion_invitaciones_correo ON public.gestion_invitaciones USING btree (correo);
CREATE INDEX idx_gestion_invitaciones_token ON public.gestion_invitaciones USING btree (token);
CREATE INDEX idx_gestion_miembros_proyecto ON public.gestion_miembros_proyecto USING btree (id_proyecto);
CREATE INDEX idx_gestion_miembros_usuario ON public.gestion_miembros_proyecto USING btree (id_usuario);
CREATE INDEX idx_gestion_notificaciones_leida ON public.gestion_notificaciones USING btree (leida);
CREATE INDEX idx_gestion_notificaciones_usuario ON public.gestion_notificaciones USING btree (id_usuario);
CREATE INDEX idx_gestion_proyectos_archivado ON public.gestion_proyectos USING btree (archivado);
CREATE INDEX idx_gestion_proyectos_eliminado ON public.gestion_proyectos USING btree (eliminado);
CREATE INDEX idx_gestion_proyectos_estado ON public.gestion_proyectos USING btree (estado);
CREATE INDEX idx_gestion_proyectos_responsable ON public.gestion_proyectos USING btree (id_responsable);
CREATE INDEX idx_gestion_usuarios_correo ON public.gestion_usuarios USING btree (correo);
CREATE INDEX idx_gestion_usuarios_eliminado ON public.gestion_usuarios USING btree (eliminado);
CREATE INDEX idx_gestion_usuarios_rol ON public.gestion_usuarios USING btree (rol);
CREATE INDEX idx_gestion_usuarios_ultimo_acceso ON public.gestion_usuarios USING btree (ultimo_acceso);
CREATE INDEX idx_hist_proyecto ON public.gestion_historial_proyecto USING btree (id_proyecto, creado_en DESC);
CREATE INDEX idx_hist_tipo ON public.gestion_historial_proyecto USING btree (tipo);
CREATE INDEX idx_reasignaciones_pendientes ON public.gestion_reasignaciones_pendientes USING btree (completada);

--
-- Triggers
--

CREATE TRIGGER trg_actividad_avance AFTER INSERT OR DELETE OR UPDATE ON public.gestion_actividades FOR EACH ROW EXECUTE FUNCTION public.fn_actualizar_avance_proyecto();
CREATE TRIGGER trg_historial_actividad AFTER UPDATE ON public.gestion_actividades FOR EACH ROW EXECUTE FUNCTION public.fn_historial_actividad();
CREATE TRIGGER trg_historial_proyecto AFTER UPDATE ON public.gestion_proyectos FOR EACH ROW EXECUTE FUNCTION public.fn_historial_proyecto();

--
-- Foreign Keys
--

ALTER TABLE ONLY public.gestion_historial_proyecto
    ADD CONSTRAINT fk_hist_proyecto FOREIGN KEY (id_proyecto) REFERENCES public.gestion_proyectos(id_proyecto) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_historial_proyecto
    ADD CONSTRAINT fk_hist_usuario FOREIGN KEY (id_usuario) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_invitaciones
    ADD CONSTRAINT fk_invitaciones_actividad FOREIGN KEY (id_actividad) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_proyectos
    ADD CONSTRAINT fk_proyectos_bloqueado_por FOREIGN KEY (bloqueado_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_aprobada_por_fkey FOREIGN KEY (aprobada_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_archivada_por_fkey FOREIGN KEY (archivada_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_asignado_a_fkey FOREIGN KEY (asignado_a) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_bloqueado_por_fkey FOREIGN KEY (bloqueado_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_creado_por_fkey FOREIGN KEY (creado_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_desbloqueado_por_fkey FOREIGN KEY (desbloqueado_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_eliminada_por_fkey FOREIGN KEY (eliminada_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_enviada_revision_por_fkey FOREIGN KEY (enviada_revision_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_actividades
    ADD CONSTRAINT gestion_actividades_id_proyecto_fkey FOREIGN KEY (id_proyecto) REFERENCES public.gestion_proyectos(id_proyecto) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_actividades_responsables
    ADD CONSTRAINT gestion_actividades_responsables_id_actividad_fkey FOREIGN KEY (id_actividad) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_actividades_responsables
    ADD CONSTRAINT gestion_actividades_responsables_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_adjuntos_actividad
    ADD CONSTRAINT gestion_adjuntos_actividad_id_actividad_fkey FOREIGN KEY (id_actividad) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_adjuntos_actividad
    ADD CONSTRAINT gestion_adjuntos_actividad_subido_por_fkey FOREIGN KEY (subido_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_comentarios_actividad
    ADD CONSTRAINT gestion_comentarios_actividad_id_actividad_fkey FOREIGN KEY (id_actividad) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_comentarios_actividad
    ADD CONSTRAINT gestion_comentarios_actividad_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_eventos_calendario
    ADD CONSTRAINT gestion_eventos_calendario_id_actividad_fkey FOREIGN KEY (id_actividad) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_eventos_calendario
    ADD CONSTRAINT gestion_eventos_calendario_id_proyecto_fkey FOREIGN KEY (id_proyecto) REFERENCES public.gestion_proyectos(id_proyecto) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_eventos_calendario
    ADD CONSTRAINT gestion_eventos_calendario_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_historial_actividad
    ADD CONSTRAINT gestion_historial_actividad_id_actividad_fkey FOREIGN KEY (id_actividad) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_historial_actividad
    ADD CONSTRAINT gestion_historial_actividad_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_historial_bloqueos_actividad
    ADD CONSTRAINT gestion_historial_bloqueos_actividad_id_actividad_fkey FOREIGN KEY (id_actividad) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_historial_bloqueos_actividad
    ADD CONSTRAINT gestion_historial_bloqueos_actividad_realizado_por_fkey FOREIGN KEY (realizado_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_historial_estado_proyecto
    ADD CONSTRAINT gestion_historial_estado_proyecto_id_proyecto_fkey FOREIGN KEY (id_proyecto) REFERENCES public.gestion_proyectos(id_proyecto) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_historial_estado_proyecto
    ADD CONSTRAINT gestion_historial_estado_proyecto_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_instantaneas_avance
    ADD CONSTRAINT gestion_instantaneas_avance_id_proyecto_fkey FOREIGN KEY (id_proyecto) REFERENCES public.gestion_proyectos(id_proyecto) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_invitaciones
    ADD CONSTRAINT gestion_invitaciones_creado_por_fkey FOREIGN KEY (creado_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_invitaciones
    ADD CONSTRAINT gestion_invitaciones_id_proyecto_fkey FOREIGN KEY (id_proyecto) REFERENCES public.gestion_proyectos(id_proyecto) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_miembros_proyecto
    ADD CONSTRAINT gestion_miembros_proyecto_id_proyecto_fkey FOREIGN KEY (id_proyecto) REFERENCES public.gestion_proyectos(id_proyecto) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_miembros_proyecto
    ADD CONSTRAINT gestion_miembros_proyecto_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_notificaciones
    ADD CONSTRAINT gestion_notificaciones_actividad_pendiente_id_fkey FOREIGN KEY (actividad_pendiente_id) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_notificaciones
    ADD CONSTRAINT gestion_notificaciones_id_actividad_fkey FOREIGN KEY (id_actividad) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_notificaciones
    ADD CONSTRAINT gestion_notificaciones_id_proyecto_fkey FOREIGN KEY (id_proyecto) REFERENCES public.gestion_proyectos(id_proyecto) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_notificaciones
    ADD CONSTRAINT gestion_notificaciones_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_notificaciones
    ADD CONSTRAINT gestion_notificaciones_usuario_a_eliminar_id_fkey FOREIGN KEY (usuario_a_eliminar_id) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_proyectos
    ADD CONSTRAINT gestion_proyectos_archivado_por_fkey FOREIGN KEY (archivado_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_proyectos
    ADD CONSTRAINT gestion_proyectos_creado_por_fkey FOREIGN KEY (creado_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_proyectos
    ADD CONSTRAINT gestion_proyectos_eliminado_por_fkey FOREIGN KEY (eliminado_por) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_proyectos
    ADD CONSTRAINT gestion_proyectos_id_responsable_fkey FOREIGN KEY (id_responsable) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_reasignaciones_pendientes
    ADD CONSTRAINT gestion_reasignaciones_pendientes_id_actividad_fkey FOREIGN KEY (id_actividad) REFERENCES public.gestion_actividades(id_actividad) ON DELETE CASCADE;

ALTER TABLE ONLY public.gestion_reasignaciones_pendientes
    ADD CONSTRAINT gestion_reasignaciones_pendientes_id_usuario_anterior_fkey FOREIGN KEY (id_usuario_anterior) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

ALTER TABLE ONLY public.gestion_reasignaciones_pendientes
    ADD CONSTRAINT gestion_reasignaciones_pendientes_id_usuario_nuevo_fkey FOREIGN KEY (id_usuario_nuevo) REFERENCES public.gestion_usuarios(id_usuario) ON DELETE SET NULL;

--
-- PostgreSQL database dump complete
--