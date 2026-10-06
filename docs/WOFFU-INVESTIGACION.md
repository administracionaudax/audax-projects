# Sustituir Woffu por un módulo de RR. HH. en Audax Proyectos: investigación legal y propuesta

- **Fecha de la investigación:** 06/10/2026. Todas las fuentes se consultaron ese día; entre paréntesis va la fecha de publicación o actualización de cada una.
- **Ámbito:** Audax Studio, unas 14 personas en plantilla, Valencia (Comunitat Valenciana).
- **Aviso:** esto es una investigación técnica, no asesoramiento jurídico. Lo marcado **[SIN VERIFICAR]** no se ha podido confirmar en una fuente oficial. Las dudas de la sección F tiene que cerrarlas la asesoría laboral antes de dar de baja Woffu.

## Resumen

1. **El registro de jornada es obligatorio desde 2019** (art. 34.9 ET) para todas las empresas, cualquiera que sea su tamaño. Hoy la imputación de horas a tareas de Audax Proyectos **no lo cubre**: el propio SPEC lo deja fuera de alcance (línea 762). Es lo único imprescindible que hace Woffu y la app no hace.
2. **El Real Decreto de registro horario digital sigue sin aprobarse** a 06/10/2026: no está en el BOE. Salió a audiencia pública en octubre de 2025 y, según la prensa, el Consejo de Estado emitió un dictamen desfavorable en marzo de 2026. Aun así conviene construir ya el módulo según su borrador (digital, inalterable, con trazabilidad de los cambios y acceso remoto de la Inspección), porque entraría en vigor a los 20 días de publicarse.
3. **La jornada de 37,5 h no está aprobada** con carácter general: el Congreso rechazó la ley el 10/09/2025. Pero el **convenio estatal de publicidad**, el probable para Audax, ya fija 37,5 h semanales y 35 h en julio y agosto.
4. **VeriFactu:** Hacienda anunció el 05/10/2026 que lo aplaza a octubre de 2028, pero **todavía no hay norma en el BOE** y la sede de la AEAT sigue mostrando el 01/01/2027 para sociedades. La factura electrónica B2B sí tiene ya fechas firmes: la Orden HAC/1028/2026 entró en vigor el 06/10/2026, así que para una empresa de menos de 8 M€ es obligatoria **24 meses después, hacia el 06/10/2028**.

---

## A. Requisitos legales obligatorios

Leyenda: **Ya** = lo cubre Audax Proyectos hoy; **Parcial** = existe una base que hay que ampliar; **No** = falta.

### A.1 Registro de jornada

| Id | Requisito verificable | Fuente | Audax hoy |
|---|---|---|---|
| **L-01** | Hay un registro **diario** de cada persona trabajadora con la **hora concreta de inicio y de fin** de la jornada. Incluye flexibilidad horaria, teletrabajo y jornada completa o parcial. No vale presentar el horario teórico, el calendario ni los partes de horas. | Art. 34.9 ET ([BOE, texto consolidado, act. 03/10/2026](https://www.boe.es/buscar/act.php?id=BOE-A-2015-11430)); CT ITSS 101/2019 ([resumen, Planificación Jurídica](https://www.planificacion-juridica.com/criterio-tecnico-de-la-itss-101-2019-sobre-registro-de-jornada/); [PIMEC](https://pimec.org/es/actualidad/noticia/n/registro-de-jornada-criterio-tecnico-101-2019-sobre-la-actuacion-de-la-inspeccion-de-trabajo-y-segu)) | **No** |
| **L-02** | El sistema es **objetivo, fiable y accesible**: refleja la jornada realmente hecha y se puede verificar. Un registro en papel o fácil de manipular se ha considerado insuficiente. | STJUE C-55/18, CCOO/Deutsche Bank, 14/05/2019 ([Iberley](https://www.iberley.es/jurisprudencia/sentencia-supranacional-n-c-55-18-tjue-14-05-2019-47989769)); SAN 15/02/2022, rec. 356/2021, caso Ferrovial ([ABA Abogadas](https://aba-abogadas.com/el-registro-del-horario-laboral-en-papel-no-es-valido/)) | **No** |
| **L-03** | Las **personas en teletrabajo** también registran inicio y fin; el registro debe «reflejar fielmente» el tiempo dedicado. | Art. 14 de la Ley 10/2021 de trabajo a distancia ([BOE](https://www.boe.es/buscar/act.php?id=BOE-A-2021-11472)) | **No** |
| **L-04** | La forma de llevar el registro se **organiza y documenta** por convenio, por acuerdo de empresa o, en su defecto, por **decisión del empresario previa consulta a la representación legal** de los trabajadores, si la hay. Tiene que existir un documento de implantación del nuevo sistema. | Art. 34.9 ET; CT 101/2019 | **No** (es un documento, no código) |
| **L-05** | Los registros se **conservan 4 años** y en ese tiempo no se pueden borrar. | Art. 34.9 ET; art. 12.4.c ET para los resúmenes mensuales del tiempo parcial | **No**. La retención de D-075 no contempla este dato. |
| **L-06** | Los registros están **a disposición de cada persona trabajadora, de sus representantes y de la ITSS**, accesibles de inmediato desde el centro de trabajo. La ITSS puede pedir impresiones o descargas en la visita. | Art. 34.9 ET; CT 101/2019 | **No** |
| **L-07** | **Horas extraordinarias:** se registran día a día y se totalizan en el periodo de pago, y se entrega al trabajador copia del resumen en el recibo de salarios. Máximo 80 horas al año. Con el convenio de publicidad se totalizan **semanalmente** con copia del resumen semanal, se informa cada mes a la representación legal, se compensan preferentemente con descanso (1 hora extra = 80 minutos) en los 4 meses siguientes y, si se pagan, con un recargo del 35 %. | Arts. 35.2 y 35.5 ET; art. 22 del convenio de publicidad ([BOE-A-2016-1290](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2016-1290)) | **No** |
| **L-08** | **Tiempo parcial:** registro día a día, totalización mensual, copia al trabajador junto con la nómina y conservación de los resúmenes mensuales durante 4 años. Si no se hace, **se presume jornada completa**. | Art. 12.4.c ET | **No**. La app ya admite jornadas parciales en `work_schedules`. |
| **L-09** | El registro permite **controlar los límites de jornada**: <br>- 40 h semanales de promedio anual (art. 34.1), o lo que fije el convenio: 37,5 h semanales, 35 h del 1/7 al 31/8, de lunes a jueves hasta las 19:00 y los viernes 7 h hasta las 15:00 (art. 21 del convenio de publicidad), <br>- 9 h ordinarias diarias (34.3), <br>- 12 h de descanso entre jornadas (34.3), <br>- 15 min de pausa si la jornada continuada pasa de 6 h (34.4), <br>- día y medio de descanso semanal (37.1). | ET ([BOE](https://www.boe.es/buscar/act.php?id=BOE-A-2015-11430)); convenio ([BOE-A-2016-1290](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2016-1290)) | **Parcial**: `work_schedules` guarda los minutos por día, pero no el horario ni las pausas. |
| **L-10** | **Desconexión digital:** la empresa elabora una **política interna** de desconexión, previa audiencia a la representación legal si la hay. | Art. 88.3 LOPDGDD ([BOE](https://www.boe.es/buscar/act.php?id=BOE-A-2018-16673)); art. 18 de la Ley 10/2021 | **No** (es un documento; la app podría alojarlo y registrar quién lo ha leído) |
| **L-11** | **Protección de datos del registro:** <br>- la base jurídica es la obligación legal, sin consentimiento, <br>- se **informa** a la plantilla de la existencia y la finalidad del registro, <br>- se aplica la minimización, <br>- **no se usa para otras finalidades**, <br>- no se expone en lugares públicos, <br>- se suprime cuando deja de ser necesario, <br>- si lo lleva un proveedor externo, este es encargado del tratamiento. | AEPD, [«El registro de jornada laboral y la protección de datos»](https://laboratorio.aepd.es/blog/el-registro-de-jornada-laboral-y-la-proteccion-de-datos) (17/02/2026); AEPD, [Guía Protección de datos y relaciones laborales](https://www.aepd.es/guias/la-proteccion-de-datos-en-las-relaciones-laborales.pdf) | **Parcial**: hay texto RGPD con versión, lectura registrada y exportación (D-075 y D-125), pero no cubre el registro. |
| **L-12** | **Geolocalización:** solo se permite si antes se informa de forma expresa, clara e inequívoca a la plantilla y a la representación legal, y con proporcionalidad: como mucho al fichar, nunca un seguimiento continuo. | Art. 90 LOPDGDD ([BOE](https://www.boe.es/buscar/act.php?id=BOE-A-2018-16673)); AEPD (17/02/2026, misma URL) | No aplica si no se usa, que es lo recomendado. |
| **L-13** | **Biometría (huella o cara): no se usa.** La AEPD considera que el consentimiento del trabajador no es válido y que haría falta una norma con rango de ley que la habilite. | AEPD, [nota de prensa](https://www.aepd.es/prensa-y-comunicacion/notas-de-prensa/la-aepd-publica-una-guia-sobre-la-utilizacion-de-datos) y [guía](https://www.aepd.es/guias/guia-control-presencia-biometrico.pdf) (23/11/2023) | No aplica |
| **L-14** | **Trazabilidad de las correcciones:** un fichaje no se sobrescribe. Toda corrección deja constancia del valor original, de quién la hace, cuándo y por qué. Hoy es una exigencia práctica de la fiabilidad (L-02); el borrador del RD la hace explícita. | L-02; borrador del RD ([eldiario.es, 09/10/2025](https://www.eldiario.es/economia/registro-jornada-propone-trabajo-acceso-remoto-inspeccion-desvele-horas-extra_1_12669987.html); [Autónomos y Emprendedor, 09/10/2025](https://www.autonomosyemprendedor.es/articulo/laboral/es-texto-decreto-registro-horario-que-trabajo-ha-sacado-audiencia-publica/20251009112914045913.html)) | **Parcial**: la auditoría con `activitylog` existe, pero no el registro. |
| **L-15** | **Sanciones vigentes:** <br>- incumplir el registro o la normativa de jornada, horas extra, descansos, vacaciones o permisos es una **infracción grave** (art. 7.5 LISOS), <br>- grave: de 751 € a 7.500 € (mínimo 751–1.500; medio 1.501–3.750; máximo 3.751–7.500), <br>- leve: de 70 € a 750 €, <br>- muy grave: de 7.501 € a 225.018 € (art. 40.1 LISOS), <br>- la **multa «por trabajador afectado»** era una propuesta del proyecto de ley de 37,5 h, que se rechazó: **no está en vigor**. | Art. 7.5 LISOS ([BOE, act. 03/10/2026](https://www.boe.es/buscar/act.php?id=BOE-A-2000-15060)); cuantías del art. 40 tomadas de [Kronjop (08/04/2026)](https://kronjop.com/es/newsroom/control-horario/ley/sanciones/), porque el art. 40 no se pudo leer en el BOE **[SIN VERIFICAR en el BOE]** | — |
| **L-16** | **Carga de la prueba:** <br>- sin registro, con horario fijo y conocido, el trabajador tiene que aportar indicios de las horas extra, <br>- con horario irregular, la carga de probar pasa a la empresa, <br>- Audax tiene flexibilidad horaria, así que **sin un registro fiable soporta el riesgo de reclamaciones de horas extra**. | STS 372/2026, de 15/04/2026, rec. 674/2025 ([Economist & Jurist, 29/06/2026](https://www.economistjurist.es/articulos-juridicos-destacados/el-supremo-aclara-cuando-la-falta-de-registro-horario-invierte-la-carga-de-la-prueba/)) | — |

**Real Decreto de registro horario digital: estado a 06/10/2026**

- **Tramitación:**
  - el proyecto salió a audiencia pública del 10 al 20/10/2025 ([MITES, Participación pública](https://expinterweb.mites.gob.es/participa/listado?tramite=2&estado=2); [idealista, 09/10/2025](https://www.idealista.com/news/finanzas/laboral/2025/10/09/866964-la-reforma-del-registro-horario-estara-en-audiencia-publica-los-proximos-10-dias)),
  - el Consejo de Estado emitió un dictamen desfavorable el 23/03/2026 por la **reserva de ley**, porque un reglamento no podría imponer por sí solo el formato digital ([Qworker, 20/09/2026](https://qworker.es/blog/registro-horario-digital-consejo-estado-reserva-ley); [esisoluciones, 21/07/2026](https://esisoluciones.es/fichajes/registro-horario-digital-2026-estado-normativa/)). **[SIN VERIFICAR en el Consejo de Estado ni en el BOE]**,
  - el Gobierno anunció la aprobación para septiembre de 2026 y no la cumplió: no estaba en el Consejo de Ministros del 29/09/2026 ([jornalo, revisado el 30/09/2026](https://jornalo.es/blog/ley-fichaje-digital-boe/)). **No hay nada en el BOE.**
- **Contenido del borrador**, que es lo que hay que prever ([eldiario.es](https://www.eldiario.es/economia/registro-jornada-propone-trabajo-acceso-remoto-inspeccion-desvele-horas-extra_1_12669987.html); [Autónomos y Emprendedor](https://www.autonomosyemprendedor.es/articulo/laboral/es-texto-decreto-registro-horario-que-trabajo-ha-sacado-audiencia-publica/20251009112914045913.html)):
  - registro **digital**,
  - el **propio trabajador** registra de forma «directa, inmediata, personal», al empezar y al terminar cada situación,
  - campos:
    - identificación de la persona,
    - régimen de jornada,
    - inicio y fin con hora y minuto,
    - pausas,
    - modalidad: presencial o a distancia,
    - tipo de hora: ordinaria, extraordinaria o complementaria,
    - si las horas extra se compensan con descanso o se pagan,
    - interrupciones de la desconexión,
    - medidas de conciliación,
    - totales diarios y mensuales,
    - autoría y autorización de cada modificación,
  - **correcciones:**
    - las acuerdan empresa y trabajador y dejan una «huella clara e indeleble»,
    - si no hay acuerdo, queda constancia de la discrepancia,
  - **acceso remoto e inmediato de la ITSS**,
  - la representación legal accede con datos minimizados,
  - cada trabajador consulta y copia los suyos, y recibe un resumen mensual con la nómina,
  - conservación de 4 años,
  - **entrada en vigor a los 20 días de publicarse** (algunas fuentes hablan de plazos escalonados **[SIN VERIFICAR]**).
- **Reducción a 37,5 h:** el Congreso la rechazó el 10/09/2025 y no está en vigor ([Securex](https://securexrrhh.com/jornada-de-375-horas-en-2026-esta-en-vigor/); [controllaboral](https://controllaboral.es/reduccion-jornada-laboral/)). El máximo legal sigue en 40 h. A Audax le afecta solo por el convenio (L-09).

### A.2 Vacaciones, permisos, festivos y calendario

| Id | Requisito verificable | Fuente | Audax hoy |
|---|---|---|---|
| **L-17** | **Vacaciones:** <br>- mínimo legal de 30 días naturales, que no se pueden cambiar por dinero salvo al terminar el contrato, <br>- con el **convenio de publicidad: 22 días laborables**, <br>- 23 días en los años 2022 a 2025 en que el IPC de diciembre del año anterior superó el 4 %, <br>- devengo proporcional al tiempo trabajado, contando como mes entero la fracción de mes. | Art. 38.1 ET; art. 23 del convenio ([BOE-A-2016-1290](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2016-1290)); acta de revisión ([BOE-A-2022-13573](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2022-13573)) | **Parcial**: hay solicitudes de vacaciones, pero **no saldos**. |
| **L-18** | **Calendario de vacaciones:** <br>- se fija en cada empresa, con la representación legal según el convenio, <br>- **cada persona conoce sus fechas al menos 2 meses antes** de empezar a disfrutarlas, <br>- el convenio las sitúa preferentemente entre el 1/6 y el 30/9. | Art. 38.3 ET; art. 23 del convenio | **No** se controla la antelación. |
| **L-19** | **Coincidencia de vacaciones con una incapacidad temporal o un nacimiento:** <br>- se tiene derecho a disfrutarlas en otra fecha, incluso fuera del año natural, <br>- si es una IT por otras causas, como mucho **18 meses** desde el final del año en que se generaron. | Art. 38.3 ET | **No** |
| **L-20** | **Permisos retribuidos**, con previo aviso y justificación (art. 37.3 ET): <br>- 15 días naturales por matrimonio o pareja de hecho, <br>- **5 días** por accidente, enfermedad grave, hospitalización o intervención con reposo de un familiar hasta 2.º grado o de un conviviente, <br>- **2 días por fallecimiento**, más 2 si hay desplazamiento, <br>- 1 día por traslado de domicilio, <br>- deber inexcusable, <br>- funciones de representación, <br>- exámenes prenatales, <br>- **hasta 4 días** por imposibilidad de acudir por catástrofe o un aviso de la autoridad (37.3.g), <br>- donación de órganos. <br>**Además, según el convenio de publicidad (art. 24), donde mejore la ley:** <br>- 4 días por fallecimiento, o 5 con desplazamiento y 1 más si son 600 km o más, <br>- 2 días por traslado, <br>- 1 día por boda de un familiar, <br>- exámenes, <br>- acompañamiento médico urgente hasta 16 h al año, <br>- lactancia acumulable en 15 días laborables. | Art. 37.3 ET, redacción del RDL 5/2023 y de la Ley 6/2024 ([BOE](https://www.boe.es/buscar/act.php?id=BOE-A-2015-11430)); convenio ([BOE-A-2016-1290](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2016-1290)) | **Parcial**: solo existe el tipo genérico `leave`, sin catálogo, duración, unidad ni justificante. |
| **L-21** | **Fuerza mayor familiar:** ausencia por motivos familiares urgentes, de la que se pagan **las horas equivalentes a 4 días al año**. Se lleva un contador en horas. | Art. 37.9 ET | **No** |
| **L-22** | **Permiso parental:** 8 semanas hasta que el menor cumpla 8 años, sin retribución, con 10 días de preaviso. **Nacimiento:** 19 semanas (art. 48.4, RDL 9/2025). **Lactancia** (37.4). **Reducción por guarda legal** (37.6), con nueva redacción de la **Ley 4/2026** (BOE 03/10/2026, **en vigor el 23/10/2026**). Todos son tipos de ausencia o cambios de jornada que hay que poder registrar. | Arts. 48 bis, 48.4, 37.4 y 37.6 ET; [Ley 4/2026](https://www.boe.es/buscar/doc.php?id=BOE-A-2026-20528) | **Parcial**: las jornadas versionadas sirven para las reducciones; los tipos no existen. |
| **L-23** | **Fiestas laborales:** <br>- como mucho 14 al año, retribuidas y no recuperables, de las que 2 son locales (37.2), <br>- la empresa elabora cada año el **calendario laboral** y lo **expone en un lugar visible** de cada centro (34.6), <br>- en 2027 la Comunitat Valenciana tiene 12 festivos autonómicos (Decreto 42/2026, DOGV del 25/03/2026; **sin San Juan y sin el 15 de agosto**), más 2 locales de Valencia, <br>- el convenio de publicidad añade el **25 de enero** como fiesta profesional (se traslada al primer viernes siguiente si no cae en viernes) y el **24 y el 31 de diciembre** como permiso retribuido. | Arts. 37.2 y 34.6 ET; [Valencia Bonita, 21/03/2026](https://www.valenciabonita.es/2026/03/21/calendario-laboral-2027-comunitat-valenciana/) **[SIN VERIFICAR en el DOGV]**; art. 23 del convenio | **Parcial**: hay festivos e importación (D-050), pero la importación «nacional» automática **no coincide** con el calendario autonómico real (en 2027 añadiría el 15 de agosto, que en la Comunitat cae en domingo y no es festivo, y no añade San José ni el Lunes de Pascua). |
| **L-24** | **Datos de salud de las ausencias** (bajas y justificantes médicos): acceso restringido y minimización. | RGPD, art. 9; D-088 de la app | **Ya**: solo ven el tipo la persona, su responsable y el admin. Los justificantes adjuntos no existen todavía. |

**Convenio aplicable:**

- **Lo más probable:** el **Convenio colectivo estatal para las empresas de publicidad**:
  - código 99004225011981,
  - texto de 2015-2016 publicado el 10/02/2016 y prorrogado,
  - se sigue revisando: las tablas de 2026 son el [BOE-A-2026-9501](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2026-9501), del 01/05/2026,
  - se aplica a quien hace «publicidad» según el art. 2 de la Ley 34/1988.
- **Alternativa:** si la actividad principal se considera consultoría, servicios digitales o TI, podría aplicarse el **XIX Convenio de consultoría, TI y estudios de mercado**:
  - [BOE-A-2025-7766](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2025-7766), del 16/04/2025, vigente de 2025 a 2027,
  - lo decide la actividad principal real de la empresa: hay que confirmarlo con la asesoría (F-1).
- **No encontré** convenio provincial de publicidad para Valencia **[SIN VERIFICAR]**.

---

## B. Recomendaciones no obligatorias hoy (reducen el riesgo)

1. **Registrar las pausas**, sobre todo la comida. No es obligatorio, pero la Guía del Ministerio (13/05/2019) lo recomienda: si no se registran, todo el tiempo entre la entrada y la salida se presume trabajo efectivo ([Noticias Jurídicas](https://noticias.juridicas.com/actualidad/noticias/13958-el-ministerio-de-trabajo-publica-una-guia-sobre-el-registro-de-jornada-/); [Uría, guía](https://www.uria.com/documentos/circulares/1087/documento/8566/191-mayo-especial.pdf)). Además, el borrador del RD lo exige.
2. **Diseñar ya según el borrador del RD:**
   - hora del servidor,
   - registro solo de alta (*append-only*),
   - corrección con doble conformidad y constancia de la discrepancia,
   - modalidad presencial o en remoto,
   - tipo de hora,
   - perfil de **solo lectura para la ITSS** que pueda activarse,
   - exportación inmediata.
3. **Resumen mensual con acuse de recibo** de cada persona, enviado con la nómina. Woffu hace algo parecido: pide cada mes confirmar las jornadas y, una vez confirmadas, solo un responsable puede «desconfirmarlas» para corregirlas ([ayuda de Woffu](https://woffu.my.site.com/help/s/article/confirmar-horarios?language=es)). Para el tiempo parcial y las horas extra ya es obligatorio (L-07 y L-08).
4. **Sin geolocalización ni biometría.** Para 14 personas en oficina y en teletrabajo no hacen falta y abren riesgos RGPD (L-12 y L-13).
5. **Integridad verificable**, más allá de la ley: hash encadenado de los fichajes y huella SHA-256 en cada exportación, para demostrar que no se han tocado.
6. **Avisos preventivos:**
   - fichaje olvidado (sin cerrarlo nunca de forma automática, sino marcándolo como incidencia),
   - menos de 12 h de descanso entre jornadas,
   - más de 6 h seguidas sin pausa,
   - más de 9 h ordinarias,
   - horas extra cerca de 80 al año,
   - vacaciones del año sin planificar a 2 meses vista.
7. **No cruzar el registro de jornada con la imputación de horas** para medir la productividad sin el visto bueno de la asesoría. La AEPD dice que el registro **no puede usarse para otras finalidades** (L-11). Como mucho, cada persona ve sus dos datos.
8. **Documentos de RR. HH. relacionados que Woffu no resuelve:**
   - **protocolo frente al acoso sexual y por razón de sexo**, obligatorio para todas las empresas (LO 3/2007, art. 48) **[SIN VERIFICAR en esta sesión]**,
   - el canal de denuncias (Ley 2/2023) **no** es obligatorio por debajo de 50 personas.
9. **Ejecución en paralelo** con Woffu durante un mes entero antes de darlo de baja.

---

## C. VeriFactu y facturación

| Tema | Estado a 06/10/2026 | Fuente |
|---|---|---|
| **VeriFactu** (RD 1007/2023, Reglamento de sistemas informáticos de facturación) | **Fecha legal vigente:** <br>- **01/01/2027** para quien declara el Impuesto sobre Sociedades, <br>- **01/07/2027** para el resto, <br>- según el RDL 15/2025, de 2 de diciembre (BOE del 03/12/2025, convalidado; BOE del 16/12/2025). <br>La sede de la AEAT **sigue mostrando esas fechas**. | [AEAT, nota informativa](https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/nota-informativa-ampliacion-plazo-adaptacion-facturacion.html); [Iberley](https://www.iberley.es/noticias/el-congreso-convalida-real-decreto-ley-que-retrasa-veri-factu-2027-35783) |
| **Aplazamiento a octubre de 2028** | **Es cierto que se ha anunciado y aún no es norma:** <br>- el 05/10/2026 Hacienda publicó una nota informativa que aplaza las obligaciones pendientes del RD 1007/2023 **hasta octubre de 2028**, para sociedades y autónomos, <br>- lo hace para alinearlo con la factura electrónica B2B de quien factura 8 M€ o menos y con la directiva europea ViDA, <br>- se mantienen los requisitos de integridad, conservación, accesibilidad, legibilidad, trazabilidad e inalterabilidad, <br>- **no se ha publicado en el BOE** y no he encontrado qué norma lo hará: hace falta rango de ley o un RD que cambie el RDL 15/2025 **[SIN VERIFICAR]**. <br>Hasta que se publique, la prudencia aconseja tratar el 01/01/2027 como la fecha válida si Audax es una sociedad. | [idealista, 05/10/2026](https://www.idealista.com/news/finanzas/economia/2026/10/05/917495-hacienda-aplaza-a-octubre-de-2028-la-implantacion-del-sistema-de-facturacion); [The Objective, 05/10/2026](https://theobjective.com/economia/2026-10-05/hacienda-pospone-verifactu-octubre-2028/); [elEconomista, 10/2026](https://www.eleconomista.es/economia/noticias/14018805/10/26/hacienda-vuelve-a-retrasar-la-entrada-en-vigor-de-verifactu-hasta-octubre-de-2028.html) |
| **Factura electrónica B2B** (Ley 18/2022, Crea y Crece) | **Ya tiene fechas firmes:** <br>- el **RD 238/2026**, de 25 de marzo, se publicó en el BOE del 31/03/2026, <br>- la **Orden HAC/1028/2026**, de 2 de octubre, se publicó en el BOE del 05/10/2026 y **entró en vigor el 06/10/2026**; con ella empiezan a contar los plazos, <br>- quien factura más de 8 M€: a los 12 meses, hacia el 06/10/2027, <br>- **quien factura 8 M€ o menos (Audax): a los 24 meses, hacia el 06/10/2028**, <br>- la obligación de informar del estado de la factura (aceptación y pago) llega 12 meses después solo para las personas físicas de 8 M€ o menos (DT 3.ª); **para una sociedad pequeña la DT 3.ª no da ese aplazamiento**: confirmarlo con la asesoría fiscal, <br>- formatos estructurados: UBL, CII, EDIFACT o Facturae, con el modelo semántico EN 16931 (no vale un PDF), <br>- hay una solución pública y gratuita de la AEAT. | [BOE-A-2026-7295](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2026-7295); [BOE-A-2026-20587](https://www.boe.es/buscar/doc.php?id=BOE-A-2026-20587); [AEAT, 31/03/2026](https://sede.agenciatributaria.gob.es/Sede/todas-noticias/2026/marzo/31/facturacion-electronica-obligatoria.html) |
| **Lo que sigue vigente mientras tanto** | - El **Reglamento de facturación (RD 1619/2012)**: contenido obligatorio de la factura completa o simplificada, numeración correlativa, plazos de expedición y conservación (art. 19). <br>- La conservación durante el plazo de prescripción tributaria (4 años, LGT) y **6 años** de libros y justificantes (art. 30 del Código de Comercio) **[SIN VERIFICAR en esta sesión; dato de conocimiento general]**. <br>- El SII solo si se factura más de 6 M€ o en otros supuestos (no aplica). | [RD 1619/2012](https://www.boe.es/buscar/act.php?id=BOE-A-2012-14696) |
| **¿Afecta a Audax Proyectos?** | **No hoy.** La app no emite facturas: solo guarda `invoice_reference` y bloquea horas «al facturar». Si algún día las emitiera, sería un sistema informático de facturación y tendría que cumplir el reglamento VeriFactu y el de la factura electrónica. **No se recomienda.** Lo razonable es facturar con un programa homologado y conectarlo con la app. | Código: `SPEC.md` §4 y §8 |

---

## D. Woffu frente a Audax Proyectos

Funcionalidades de Woffu según su web ([woffu.com/es](https://www.woffu.com/es/); [control horario](https://woffu.com/en/time-tracking-and-workday-logging/)) y su ayuda pública ([confirmar fichajes](https://woffu.my.site.com/help/s/article/confirmar-horarios?language=es); [control de presencia](https://woffu.my.site.com/help/s/article/control-de-presencia-y-control-horario?language=es)). Los precios no son públicos.

| Funcionalidad de Woffu | ¿Hace falta a 14 personas? | Audax Proyectos hoy |
|---|---|---|
| Fichaje de entrada y salida por web y app (Woffu tiene 10 métodos: web, app, Slack, Teams, QR, PIN, terminales…) | **Sí (L-01)** | **No**. El temporizador y la imputación de horas son a tareas y no son registro de jornada. |
| Pausas y tipos de pausa | Recomendado (B-1) | **No** |
| Geolocalización y restricción por IP | No (B-4) | No |
| Fichaje biométrico | No (L-13) | No |
| Solicitudes de corrección de fichajes con aprobación e historial original frente a modificado | **Sí (L-14)** | **No**, aunque hay infraestructura de auditoría (`LogsDomainActivity`). |
| Confirmación mensual de jornadas por el empleado (por email) y bloqueo tras confirmar | Recomendado (B-3) | **No**. Hay un patrón parecido en las semanas de horas (`TimesheetPeriod`) y en los bloqueos. |
| Cálculo automático de horas ordinarias y extra, y bolsa de horas en tiempo real | **Sí** si hay horas extra (L-07) | **No**. Ojo: la «bolsa de horas» de Audax (`HourBank`) es de **clientes**, no de jornada. Hay que buscar otro nombre, por ejemplo «saldo de jornada». |
| Políticas horarias (horario, flexibilidad, jornada de verano) | Sí (L-09) | **Parcial**: `work_schedules` versionadas en minutos por día, sin hora de inicio ni de fin y sin jornada de verano automática. |
| Solicitudes de vacaciones y ausencias con aprobación del responsable | Sí | **Ya** (D-049, D-088, D-091), con notificaciones en la app y por email. |
| Tipos de permiso según el convenio, con duración y unidad automáticas | Sí (L-20 a L-22) | **Parcial**: cinco tipos fijos (`AbsenceType`). |
| Saldo de vacaciones generadas y consumidas | Sí (L-17) | **No** |
| Calendario del equipo | Sí | **Ya**, con las ausencias y los festivos. |
| Calendarios de festivos | Sí (L-23) | **Ya** (D-050), aunque la importación nacional automática no es fiable para la Comunitat Valenciana. |
| Turnos y cuadrantes | No | No |
| Informes legales del registro para la Inspección (Excel o PDF) | **Sí (L-06)** | **No**. Hay infraestructura de exportación a PDF y XLSX. |
| Gestor documental, envío de nóminas y firma digital | No es obligatorio | **No**. Los adjuntos existen, pero para tareas. |
| Tablón de anuncios con confirmación de lectura y chat | No | **Parcial**: hay chat (F6) y lectura registrada del texto RGPD. |
| Canal de denuncias | No por debajo de 50 personas | No |
| Evaluación del desempeño y formación | No | Parcial: weeklies y resúmenes con IA. |
| Integración con nóminas (A3, Sage…) | No | No. Basta con una exportación CSV para la gestoría. |
| Alertas de fichaje olvidado | Recomendado | **Infraestructura sí**: notificaciones, push, *scheduler* y Horizon. |
| Roles y permisos por equipo | Sí | **Ya**: admin, responsable de departamento, empleado y colaborador. Los colaboradores externos no fichan porque no son plantilla. |
| Auditoría, retención, exportación RGPD, doble factor | Sí | **Ya** (D-075, D-125 y D-126), pero hay que añadir la retención mínima de 4 años del registro. |

---

## E. Propuesta de alcance: entregas para cumplir sin riesgo de sanción

Principio: **el registro de jornada es un dominio aparte de la imputación de horas**. Tiene sus propias tablas, sus permisos y su finalidad RGPD.

**E1. Registro de jornada (núcleo legal; cumple L-01 a L-03, L-09 y L-14)**

- **Tabla `clock_events` solo de alta**, una fila por evento:
  - campos: `user_id`, `type` (inicio, inicio de pausa, fin de pausa, fin), `occurred_at` (UTC, **hora del servidor**), `mode` (presencial o en remoto), `source` (web o PWA), `created_by`,
  - **sin UPDATE ni DELETE**, ni siquiera el admin; garantizado con una política y un *trigger* en PostgreSQL,
  - hash encadenado (B-5).
- **Correcciones:**
  - son filas nuevas (`clock_corrections`) que apuntan al original, con el valor propuesto, el motivo, el autor, la conformidad del trabajador y de la empresa y, si no hay acuerdo, la **discrepancia** anotada,
  - la vista «efectiva» se calcula y nunca se pisa el original.
- **Botón de fichar** en la cabecera, junto al temporizador actual, y en la PWA, con estado visible («Trabajando desde las 9:02 · En pausa»).
- **Totales:**
  - jornada efectiva diaria, sin pausas,
  - tipo de hora (ordinaria, extra, complementaria) **contra la jornada teórica**,
  - campos nuevos en `work_schedules`: horario, pausa y jornada de verano del convenio.
- **Avisos** (B-6): fichaje sin cerrar, descanso entre jornadas, pausa a partir de 6 h, 9 h, 80 h extra al año. Los fichajes sin cerrar quedan como «incidencia» y **nunca se cierran solos**.
- **Tests:** Pest para la inmutabilidad, las correcciones, los totales y los casos de jornada parcial y de verano; E2E para fichar y corregir.

**E2. Acceso, resúmenes e Inspección (L-05 a L-08)**

- «**Mi registro**»: cada persona consulta y **descarga** sus registros de cualquier periodo en PDF y CSV.
- **Resumen mensual:**
  - incluye el total ordinario, las horas extra con su compensación y las complementarias,
  - se genera el día 1 y se envía por notificación y por email,
  - queda el **acuse o conformidad** de la persona; su disconformidad se anota y no bloquea,
  - **resumen semanal de horas extra** si hubo alguna (convenio, L-07).
- **Exportación para la ITSS:**
  - por persona o por toda la plantilla y por rango de fechas, en PDF y CSV,
  - con huella SHA-256 y el historial de correcciones,
  - disponible al momento para el admin.
- **Rol `inspector`** de solo lectura, desactivado por defecto: una cuenta temporal con caducidad, doble factor y auditoría de cada acceso, preparada para el «acceso remoto» del RD.
- **Acceso de la representación legal** con datos minimizados, si llega a haberla.

**E3. Conservación, RGPD y documentos (L-04, L-05, L-10 y L-11)**

- **Retención del registro:**
  - mínimo **4 años** sin borrado posible; la configuración no deja bajar de 48 meses,
  - después se suprime de forma automática con `app:prune-data`; el plazo exacto lo fija la asesoría (F-5),
  - **nunca** se borra al desactivar a una persona.
- **Exportación RGPD:** una sección nueva, «registro de jornada».
- **Texto informativo:** sección del registro de jornada (finalidad, base legal, conservación, accesos, sin geolocalización ni biometría), pendiente de la asesoría.
- **Documentos alojados en la app con lectura registrada**, como el texto RGPD (D-125):
  - el **documento de implantación del sistema** (L-04),
  - la **política de desconexión digital** (L-10).

**E4. Vacaciones, permisos y calendario (L-17 a L-23)**

- **Catálogo de tipos de ausencia** que sustituye al enum fijo y conserva los cinco actuales como categorías. Cada tipo lleva:
  - la base (ET o convenio),
  - la unidad: días naturales, días laborables u horas,
  - la duración por defecto y la ampliación por desplazamiento,
  - si es retribuido,
  - si pide justificante,
  - el preaviso,
  - si cuenta como dato de salud.
  - Se precargan los de L-20 a L-22.
- **Saldos por persona y año:**
  - vacaciones con devengo proporcional (altas y bajas a mitad de año),
  - arrastre por IT (18 meses) o por nacimiento,
  - contador de **horas de fuerza mayor** (4 días al año),
  - permiso parental (8 semanas por hijo),
  - acompañamiento médico (16 h al año, según el convenio).
- **Calendario de vacaciones anual:**
  - planificación y aprobación,
  - aviso si quedan menos de **2 meses** para una vacación sin aprobar,
  - fecha de comunicación guardada como prueba de la antelación.
- **Justificantes adjuntos** con acceso restringido (D-088) y retención propia.
- **Calendario laboral publicable** (L-23):
  - una página o PDF «Calendario laboral AAAA» visible para toda la plantilla,
  - festivos importados del **decreto autonómico** y de los **locales de Valencia**,
  - la importación «nacional» automática pasa a ser opcional,
  - 25 de enero (o el viernes siguiente), 24 y 31 de diciembre.

**E5. Migración y baja de Woffu**

- **Exportar de Woffu antes de darlo de baja** todos los registros de jornada, las correcciones y las ausencias de los **últimos 4 años**, en PDF y CSV, y archivarlos con su huella en la app como «histórico Woffu» de solo lectura. **Es obligatorio por L-05: el plazo sigue corriendo aunque se cambie de herramienta.**
- Cargar los saldos iniciales de vacaciones y de horas extra pendientes de compensar.
- **Un mes entero en paralelo** (B-9) y comprobar que los totales coinciden.
- Comunicar el cambio a la plantilla: documento de implantación y texto informativo actualizados.

**Fuera de alcance** (no son obligatorios): nóminas y firma digital, turnos, canal de denuncias, geolocalización, biometría, integración con A3 (basta un CSV para la gestoría) y facturación.

**Orden recomendado:**

1. E1, E2 y E3 juntas, porque sin ellas no se puede dar de baja Woffu.
2. Después, E4.
3. Al final, E5.
4. Con Woffu activo hasta acabar E5.

---

## F. Dudas para la asesoría laboral

1. **Convenio aplicable:** ¿el estatal de publicidad o el XIX de consultoría y TI? Según el que sea:
   - ¿son 22 días laborables de vacaciones?,
   - en cada permiso donde el convenio de 2016 y el ET (RDL 5/2023) difieren, como hospitalización (4 frente a 5 días) o fallecimiento (4 frente a 2), ¿se aplica lo más favorable concepto a concepto?
2. **Implantación:** ¿hay representación legal en Audax? Si no la hay, ¿basta con una decisión de la empresa documentada y comunicada a la plantilla para cambiar de Woffu al nuevo sistema? ¿Hay que informar de algo más?
3. **Horas extra y flexibilidad:**
   - ¿cómo se trata el exceso diario dentro de la flexibilidad de ±30 minutos del convenio?,
   - ¿la pausa de comida computa como trabajo?,
   - ¿totalizamos las horas extra por semana (convenio) y además por mes (ET)?,
   - ¿compensamos con descanso (80 minutos por hora extra) por defecto?
4. **Resumen mensual:** ¿es exigible entregarlo y firmarlo para toda la plantilla o solo para el tiempo parcial y las horas extra? ¿Vale un acuse electrónico dentro de la app, sin firma cualificada?
5. **RGPD:**
   - ¿qué plazo de supresión fijamos tras los 4 años?,
   - ¿podemos enseñar a cada persona, o a su responsable, la comparación entre la jornada registrada y las horas imputadas a proyectos, o es otra finalidad?,
   - ¿cómo deben tratarse los justificantes médicos?
6. **Histórico de Woffu y RD digital:**
   - ¿basta con conservar las exportaciones en PDF y CSV de Woffu durante 4 años como registro válido?,
   - si se publica el RD con el texto del borrador, ¿hay algo del diseño propuesto (E1 a E3) que no cumpliría?

---

### Fuentes principales

**Normas**

- Estatuto de los Trabajadores, texto consolidado actualizado el 03/10/2026: https://www.boe.es/buscar/act.php?id=BOE-A-2015-11430
- LISOS: https://www.boe.es/buscar/act.php?id=BOE-A-2000-15060
- Ley 10/2021: https://www.boe.es/buscar/act.php?id=BOE-A-2021-11472
- LOPDGDD: https://www.boe.es/buscar/act.php?id=BOE-A-2018-16673
- Ley 4/2026 (03/10/2026): https://www.boe.es/buscar/doc.php?id=BOE-A-2026-20528

**Convenios**

- Convenio de publicidad: https://www.boe.es/diario_boe/txt.php?id=BOE-A-2016-1290
- Revisión de 2022: https://www.boe.es/diario_boe/txt.php?id=BOE-A-2022-13573
- Tablas de 2026: https://www.boe.es/diario_boe/txt.php?id=BOE-A-2026-9501
- XIX Convenio de consultoría: https://www.boe.es/diario_boe/txt.php?id=BOE-A-2025-7766

**Facturación**

- RD 238/2026: https://www.boe.es/diario_boe/txt.php?id=BOE-A-2026-7295
- Orden HAC/1028/2026: https://www.boe.es/buscar/doc.php?id=BOE-A-2026-20587
- AEAT, VeriFactu: https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/nota-informativa-ampliacion-plazo-adaptacion-facturacion.html

**AEPD**

- Blog del Laboratorio (17/02/2026): https://laboratorio.aepd.es/blog/el-registro-de-jornada-laboral-y-la-proteccion-de-datos
- Guía de biometría (23/11/2023): https://www.aepd.es/guias/guia-control-presencia-biometrico.pdf

**Prensa especializada y blogs** (citados en cada fila; menos fiables que el BOE, sobre todo los blogs de proveedores de fichaje): eldiario.es, idealista, The Objective, Economist & Jurist, Qworker, jornalo, esisoluciones y Kronjop.
